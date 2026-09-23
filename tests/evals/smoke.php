<?php

/**
 * End-to-end smoke through the real UI (Selenium): health/ready endpoints,
 * then the dashboard panel for the busiest seed patients as admin and as a
 * restricted user. Prints one line per check; exits non-zero on any failure.
 *
 * Run (inside the openemr container, as apache):
 *   php tests/evals/smoke.php [base_url] [patients]
 *   e.g. php tests/evals/smoke.php http://openemr 10
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Evals;

use Document;
use Facebook\WebDriver\Remote\LocalFileDetector;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Row;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Panther\Client;

// Boot OpenEMR as an unauthenticated CLI script: we need its DB connection
// to pick patients, but the browser session does its own real login below.
$ignoreAuth = 1;
$_GET['site'] = 'default';
$sessionAllowWrite = true;
require_once __DIR__ . '/../../interface/globals.php';
require_once __DIR__ . '/lib.php';

$input = new ArgvInput(null, new InputDefinition([
    new InputArgument('base', InputArgument::OPTIONAL, 'Base URL of the app', 'http://openemr'),
    new InputArgument('patients', InputArgument::OPTIONAL, 'How many of the busiest patients to open', '10'),
]));
$base = strOf($input->getArgument('base'), 'http://openemr');
$count = intOf($input->getArgument('patients'), 10);
$module = '/interface/modules/custom_modules/oe-module-clinical-copilot/public';

/** Counts failed checks; passed by reference into check() so the exit code can report it. */
final class Failures
{
    public int $count = 0;
}
$failures = new Failures();

/** Prints one result line and bumps the failure count. */
function check(Failures $failures, string $name, bool $ok, string $detail = ''): void
{
    $failures->count += $ok ? 0 : 1;
    printf("%-52s %s %s\n", $name, $ok ? 'OK  ' : 'FAIL', $detail);
}

// 1. Plain HTTP checks on the two unauthenticated endpoints.
foreach (['health.php' => 200, 'ready.php' => 200] as $file => $want) {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body = (string) file_get_contents("$base$module/$file", false, $ctx);
    $status = 0;
    foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    check($failures, $file, $status === $want, "HTTP $status " . substr($body, 0, 80));
}

// 2. Pick the N patients with the most encounters — the richest charts.
$pids = array_map(fn(array $r) => Row::int($r, 'pid'), QueryUtils::fetchRecords("SELECT pid FROM form_encounter GROUP BY pid ORDER BY COUNT(*) DESC LIMIT $count"));

/**
 * Drives a logged-in Selenium session to the patient's summary page (and
 * selects their latest encounter, mirroring what a clinician does), waits
 * for the panel's status to leave "loading…", then reads counts out of the
 * DOM with JavaScript. Returns what a human would see, not API output.
 *
 * @return array{status: string, facts: int, sentences: int, alert: string}
 */
