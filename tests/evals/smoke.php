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

$ignoreAuth = 1;
$_GET['site'] = 'default';
$sessionAllowWrite = true;
require_once __DIR__ . '/../../interface/globals.php';

use OpenEMR\Common\Database\QueryUtils;
use Symfony\Component\Panther\Client;

$base = $argv[1] ?? 'http://openemr';
$count = (int) ($argv[2] ?? 10);
$module = '/interface/modules/custom_modules/oe-module-clinical-copilot/public';
$failures = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-52s %s %s\n", $name, $ok ? 'OK  ' : 'FAIL', $detail);
}

foreach (['health.php' => 200, 'ready.php' => 200] as $file => $want) {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body = (string) file_get_contents("$base$module/$file", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    check($file, $status === $want, "HTTP $status " . substr($body, 0, 80));
}

$pids = array_map(fn(array $r) => (int) $r['pid'], QueryUtils::fetchRecords("SELECT pid FROM form_encounter GROUP BY pid ORDER BY COUNT(*) DESC LIMIT $count"));

function openPanel(Client $c, string $base, int $pid): array
{
    $enc = (int) (QueryUtils::querySingleRow("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid])['encounter'] ?? 0);
    $c->request('GET', "$base/interface/patient_file/summary/demographics.php?set_pid=$pid");
    sleep(1);
    if ($enc > 0) {
        $c->request('GET', "$base/interface/patient_file/encounter/encounter_top.php?set_encounter=$enc&pid=$pid");
        sleep(1);
        $c->request('GET', "$base/interface/patient_file/summary/demographics.php");
    }
    $c->waitFor('#copilot-panel', 20);
    $status = (string) $c->executeScript("return new Promise(r => { const t0=Date.now(); const i=setInterval(()=>{ const s=document.querySelector('#copilot-status'); if(s && !/loading/.test(s.textContent) || Date.now()-t0>40000){clearInterval(i); r(s?s.textContent:'no status');} },300); });");
    $facts = (int) $c->executeScript("return document.querySelectorAll('#copilot-facts li').length");
    $stripped = (string) $c->executeScript("const a=document.querySelector('#copilot-narration .copilot-alert'); return a?a.textContent:'';");
    $sentences = (int) $c->executeScript("return document.querySelectorAll('#copilot-narration p').length");
    return ['status' => $status, 'facts' => $facts, 'sentences' => $sentences, 'alert' => $stripped];
}

function login(string $base, string $user, string $pass): Client
{
    $c = Client::createSeleniumClient('http://selenium:4444/wd/hub', null, $base);
    $c->request('GET', "$base/interface/login/login.php?site=default");
    $c->waitFor('#authUser', 20);
    $c->executeScript("document.querySelector('#authUser').value=" . json_encode($user) . ";document.querySelector('#clearPass').value=" . json_encode($pass) . ";document.querySelector('#login-button, button[type=submit]').click();");
    sleep(4);
    return $c;
}

$admin = login($base, 'admin', 'pass');
foreach ($pids as $pid) {
    $r = openPanel($admin, $base, $pid);
    $ok = str_starts_with($r['status'], 'ref ') && $r['facts'] > 0;
    check("admin pid $pid briefing", $ok, sprintf('%s facts=%d sentences=%d %s', $r['status'], $r['facts'], $r['sentences'], $r['alert']));
}
$admin->quit();

$recep = login($base, 'receptionist', 'receptionist');
$r = openPanel($recep, $base, $pids[0]);
check("receptionist pid {$pids[0]} refused", str_contains($r['status'], 'not authorized') && $r['facts'] === 0, $r['status']);
$recep->quit();

printf("\n%s\n", $failures === 0 ? 'SMOKE OK' : "SMOKE FAILED ($failures)");
exit($failures === 0 ? 0 : 1);