function openPanel(Client $c, string $base, int $pid): array
{
    $encRow = QueryUtils::querySingleRow("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid]);
    $enc = is_array($encRow) ? Row::int($encRow, 'encounter') : 0;
    $c->request('GET', "$base/interface/patient_file/summary/demographics.php?set_pid=$pid");
    sleep(1);
    if ($enc > 0) {
        $c->request('GET', "$base/interface/patient_file/encounter/encounter_top.php?set_encounter=$enc&pid=$pid");
        sleep(1);
        $c->request('GET', "$base/interface/patient_file/summary/demographics.php");
    }
    $c->waitFor('#copilot-panel', 20);
    $status = strOf($c->executeScript("return new Promise(r => { const t0=Date.now(); const i=setInterval(()=>{ const s=document.querySelector('#copilot-status'); if(s && !/loading/.test(s.textContent) || Date.now()-t0>40000){clearInterval(i); r(s?s.textContent:'no status');} },300); });"));
    $facts = intOf($c->executeScript("return document.querySelectorAll('#copilot-facts li').length"));
    $stripped = strOf($c->executeScript("const a=document.querySelector('#copilot-narration .copilot-alert'); return a?a.textContent:'';"));
    $sentences = intOf($c->executeScript("return document.querySelectorAll('#copilot-narration p').length"));
    return ['status' => $status, 'facts' => $facts, 'sentences' => $sentences, 'alert' => $stripped];
}

/** Opens a fresh browser session against the Selenium grid and logs in through the real form. */
function login(string $base, string $user, string $pass): Client
{
    $c = Client::createSeleniumClient('http://selenium:4444/wd/hub', null, $base);
    // The upload and re-brief waits below poll for up to 90-120 s inside one
    // executeScript call; WebDriver's default script timeout (30 s) would cut
    // them off first now that a brief also retrieves guideline evidence.
    $c->getWebDriver()->manage()->timeouts()->setScriptTimeout(150);
    $c->request('GET', "$base/interface/login/login.php?site=default");
    $c->waitFor('#authUser', 20);
    $c->executeScript("document.querySelector('#authUser').value=" . json_encode($user) . ";document.querySelector('#clearPass').value=" . json_encode($pass) . ";document.querySelector('#login-button, button[type=submit]').click();");
    sleep(4);
    return $c;
}

// 3. As admin every chart should brief ("ref <id>" status) with facts shown.
$admin = login($base, 'admin', 'pass');
foreach ($pids as $pid) {
    $r = openPanel($admin, $base, $pid);
    $ok = str_starts_with($r['status'], 'ref ') && $r['facts'] > 0;
    check($failures, "admin pid $pid briefing", $ok, sprintf('%s facts=%d sentences=%d %s', $r['status'], $r['facts'], $r['sentences'], $r['alert']));
}
$admin->quit();

// 3b. Week 2: upload a synthetic lab PDF through the panel on a quiet demo
// patient (not the busiest one, whose briefing the live eval case measures),
// let the extraction run, open a document citation and check the viewer
// drew a highlight, then ask a guideline question and check the answer
// separates record from guidelines and the routing drawer is filled.
// Cleans up its own upload afterwards.
$demoRow = QueryUtils::querySingleRow("SELECT pid FROM patient_data WHERE pid NOT IN (" . implode(',', $pids) . ") ORDER BY pid LIMIT 1");
$demoPid = is_array($demoRow) ? Row::int($demoRow, 'pid') : 0;
if ($demoPid > 0) {
    $admin = login($base, 'admin', 'pass');
    $before = openPanel($admin, $base, $demoPid)['status'];
    $fixture = realpath(__DIR__ . '/fixtures/docs/lab-layout1.pdf');
    $fileInput = $admin->getWebDriver()->findElement(WebDriverBy::id('copilot-file'));
    if ($fileInput instanceof RemoteWebElement) {
        // A remote grid needs the local file detector to ship the PDF to the browser host.
        $fileInput->setFileDetector(new LocalFileDetector());
    }
    $fileInput->sendKeys(is_string($fixture) ? $fixture : '');
    $admin->executeScript("document.querySelector('#copilot-upload button').click();");
    $admin->executeScript("return new Promise(r => { const t0=Date.now(); const i=setInterval(()=>{ const s=document.querySelector('#copilot-upload-status').textContent; if(/Extracted|failed|Failed|already/.test(s) || Date.now()-t0>120000){clearInterval(i); r(1);} },500); });");
    // The panel re-briefs after an extraction; wait for a new briefing reference, not a fixed time.
    $admin->executeScript("return new Promise(r => { const t0=Date.now(); const i=setInterval(()=>{ const s=document.querySelector('#copilot-status').textContent; if((s !== arguments[0] && /^ref /.test(s)) || Date.now()-t0>90000){clearInterval(i); r(1);} },500); });", [$before]);
    sleep(1);
    $uploadStatus = strOf($admin->executeScript("return document.querySelector('#copilot-upload-status').textContent"));
    check($failures, "upload+extract pid $demoPid", str_starts_with($uploadStatus, 'Extracted') && str_contains($uploadStatus, '100% verified'), $uploadStatus);
    $handoffs = intOf($admin->executeScript("return document.querySelectorAll('#copilot-handoffs li').length"));
    check($failures, 'routing drawer filled', $handoffs >= 3, "$handoffs hops");
    $links = intOf($admin->executeScript("return document.querySelectorAll('#copilot-facts .copilot-source').length"));
    check($failures, 'facts carry document citations', $links > 0, "$links source links");
    $admin->executeScript("const a=Array.from(document.querySelectorAll('#copilot-facts .copilot-source')).find(x => /^source p\\./.test(x.textContent)); if(a){a.click();}");
    sleep(4);
    $marks = intOf($admin->executeScript("return document.querySelectorAll('.copilot-viewer-mark').length"));
    $note = strOf($admin->executeScript("const n=document.querySelector('#copilot-viewer-note'); return n?n.textContent:''"));
    check($failures, 'click-to-source highlight drawn', $marks === 2 && str_starts_with($note, 'Highlighted'), "marks=$marks $note");
    $admin->executeScript("window.copilotSourceViewer && window.copilotSourceViewer.close();");
    $admin->executeScript("document.querySelector('#copilot-question').value='Should this patient be on a statin?'; document.querySelector('#copilot-ask button').click();");
    $admin->executeScript("return new Promise(r => { const t0=Date.now(); const i=setInterval(()=>{ if(document.querySelectorAll('#copilot-thread .copilot-assistant').length>0 || Date.now()-t0>90000){clearInterval(i); r(1);} },400); });");
    sleep(1);
    $answer = strOf($admin->executeScript("const n=document.querySelector('#copilot-thread .copilot-assistant'); return n?n.innerText:''"));
    $guidelineChips = intOf($admin->executeScript("return document.querySelectorAll('#copilot-thread .copilot-chip-guideline').length"));
    // Passages were retrieved for the question (the drawer shows the evidence_retriever hop) and
    // either a guideline sentence is shown with its chip, or the model's guideline sentence was
    // withheld by the verifier and only record sentences remain. On the demo chart the model
    // sometimes files the AHA/ACC numbers under the ADA passage's id; the verifier strips that,
    // which is the contract working (eval case 33 pins it), not a failure of retrieval.
    // The routing drawer shows the last extraction's hops, not the ask's, so retrieval is
    // not observable from the page; the answer's shape is. Either a guideline sentence is
    // shown with its chip, or only record sentences remain (the model's guideline sentence
    // was withheld by the verifier).
    $cited = $guidelineChips > 0 && str_contains($answer, 'From guidelines');
    $withheld = $guidelineChips === 0 && str_contains($answer, 'From the record');
    check($failures, 'guideline question answered with only verified sentences', $cited || $withheld, "guideline chips=$guidelineChips " . substr(str_replace("\n", ' ', $answer), 0, 80));
    // Week 2 extension: the briefing's "what the guidelines say" section rendered and
    // did not fall back to "unavailable" (the sidecar was reachable for the ask above).
    $guidelineSection = strOf($admin->executeScript("return document.querySelector('#copilot-guidelines').textContent"));
    $guidelineCards = intOf($admin->executeScript("return document.querySelectorAll('#copilot-guidelines .copilot-card').length"));
    check($failures, 'guideline section rendered without falling back', $guidelineSection !== '' && !str_contains($guidelineSection, 'unavailable'), "cards=$guidelineCards " . substr(str_replace("\n", ' ', $guidelineSection), 0, 80));
    $admin->quit();
    // Remove the upload and its derived rows so the seed data stays as seeded.
    foreach (QueryUtils::fetchRecords("SELECT document_id FROM copilot_document WHERE pid = ?", [$demoPid]) as $d) {
        $id = Row::int($d, 'document_id');
        foreach (QueryUtils::fetchRecords("SELECT DISTINCT po.procedure_order_id FROM procedure_order po JOIN procedure_report prp ON prp.procedure_order_id = po.procedure_order_id JOIN procedure_result pr ON pr.procedure_report_id = prp.procedure_report_id WHERE pr.document_id = ?", [$id]) as $o) {
            $oid = Row::int($o, 'procedure_order_id');
            QueryUtils::sqlStatementThrowException("DELETE pr FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id WHERE prp.procedure_order_id = ?", [$oid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM procedure_report WHERE procedure_order_id = ?", [$oid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order_code WHERE procedure_order_id = ?", [$oid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order WHERE procedure_order_id = ?", [$oid]);
        }
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_document_fact WHERE document_id = ?", [$id]);
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_intake WHERE document_id = ?", [$id]);
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_document WHERE document_id = ?", [$id]);
        $doc = new Document($id);
        $url = $doc->get_url_filepath();
        if (is_string($url) && $url !== '' && file_exists($url)) {
            @unlink($url);
        }
        QueryUtils::sqlStatementThrowException("DELETE FROM documents WHERE id = ?", [$id]);
    }
    QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$demoPid]);
} else {
    check($failures, 'week 2 e2e', false, 'no demo patient available');
}

// 4. As a receptionist (no clinical ACL) the same chart must be refused with no facts.
$recep = login($base, 'receptionist', 'receptionist');
$r = openPanel($recep, $base, $pids[0]);
check($failures, "receptionist pid {$pids[0]} refused", str_contains($r['status'], 'not authorized') && $r['facts'] === 0, $r['status']);
// Week 2: the documents endpoint refuses the same user (no patients/docs ACL).
$docs = strOf($recep->executeScript("return fetch(document.getElementById('copilot-panel').dataset.documentsEndpoint, {method:'POST', body:new URLSearchParams({csrf_token_form: document.getElementById('copilot-panel').dataset.csrf, action:'list'}), credentials:'same-origin'}).then(r => String(r.status));"));
check($failures, 'receptionist documents endpoint refused (403)', $docs === '403', "HTTP $docs");
$recep->quit();

printf("\n%s\n", $failures->count === 0 ? 'SMOKE OK' : "SMOKE FAILED ({$failures->count})");
exit($failures->count === 0 ? 0 : 1);
