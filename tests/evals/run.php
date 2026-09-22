<?php

/**
 * Clinical Co-Pilot eval harness.
 *
 * Recorded cases (default) replay a narration fixture through the Verifier
 * and OmissionGuard: deterministic, no network, no database. Live cases
 * (--live) assemble real facts from the seed database and call OpenAI.
 * Week 2 modes reach the sidecar (anchor, absent, malformed, route,
 * retrieve; the container must be up), the database (facts) or the real
 * controllers (extract, phi_logs; live). Which mode reaches which layer:
 * clinical_copilot_week2/ENGINEERING_REQUIREMENTS.md section 1.
 * Results are written to tests/evals/results.json.
 *
 * Usage (inside the openemr container, as apache):
 *   php tests/evals/run.php            # recorded cases only
 *   php tests/evals/run.php --live     # recorded + live cases
 *
 * Every case may declare boolean "rubrics" (schema_valid, citation_present,
 * factually_consistent, safe_refusal, no_phi_in_logs, routing_correct,
 * anchor_correct). Each is evaluated to pass / fail / na per case and written
 * to results.json; tests/evals/gate.php turns those into the push gate. A
 * case with "pending": true is skipped until its implementation lands.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$live = in_array('--live', $argv, true);
$root = dirname(__DIR__, 2);

// Cases in "facts" mode persist a recorded extraction and assemble facts from
// the database (no model), so they need the OpenEMR runtime even when not --live.
$needsDb = $live;
foreach (glob(__DIR__ . '/cases/*.json') ?: [] as $p) {
    $c = json_decode((string) file_get_contents($p), true);
    if (is_array($c) && ($c['mode'] ?? '') === 'facts' && ($c['pending'] ?? false) !== true) {
        $needsDb = true;
    }
}

// Two bootstraps. Live mode needs the full OpenEMR runtime (database, site
// config) so it loads globals.php as an authenticated CLI script; recorded
// mode only needs Composer's autoloader.
if ($needsDb) {
    $ignoreAuth = 1;
    $_GET['site'] = 'default';
    $sessionAllowWrite = true;
    require_once $root . '/interface/globals.php';
} else {
    require_once $root . '/vendor/autoload.php';
}

use Composer\Autoload\ClassLoader;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\ModelOutput;
use OpenEMR\Modules\ClinicalCopilot\Narration;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Modules\ClinicalCopilot\Verifier;

// The module is not in composer.json's autoload map (see Support/ModuleAutoload.php).
$loaders = ClassLoader::getRegisteredLoaders();
reset($loaders)->addPsr4('OpenEMR\\Modules\\ClinicalCopilot\\', $root . '/interface/modules/custom_modules/oe-module-clinical-copilot/src/');

/** @return array<string, mixed> */
function loadCase(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("Bad case file $path");
    }
    /** @var array<string, mixed> $data */
    return $data;
}

/** Builds a FactSet from the "facts" array in a recorded case file. @param list<array<string, mixed>> $rows */
function factsFrom(array $rows): FactSet
{
    $facts = [];
    foreach ($rows as $r) {
        $facts[] = new Fact(
            (string) $r['id'],
            (string) $r['service'],
            (int) $r['record_id'],
            (string) $r['field'],
            (string) $r['value'],
            FactCategory::from((string) $r['category']),
        );
    }
    return new FactSet($facts);
}

/**
 * Builds a Narration from the "narration" fixture in a recorded case file.
 * Delegates to the production parser (ModelOutput), so a case exercises the
 * same inline-citation recovery and scrubbing the panel gets, not a copy.
 *
 * @param array<string, mixed> $data
 */
function narrationFrom(array $data): Narration
{
    return ModelOutput::narration($data);
}

/** Rubric names the gate understands; a case lists the ones that apply to it. */
const RUBRICS = ['schema_valid', 'citation_present', 'factually_consistent', 'safe_refusal', 'no_phi_in_logs', 'routing_correct', 'anchor_correct'];

/** Modes whose runner has not landed yet; a non-pending case in one of these fails every rubric it declares. */
const UNIMPLEMENTED_MODES = [];

/** Sidecar test endpoints (COPILOT_EVAL_ENDPOINTS=1 on the dev compose service). */
function sidecarUrl(): string
{
    $v = getenv('COPILOT_SIDECAR_URL');
    return is_string($v) && $v !== '' ? rtrim($v, '/') : 'http://copilot-sidecar:8000';
}

/**
 * Runs an anchor-mode (recorded proposal, no model) or extract-mode (real
 * parser and model) case through the sidecar and scores it against the
 * fixture's truth.json. Returns one run in the shape compare() and
 * evaluateRubrics() understand.
 *
 * anchor_correct: every true result is anchored on its true page, and no
 * result is anchored to a value that is not that analyte's printed result.
 * The optional "swapped" list is re-anchored and must come back unverified.
 *
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function runDocumentCase(array $case, string $mode): array
{
    $fixturesDir = __DIR__ . '/fixtures/docs/';
    $truth = json_decode((string) file_get_contents($fixturesDir . (string) $case['truth']), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($truth)) {
        throw new RuntimeException('bad truth file');
    }
    $body = ['fixture' => (string) $case['fixture'], 'doc_type' => (string) $case['doc_type'], 'document_id' => 1];
    if ($mode === 'anchor') {
        $body['proposal'] = json_decode((string) file_get_contents($fixturesDir . (string) $case['model_output']), true, 32, JSON_THROW_ON_ERROR);
    } else {
        $body['proposal'] = new stdClass(); // extract mode: the sidecar calls the model itself
    }
    $t = hrtime(true);
    $response = sidecarPost('/eval/' . $mode, $body);
    $ms = (int) round((hrtime(true) - $t) / 1e6);
    $extraction = $mode === 'anchor' ? $response : ($response['extraction'] ?? null);
    if (!is_array($extraction)) {
        throw new RuntimeException('sidecar returned no extraction');
    }
    $run = ['status' => $extraction['status'] ?? null, 'ms' => $ms, 'schema_errors' => [], 'anchor_errors' => [], 'ungrounded_tokens' => [], 'uncited_kept' => 0, 'leaked_identifiers' => []];
    $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
    $run['tokens'] = array_sum(array_map(fn($u) => (int) ($u['input'] ?? 0) + (int) ($u['output'] ?? 0), $usage));
    $run['model_calls'] = count($usage);
    $run['reason'] = $extraction['failure_reason'] ?? null;
    if (($extraction['status'] ?? null) !== 'extracted' || !is_array($extraction['extraction'] ?? null)) {
        if (($case['expect']['status'] ?? null) === 'failed') {
            // The case expects a refusal (missing required data): score it as one.
            $run['answer_type'] = 'refused';
            return $run;
        }
        $run['anchor_errors'][] = 'extraction failed: ' . json_encode($extraction['failure_reason'] ?? null);
        return $run;
    }
    $doc = $extraction['extraction'];
    $run['schema_errors'] = schemaErrors((string) $case['doc_type'] === 'lab_pdf' ? 'lab-report' : 'intake-form', json_decode(json_encode($doc, JSON_THROW_ON_ERROR)));
    if ((string) $case['doc_type'] === 'intake_form') {
        return scoreIntake($run, $doc, $truth);
    }
    $run['unextracted'] = count(is_array($doc['unextracted'] ?? null) ? $doc['unextracted'] : []);
    $results = is_array($doc['results'] ?? null) ? $doc['results'] : [];
    $run['results'] = count($results);
    $run['anchored'] = count(array_filter($results, fn($r) => (($r['citation']['anchored'] ?? false) === true)));
    $run['uncited_kept'] = count(array_filter($results, fn($r) => !is_array($r['citation'] ?? null)));

    // anchor_correct against truth: match each true result by analyte (OCR may
    // mangle a name; fall back to value+unit within the same page).
    $byAnalyte = [];
    foreach ($results as $r) {
        $byAnalyte[normName((string) ($r['analyte'] ?? ''))][] = $r;
    }
    foreach (is_array($truth['results'] ?? null) ? $truth['results'] : [] as $want) {
        $cands = $byAnalyte[normName((string) $want['analyte'])] ?? [];
        if ($cands === []) {
            $cands = array_values(array_filter($results, fn($r) => normNum((string) ($r['value'] ?? '')) === normNum((string) $want['value']) && (($r['citation']['bbox']['page'] ?? null) === $want['page'])));
        }
        if ($cands === []) {
            $run['anchor_errors'][] = sprintf('%s missing', $want['analyte']);
            continue;
        }
        $got = $cands[0];
        if (normNum((string) ($got['value'] ?? '')) !== normNum((string) $want['value'])) {
            $run['ungrounded_tokens'][] = sprintf('%s=%s (truth %s)', $want['analyte'], $got['value'] ?? '', $want['value']);
        }
        if (($got['citation']['anchored'] ?? false) !== true) {
            $run['anchor_errors'][] = sprintf('%s not anchored', $want['analyte']);
        } elseif (($got['citation']['bbox']['page'] ?? null) !== $want['page']) {
            $run['anchor_errors'][] = sprintf('%s anchored on page %s, truth page %s', $want['analyte'], json_encode($got['citation']['bbox']['page'] ?? null), $want['page']);
        }
    }
    if (($doc['collection_date'] ?? null) !== ($truth['collection_date'] ?? null)) {
        $run['ungrounded_tokens'][] = sprintf('collection_date=%s (truth %s)', json_encode($doc['collection_date'] ?? null), json_encode($truth['collection_date'] ?? null));
    }
    if (($doc['collection_date_citation']['anchored'] ?? false) !== true) {
        $run['anchor_errors'][] = 'collection date not anchored';
    }
    // Swapped proposals must come back unverified (the Codex "100 in three columns" rule).
    foreach (is_array($case['swapped'] ?? null) ? $case['swapped'] : [] as $swap) {
        $proposal = ['patient_name_on_report' => null, 'collection_date' => $truth['collection_date'] ?? null, 'reported_date' => null, 'lab_name' => null,
            'results' => [['analyte' => $swap['analyte'], 'value' => $swap['value'], 'unit' => $swap['unit'] ?? 'mg/dL', 'reference_range' => null, 'abnormal_flag' => null, 'page' => 1]]];
        $sw = sidecarPost('/eval/anchor', ['fixture' => (string) $case['fixture'], 'doc_type' => 'lab_pdf', 'document_id' => 1, 'proposal' => $proposal]);
        $anchored = $sw['extraction']['results'][0]['citation']['anchored'] ?? null;
        if ($anchored !== false) {
            $run['anchor_errors'][] = sprintf('swapped %s=%s was anchored (must be unverified)', $swap['analyte'], $swap['value']);
        }
    }
    return $run;
}

/**
 * Intake-form scoring against truth.json: every true medication, allergy and
 * family-history line must be present (matched by name/substance/condition)
 * and anchored; the chief concern must be present and anchored; demographics
 * on the form are compared but never persisted, so only their anchoring is
 * scored. Extra items the model invented count against factually_consistent.
 *
 * @param array<string, mixed> $run
 * @param array<string, mixed> $doc
 * @param array<string, mixed> $truth
 * @return array<string, mixed>
 */
function scoreIntake(array $run, array $doc, array $truth): array
{
    $lists = [['medications', 'name', 'name'], ['allergies', 'substance', 'substance'], ['family_history', 'condition', 'condition']];
    $cited = 0;
    $anchored = 0;
    foreach ($lists as [$key, $field, $truthField]) {
        $got = is_array($doc[$key] ?? null) ? $doc[$key] : [];
        $want = is_array($truth[$key] ?? null) ? $truth[$key] : [];
        foreach ($got as $g) {
            $cited++;
            $anchored += (($g['citation']['anchored'] ?? false) === true) ? 1 : 0;
            if (!is_array($g['citation'] ?? null)) {
                $run['uncited_kept']++;
            }
        }
        foreach ($want as $w) {
            $match = array_values(array_filter($got, fn($g) => normName((string) ($g[$field] ?? '')) === normName((string) $w[$truthField])));
            if ($match === []) {
                $run['anchor_errors'][] = sprintf('%s "%s" missing', $key, $w[$truthField]);
            } elseif (($match[0]['citation']['anchored'] ?? false) !== true) {
                $run['anchor_errors'][] = sprintf('%s "%s" not anchored', $key, $w[$truthField]);
            }
        }
        if (count($got) > count($want)) {
            $run['ungrounded_tokens'][] = sprintf('%s: %d listed, truth has %d', $key, count($got), count($want));
        }
    }
    $cc = $doc['chief_concern'] ?? null;
    if (($truth['chief_concern'] ?? null) === null && is_array($cc)) {
        $run['ungrounded_tokens'][] = 'chief concern extracted from a form that has none';
    }
    if (($truth['chief_concern'] ?? null) !== null) {
        if (!is_array($cc)) {
            $run['anchor_errors'][] = 'chief concern missing';
        } elseif (($cc['citation']['anchored'] ?? false) !== true) {
            $run['anchor_errors'][] = 'chief concern not anchored';
        } elseif (normName((string) ($cc['value'] ?? '')) !== normName((string) $truth['chief_concern'])) {
            $run['ungrounded_tokens'][] = 'chief concern text differs from truth';
        }
    }
    foreach (['name', 'dob', 'sex', 'phone'] as $d) {
        $node = $doc['demographics'][$d] ?? null;
        if (is_array($node) && ($node['citation']['anchored'] ?? false) !== true) {
            $run['anchor_errors'][] = "demographics $d not anchored";
        }
    }
    if (($doc['form_date'] ?? null) !== ($truth['form_date'] ?? null)) {
        $run['ungrounded_tokens'][] = sprintf('form_date=%s (truth %s)', json_encode($doc['form_date'] ?? null), json_encode($truth['form_date'] ?? null));
    }
    $run['results'] = $cited;
    $run['anchored'] = $anchored;
    $run['unextracted'] = 0;
    return $run;
}

/**
 * Malformed-input case: a document the ingestion path must refuse. The parse
 * step fails before any model call, so this is deterministic. expect.reason is
 * the failure_reason code the sidecar must return (unreadable, encrypted,
 * too_many_pages); anything else, including a "successful" extraction, fails.
 *
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function runMalformedCase(array $case): array
{
    $t = hrtime(true);
    $response = sidecarPost('/eval/extract', ['fixture' => (string) $case['fixture'], 'doc_type' => (string) $case['doc_type'], 'document_id' => 1, 'proposal' => new stdClass()]);
    $extraction = is_array($response['extraction'] ?? null) ? $response['extraction'] : [];
    return [
        'ms' => (int) round((hrtime(true) - $t) / 1e6),
        'status' => $extraction['status'] ?? null,
        'reason' => $extraction['failure_reason'] ?? null,
        'model_calls' => count(is_array($response['usage'] ?? null) ? $response['usage'] : []),
        'schema_errors' => [],
        'ungrounded_tokens' => [],
        'uncited_kept' => 0,
        'anchor_errors' => [],
    ];
}

/**
 * Absent-item case: a proposal whose values the document does not contain.
 * Every item must come back unverified; one anchored item is a failure,
 * because it means the anchor step accepted an invented value.
 *
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function runAbsentCase(array $case): array
{
    $t = hrtime(true);
    $response = sidecarPost('/eval/anchor-absent', ['fixture' => (string) $case['fixture'], 'doc_type' => (string) $case['doc_type'], 'document_id' => 1, 'proposal' => is_array($case['proposal'] ?? null) ? $case['proposal'] : []]);
    $anchored = is_array($response['anchored'] ?? null) ? $response['anchored'] : [];
    return [
        'ms' => (int) round((hrtime(true) - $t) / 1e6),
        'status' => $response['status'] ?? null,
        'anchored_absent' => count($anchored),
        'anchor_errors' => array_map(fn($a) => sprintf('"%s" was anchored but is not in the document', (string) $a), $anchored),
        'schema_errors' => [],
        'ungrounded_tokens' => [],
        'uncited_kept' => 0,
    ];
}

/**
 * Route-mode case: the real graph with stubbed workers (sidecar /eval/route).
 * routing_correct compares the [from, to, reason] sequence with expect.handoffs;
 * schema_valid validates every handoff against contracts/handoff.schema.json.
 *
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function runRouteCase(array $case): array
{
    $state = is_array($case['state'] ?? null) ? $case['state'] : [];
    $t = hrtime(true);
    $response = sidecarPost('/eval/route', ['mode' => (string) ($state['mode'] ?? 'extract'), 'question' => $state['question'] ?? null, 'documents' => is_array($state['documents'] ?? null) ? array_values($state['documents']) : []]);
    $handoffs = is_array($response['handoffs'] ?? null) ? $response['handoffs'] : [];
    $schemaErrors = [];
    foreach ($handoffs as $h) {
        $schemaErrors = [...$schemaErrors, ...schemaErrors('handoff', json_decode(json_encode($h, JSON_THROW_ON_ERROR)))];
    }
    return [
        'ms' => (int) round((hrtime(true) - $t) / 1e6),
        'handoffs' => array_map(fn(array $h) => [(string) $h['from'], (string) $h['to'], (string) $h['reason']], $handoffs),
        'schema_errors' => $schemaErrors,
        'ungrounded_tokens' => [],
        'extractions' => $response['extractions'] ?? null,
    ];
}

/**
 * Retrieve-mode case: the committed query embedding (tests/evals/fixtures/queries)
 * through the sidecar's hybrid retriever, offline. schema_valid checks every
 * chunk against run.response's chunk shape; safe_refusal for an off-corpus
 * query means zero chunks (answer_type not_in_corpus); factually_consistent
 * means the expected source id is on top and at least min_chunks came back.
 *
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function runRetrieveCase(array $case): array
{
    $q = json_decode((string) file_get_contents(__DIR__ . '/fixtures/queries/' . (string) $case['query_fixture'] . '.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($q)) {
        throw new RuntimeException('bad query fixture');
    }
    $t = hrtime(true);
    $response = sidecarPost('/eval/retrieve', ['query' => (string) $q['query'], 'embedding' => $q['embedding']]);
    $chunks = is_array($response['chunks'] ?? null) ? $response['chunks'] : [];
    $schemaErrors = [];
    $chunkSchema = Contracts::schema('run.response')->properties->chunks->items;
    foreach ($chunks as $c) {
        $v = new Validator();
        $v->validate(json_decode(json_encode($c, JSON_THROW_ON_ERROR)), $chunkSchema, Constraint::CHECK_MODE_NORMAL);
        foreach ($v->getErrors() as $e) {
            $schemaErrors[] = sprintf('%s: %s', (string) ($e['property'] ?? ''), (string) ($e['message'] ?? ''));
        }
    }
    $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
    $run = [
        'ms' => (int) round((hrtime(true) - $t) / 1e6),
        'chunks' => count($chunks),
        'top_source_id' => $chunks[0]['source_id'] ?? null,
        'reranked' => ($response['reranked'] ?? false) === true,
        'schema_errors' => $schemaErrors,
        'uncited_kept' => count(array_filter($chunks, fn($c) => empty($c['source_id']) || empty($c['quote']) || empty($c['chunk_id']))),
        'ungrounded_tokens' => [],
        'answer_type' => $chunks === [] ? 'not_in_corpus' : 'cited',
    ];
    if (isset($expect['min_chunks']) && count($chunks) < (int) $expect['min_chunks']) {
        $run['ungrounded_tokens'][] = sprintf('only %d chunks', count($chunks));
    }
    return $run;
}

/**
 * Facts-mode case (database, no model): anchor a recorded proposal through
 * the sidecar (deterministic), persist it for a temporary patient with no
 * encounters through DocumentIngestService, assemble facts through the real
 * FactAssembler and chart source, score the categories and citations, then
 * remove everything. This is the layer between "the sidecar returned JSON"
 * and "the physician sees a cited fact", which no other mode reaches.
 *
 * expect.categories: {category: minimum count}; expect.cited: categories whose
 * facts must all carry an anchored document citation; expect.absent: categories
 * that must not appear.
 *
 * @param array<string, mixed> $case
 * @return array<string, mixed>
 */
function runFactsCase(array $case): array
{
    $fixturesDir = __DIR__ . '/fixtures/docs/';
    $proposal = isset($case['proposal']) && is_array($case['proposal']) ? $case['proposal']
        : json_decode((string) file_get_contents($fixturesDir . (string) $case['model_output']), true, 32, JSON_THROW_ON_ERROR);
    $t = hrtime(true);
    $extraction = sidecarPost('/eval/anchor', ['fixture' => (string) $case['fixture'], 'doc_type' => (string) $case['doc_type'], 'document_id' => 1, 'proposal' => $proposal]);

    $maxPid = (int) \OpenEMR\Common\Database\QueryUtils::fetchSingleValue("SELECT MAX(pid) AS m FROM patient_data", 'm');
    $pid = $maxPid + 1;
    \OpenEMR\Common\Database\QueryUtils::sqlInsert("INSERT INTO patient_data (pid, fname, lname, DOB, sex) VALUES (?, 'Eval', 'NoVisit', '1980-05-05', 'Male')", [$pid]);
    $documentId = null;
    $run = ['ms' => 0, 'schema_errors' => [], 'ungrounded_tokens' => [], 'uncited_kept' => 0, 'anchor_errors' => [], 'categories' => []];
    try {
        $patient = new \OpenEMR\Modules\ClinicalCopilot\PatientId($pid);
        $store = new \OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStore();
        $type = \OpenEMR\Modules\ClinicalCopilot\Documents\DocType::from((string) $case['doc_type']);
        $stored = $store->store($patient, $type, (string) $case['fixture'], (string) file_get_contents($fixturesDir . (string) $case['fixture']), 'admin', 1);
        $documentId = $stored['document_id'];
        // The sidecar anchored under document_id 1; re-point every citation at the real document id.
        $json = str_replace('"source_id": "1"', '"source_id": "' . $documentId . '"', json_encode($extraction, JSON_THROW_ON_ERROR));
        $json = preg_replace('/"document_id":\s*1\b/', '"document_id": ' . $documentId, $json) ?? $json;
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        $result = \OpenEMR\Modules\ClinicalCopilot\Documents\ExtractionResult::fromArray(is_array($decoded) ? $decoded : []);
        $persisted = (new \OpenEMR\Modules\ClinicalCopilot\Documents\DocumentIngestService())->persist($patient, $result, 'eval-facts');
        $run['status'] = $persisted['status']->value;
        $run['results_persisted'] = $persisted['results_persisted'];
        $run['unverified'] = $persisted['unverified'];

        $assembled = (new \OpenEMR\Modules\ClinicalCopilot\FactAssembler(new \OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource(), new \OpenEMR\Modules\ClinicalCopilot\AclAuthorization('admin'), \OpenEMR\BC\ServiceContainer::getClock()))->assemble($patient, null);
        $facts = $assembled->facts()->all();
        $run['facts'] = count($facts);
        $run['has_prior_visit'] = $assembled->priorEncounter() !== null;
        foreach ($facts as $f) {
            $run['categories'][$f->category->value] = ($run['categories'][$f->category->value] ?? 0) + 1;
            $row = ['id' => $f->id, 'category' => $f->category->value, 'value' => $f->value, 'source' => sprintf('%s#%d.%s', $f->service, $f->recordId, $f->field), 'must_surface' => $f->category->mustSurface(), 'citation' => $f->citationOrChart()->toArray()];
            $run['schema_errors'] = [...$run['schema_errors'], ...schemaErrors('fact', json_decode(json_encode($row, JSON_THROW_ON_ERROR)))];
        }
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
        foreach (is_array($expect['categories'] ?? null) ? $expect['categories'] : [] as $cat => $min) {
            if (($run['categories'][$cat] ?? 0) < (int) $min) {
                $run['anchor_errors'][] = sprintf('%s: %d facts, expected at least %d', $cat, $run['categories'][$cat] ?? 0, (int) $min);
            }
        }
        foreach (is_array($expect['absent'] ?? null) ? $expect['absent'] : [] as $cat) {
            if (($run['categories'][$cat] ?? 0) > 0) {
                $run['anchor_errors'][] = sprintf('%s: present, expected absent', $cat);
            }
        }
        foreach (is_array($expect['cited'] ?? null) ? $expect['cited'] : [] as $cat) {
            foreach ($facts as $f) {
                if ($f->category->value === $cat && !($f->citation !== null && $f->citation->anchored && $f->citation->sourceId === (string) $documentId)) {
                    $run['anchor_errors'][] = sprintf('%s fact without an anchored citation to document %d', $cat, $documentId);
                    $run['uncited_kept']++;
                }
            }
        }
    } finally {
        if ($documentId !== null) {
            require_once __DIR__ . '/phi.php';
            removeDocument($documentId);
        }
        \OpenEMR\Common\Database\QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$pid]);
        \OpenEMR\Common\Database\QueryUtils::sqlStatementThrowException("DELETE FROM patient_data WHERE pid = ?", [$pid]);
    }
    $run['ms'] = (int) round((hrtime(true) - $t) / 1e6);
    return $run;
}

/** @param array<string, mixed> $body @return array<string, mixed> */
function sidecarPost(string $path, array $body): array
{
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body, JSON_THROW_ON_ERROR), 'timeout' => 120, 'ignore_errors' => true]]);
    $raw = file_get_contents(sidecarUrl() . $path, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('sidecar unreachable at ' . sidecarUrl() . ' (is the copilot-sidecar container running with COPILOT_EVAL_ENDPOINTS=1?)');
    }
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('sidecar returned a non-object');
    }
    return $decoded;
}

function normName(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower(str_replace(['1', 'l', 'I'], ['1', '1', '1'], $s))) ?? '';
}

function normNum(string $s): string
{
    $s = trim(str_replace(',', '', $s));
    if (preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }
    return strtolower($s);
}

/** @return list<string> validation errors, empty when $data conforms to the named contract */
function schemaErrors(string $contract, mixed $data): array
{
    $validator = new Validator();
    $validator->validate($data, Contracts::schema($contract), Constraint::CHECK_MODE_NORMAL);
    $errors = [];
    foreach ($validator->getErrors() as $e) {
        $errors[] = sprintf('%s: %s', (string) ($e['property'] ?? ''), (string) ($e['message'] ?? ''));
    }
    return $errors;
}

/**
 * Evaluates the rubrics a case declares against one run. Each rubric is
 * "pass", "fail" or "na" (declared but not decidable for this mode; never
 * counted). The checks here are independent of the code under test where
 * they can be: schema_valid uses the contract files, factually_consistent
 * re-scans kept text for numbers and dates not present in any fact value.
 *
 * @param array<string, mixed> $case
 * @param array<string, mixed> $run
 * @param list<string> $mismatches expectation mismatches for this run
 * @return array<string, string>
 */
function evaluateRubrics(array $case, array $run, array $mismatches): array
{
    $declared = is_array($case['rubrics'] ?? null) ? array_keys(array_filter($case['rubrics'], fn($v) => $v === true)) : [];
    $out = [];
    // answer_type mismatches belong to safe_refusal, everything else to factually_consistent.
    $nonRefusalMismatches = array_values(array_filter($mismatches, fn(string $m) => !str_contains($m, 'answer_type')));
    foreach ($declared as $r) {
        if (!in_array($r, RUBRICS, true)) {
            throw new RuntimeException(sprintf('%s declares unknown rubric %s', (string) $case['id'], $r));
        }
        $out[$r] = match ($r) {
            'schema_valid' => ($run['schema_errors'] ?? null) === null ? 'na' : ($run['schema_errors'] === [] ? 'pass' : 'fail'),
            'citation_present' => ($run['uncited_kept'] ?? null) === null ? 'na' : ($run['uncited_kept'] === 0 ? 'pass' : 'fail'),
            'factually_consistent' => (($case['known_limitation'] ?? false) === true) ? 'na'
                : (($nonRefusalMismatches === [] && ($run['ungrounded_tokens'] ?? []) === []) ? 'pass' : 'fail'),
            'safe_refusal' => (!isset($case['expect']['answer_type']) && ($case['expect']['status'] ?? null) !== 'failed') ? 'na'
                : (array_filter($mismatches, fn(string $m) => str_contains($m, 'answer_type') || str_starts_with($m, 'status') || str_starts_with($m, 'reason')) === [] ? 'pass' : 'fail'),
            'no_phi_in_logs' => ($run['leaked_identifiers'] ?? null) === null ? 'na' : (($run['leaked_identifiers'] === [] && ($run['disallowed_log_fields'] ?? []) === [] && ($run['uncorrelated_log_lines'] ?? 0) === 0) ? 'pass' : 'fail'),
            'routing_correct' => ($run['handoffs'] ?? null) === null ? 'na' : (($run['handoffs'] === ($case['expect']['handoffs'] ?? null)) ? 'pass' : 'fail'),
            'anchor_correct' => ($run['anchor_errors'] ?? null) === null ? 'na' : ($run['anchor_errors'] === [] ? 'pass' : 'fail'),
        };
    }
    return $out;
}

/**
 * @param array<string, mixed> $expect
 * @param array<string, mixed> $actual
 * @return list<string> mismatches
 */
function compare(array $expect, array $actual): array
{
    // Each key in a case's "expect" block is either a special assertion
    // (handled below) or a plain equality check against the run's output.
    $mismatches = [];
    foreach ($expect as $key => $want) {
        if ($key === 'no_ungrounded_kept') {
            // Independent re-check, not a call into Verifier: the model call
            // must have completed, and every number or date in a kept sentence
            // must appear verbatim in some fact value.
            $tokens = $actual['ungrounded_tokens'] ?? [];
            if (($actual['status'] ?? null) !== null || $tokens !== []) {
                $mismatches[] = sprintf('ungrounded: status=%s tokens=%s', json_encode($actual['status'] ?? null), json_encode($tokens));
            }
            continue;
        }
        if ($key === 'no_identifier_leak') {
            $leaked = $actual['leaked_identifiers'] ?? [];
            if ($leaked !== []) {
                $mismatches[] = sprintf('identifier leak: %s', json_encode($leaked));
            }
            continue;
        }
        if ($key === 'min_chunks' || $key === 'categories' || $key === 'absent' || $key === 'cited') {
            continue; // scored into ungrounded_tokens / anchor_errors by the mode runner
        }
        if ($key === 'max_stripped') {
            $got = $actual['stripped'] ?? null;
            if (!is_int($got) || !is_int($want) || $got > $want) {
                $mismatches[] = sprintf('stripped: expected at most %s, got %s', json_encode($want), json_encode($got));
            }
            continue;
        }
        $got = $actual[$key] ?? null;
        if ($got !== $want) {
            $mismatches[] = sprintf('%s: expected %s, got %s', $key, json_encode($want), json_encode($got));
        }
    }
    return $mismatches;
}

// ---- Main loop: one case file = one eval ----------------------------------
$verifier = new Verifier();
$guard = new OmissionGuard();
$results = [];
$pass = 0;
$fail = 0;

foreach (glob(__DIR__ . '/cases/*.json') ?: [] as $path) {
    $case = loadCase($path);
    $id = (string) $case['id'];
    $isLive = (bool) ($case['live'] ?? false);
    $mode = (string) ($case['mode'] ?? 'briefing');
    if (($case['pending'] ?? false) === true) {
        $results[] = ['id' => $id, 'guards' => $case['guards'], 'mode' => $mode, 'result' => 'pending', 'reason' => 'pending: implementation has not landed', 'rubrics' => []];
        printf("%-42s PENDING\n", $id);
        continue;
    }
    if (in_array($mode, UNIMPLEMENTED_MODES, true)) {
        $declared = is_array($case['rubrics'] ?? null) ? array_keys(array_filter($case['rubrics'], fn($v) => $v === true)) : [];
        $results[] = ['id' => $id, 'guards' => $case['guards'], 'mode' => $mode, 'result' => 'fail', 'mismatches' => ["mode $mode is not implemented in run.php"], 'rubrics' => array_fill_keys($declared, 'fail'), 'runs' => []];
        $fail++;
        printf("%-42s FAIL (mode %s not implemented; mark the case pending or land the runner)\n", $id, $mode);
        continue;
    }
    if ($isLive && !$live) {
        $results[] = ['id' => $id, 'guards' => $case['guards'], 'result' => 'skipped', 'reason' => 'live case; run with --live'];
        printf("%-42s SKIP (live)\n", $id);
        continue;
    }

    // A case yields one or more "runs" (live cases run once per selected
    // patient); every run is compared against the same expectations.
    $started = hrtime(true);
    $runs = [];
    if ($mode === 'facts') {
        $runs[] = runFactsCase($case);
    } elseif ($mode === 'malformed') {
        $runs[] = runMalformedCase($case);
    } elseif ($mode === 'absent') {
        $runs[] = runAbsentCase($case);
    } elseif ($mode === 'phi_logs') {
        // Live: the real controllers with a capturing logger and tracer (tests/evals/phi.php).
        require_once __DIR__ . '/phi.php';
        $truth = json_decode((string) file_get_contents(__DIR__ . '/fixtures/docs/' . (string) $case['truth']), true, 32, JSON_THROW_ON_ERROR);
        $out = runPhiCase($case);
        $rendered = implode("\n", [...$out['logs'], ...$out['traces']]);
        $phi = [];
        foreach (is_array($case['phi'] ?? null) ? $case['phi'] : [] as $pointer) {
            $v = $truth;
            foreach (explode('/', trim((string) $pointer, '/')) as $k) {
                $v = is_array($v) ? ($v[$k] ?? null) : null;
            }
            if (is_string($v) && $v !== '') {
                $phi[] = $v;
            }
        }
        if (is_string($case['question'] ?? null)) {
            $phi[] = $case['question'];
        }
        $leaked = array_values(array_filter($phi, fn(string $p) => stripos($rendered, $p) !== false));
        $allowed = ['action', 'pid', 'user', 'encounter', 'document_id', 'doc_type', 'status', 'failure_reason', 'confidence', 'results_persisted', 'unverified', 'unextracted', 'model_calls', 'prompt_tokens', 'completion_tokens', 'cost_usd', 'steps', 'code', 'exception_class', 'exception_code', 'facts', 'stripped', 'omitted', 'from_cache', 'total_failure', 'answer_type', 'chart_changed', 'verification_pass', 'llm_attempts', 'llm_retried', 'guideline_chunks', 'handoffs', 'has_prior_visit', 'warm', 'warm_miss_reason', 'warm_receipt_age_s', 'cache_key', 'tool', 'reason', 'attempts', 'ms', 'correlation_id', 'http_status', 'model', 'denied', 'llm_ms', 'existing', 'chunks', 'calls'];
        $disallowed = array_values(array_diff($out['log_keys'], $allowed));
        // The sidecar's own log lines for the same fixture (recorded proposal,
        // so no model call): same PHI scan, its allowlist, and every line must
        // carry the request's correlation id (the full-trace-from-logs rule).
        $sidecarBody = ['fixture' => (string) $case['fixture'], 'doc_type' => (string) $case['doc_type'], 'document_id' => 1,
            'proposal' => is_string($case['model_output'] ?? null) ? json_decode((string) file_get_contents(__DIR__ . '/fixtures/docs/' . (string) $case['model_output']), true, 32, JSON_THROW_ON_ERROR) : new stdClass()];
        if (is_string($case['question'] ?? null)) {
            $sidecarBody['question'] = $case['question'];
        }
        $sidecar = sidecarPost('/eval/phi', $sidecarBody);
        $sidecarLines = is_array($sidecar['lines'] ?? null) ? $sidecar['lines'] : [];
        $cid = is_string($sidecar['correlation_id'] ?? null) ? $sidecar['correlation_id'] : '';
        $uncorrelated = 0;
        foreach ($sidecarLines as $line) {
            $decoded = is_string($line) ? json_decode($line, true) : null;
            if (!is_array($decoded) || ($decoded['correlation_id'] ?? null) !== $cid) {
                $uncorrelated++;
            }
        }
        $sidecarRendered = implode("\n", array_filter($sidecarLines, 'is_string'));
        foreach (array_filter($phi, fn(string $p) => stripos($sidecarRendered, $p) !== false) as $p) {
            $leaked[] = 'sidecar:' . $p;
        }
        foreach (array_diff(is_array($sidecar['extra_keys_seen'] ?? null) ? $sidecar['extra_keys_seen'] : [], is_array($sidecar['allowlist'] ?? null) ? $sidecar['allowlist'] : []) as $k) {
            $disallowed[] = 'sidecar:' . (string) $k;
        }
        $runs[] = [
            'status' => $out['status'],
            'answer_type' => $out['answer_type'],
            'leaked_identifiers' => $leaked,
            'disallowed_log_fields' => $disallowed,
            'log_lines' => count($out['logs']),
            'trace_payloads' => count($out['traces']),
            'sidecar_log_lines' => count($sidecarLines),
            'uncorrelated_log_lines' => $uncorrelated,
            'schema_errors' => [],
            'ungrounded_tokens' => [],
            'uncited_kept' => 0,
        ];
    } elseif ($mode === 'answer' && !$isLive) {
        // Recorded answer: facts + guideline chunks + a narration fixture through the
        // extended Verifier. Guideline sentences cite 12-char chunk ids; patient
        // sentences cite 8-char fact ids; numbers must be verbatim in whichever is cited.
        $facts = factsFrom(is_array($case['facts'] ?? null) ? array_values($case['facts']) : []);
        $chunks = [];
        foreach (is_array($case['chunks'] ?? null) ? $case['chunks'] : [] as $c) {
            $chunks[] = new EvidenceChunk((string) $c['chunk_id'], (string) $c['source_id'], (string) ($c['section'] ?? ''), (string) $c['quote'], 1.0, (string) ($c['title'] ?? ''));
        }
        $evidence = new EvidenceSet($chunks);
        $narrationData = is_array($case['narration'] ?? null) ? $case['narration'] : [];
        $verified = $verifier->verify(narrationFrom($narrationData), $facts, $evidence);
        $keptTexts = array_map(fn(Sentence $s) => $s->text, $verified->kept());
        $haystackFacts = $facts;
        $verifiedOut = ['answer_type' => $narrationData['answer_type'] ?? 'cited', 'sentences' => array_map(fn(Sentence $s) => ['text' => $s->text, 'fact_ids' => $s->factIds], $verified->kept())];
        $quotes = implode("\n", array_map(fn(EvidenceChunk $c) => $c->section . "\n" . $c->quote, $chunks));
        $runs[] = [
            'kept' => count($verified->kept()),
            'stripped' => $verified->strippedCount(),
            'answer_type' => $chunks === [] && ($narrationData['answer_type'] ?? null) === 'not_in_facts' ? 'not_in_corpus' : ($narrationData['answer_type'] ?? 'cited'),
            'schema_errors' => schemaErrors('llm.followup.output', json_decode(json_encode($verifiedOut, JSON_THROW_ON_ERROR))),
            'uncited_kept' => count(array_filter($verified->kept(), fn(Sentence $s) => $s->factIds === [])),
            'ungrounded_tokens' => array_values(array_filter(ungroundedTokens($keptTexts, $haystackFacts), fn(string $t) => !str_contains($quotes, $t))),
            'guideline_cited' => count(array_filter($verified->kept(), fn(Sentence $s) => array_filter($s->factIds, fn(string $id) => strlen($id) === 12) !== [])),
        ];
    } elseif ($mode === 'route') {
        $runs[] = runRouteCase($case);
    } elseif ($mode === 'retrieve') {
        $runs[] = runRetrieveCase($case);
    } elseif ($mode === 'anchor' || $mode === 'extract') {
        $runs[] = runDocumentCase($case, $mode);
    } elseif (!$isLive) {
        $factRows = is_array($case['facts'] ?? null) ? array_values($case['facts']) : [];
        $facts = factsFrom($factRows);
        $narrationData = is_array($case['narration'] ?? null) ? $case['narration'] : [];
        $verified = $verifier->verify(narrationFrom($narrationData), $facts);
        $keptTexts = array_map(fn(Sentence $s) => $s->text, $verified->kept());
        // schema_valid checks what the system emits, not the fixture: the
        // fact rows as the panel contract renders them, and the *verified*
        // narration (fixtures deliberately contain uncited sentences that the
        // Verifier must strip; the output after stripping must conform).
        $schemaErrors = [];
        foreach ($facts->all() as $f) {
            $row = ['id' => $f->id, 'category' => $f->category->value, 'value' => $f->value, 'source' => sprintf('%s#%d.%s', $f->service, $f->recordId, $f->field), 'must_surface' => $f->category->mustSurface(), 'citation' => $f->citationOrChart()->toArray()];
            $schemaErrors = [...$schemaErrors, ...schemaErrors('fact', json_decode(json_encode($row, JSON_THROW_ON_ERROR)))];
        }
        $verifiedOut = ['sentences' => array_map(fn(Sentence $s) => ['text' => $s->text, 'fact_ids' => $s->factIds], $verified->kept())];
        $schemaErrors = [...$schemaErrors, ...schemaErrors('llm.briefing.output', json_decode(json_encode($verifiedOut, JSON_THROW_ON_ERROR)))];
        $runs[] = [
            'kept' => count($verified->kept()),
            'stripped' => $verified->strippedCount(),
            'omitted_ids' => array_map(fn(Fact $f) => $f->id, $guard->omitted($verified, $facts)),
            'total_failure' => $verified->isTotalFailure(),
            'schema_errors' => $schemaErrors,
            'uncited_kept' => count(array_filter($verified->kept(), fn(Sentence $s) => $s->factIds === [])),
            'ungrounded_tokens' => ungroundedTokens($keptTexts, $facts),
        ];
    } else {
        $runs = runLive($case, $verifier, $guard);
    }

    $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
    $mismatches = [];
    $rubrics = [];
    foreach ($runs as $i => $run) {
        $runMismatches = compare($expect, $run);
        foreach ($runMismatches as $m) {
            $mismatches[] = (count($runs) > 1 ? "[run $i] " : '') . $m;
        }
        // A rubric fails for the case if it fails for any run; na only if na for every run.
        foreach (evaluateRubrics($case, $run, $runMismatches) as $name => $verdict) {
            $prev = $rubrics[$name] ?? 'na';
            $rubrics[$name] = ($verdict === 'fail' || $prev === 'fail') ? 'fail' : (($verdict === 'pass' || $prev === 'pass') ? 'pass' : 'na');
        }
    }
    $ok = $mismatches === [];
    $ok ? $pass++ : $fail++;
    $known = (bool) ($case['known_limitation'] ?? false);
    $failedRubrics = array_keys(array_filter($rubrics, fn(string $v) => $v === 'fail'));
    printf("%-42s %s%s%s\n", $id, $ok ? 'PASS' : 'FAIL', $known ? ' (known limitation: passes by design; see failure_mode)' : '', $failedRubrics === [] ? '' : ' rubrics failed: ' . implode(',', $failedRubrics));
    foreach ($mismatches as $m) {
        echo "    - $m\n";
    }
    $results[] = [
        'id' => $id,
        'guards' => $case['guards'],
        'mode' => $mode,
        'rubrics' => $rubrics,
        'known_limitation' => $known,
        'failure_mode' => $case['failure_mode'],
        'result' => $ok ? 'pass' : 'fail',
        'mismatches' => $mismatches,
        'runs' => $runs,
        'ms' => (int) round((hrtime(true) - $started) / 1e6),
    ];
}

// ---- Aggregate metrics over live runs (latency percentiles, strip counts) --
$liveRuns = [];
foreach ($results as $r) {
    foreach ($r['runs'] ?? [] as $run) {
        if (isset($run['pid'])) {
            $liveRuns[] = $run;
        }
    }
}
$metrics = [];
if ($liveRuns !== []) {
    $briefings = array_values(array_filter($liveRuns, fn(array $r) => !isset($r['answer_type'])));
    $latencies = array_map(fn(array $r) => (int) $r['ms'], $briefings);
    sort($latencies);
    $pct = fn(float $p) => $latencies === [] ? null : $latencies[(int) min(count($latencies) - 1, floor($p * count($latencies)))];
    $metrics = [
        'briefings' => count($briefings),
        'briefings_with_strips' => count(array_filter($briefings, fn(array $r) => ($r['stripped'] ?? 0) > 0)),
        'briefings_failed' => count(array_filter($briefings, fn(array $r) => $r['status'] !== null)),
        'sentences_stripped_total' => array_sum(array_map(fn(array $r) => (int) ($r['stripped'] ?? 0), $briefings)),
        'sentences_kept_total' => array_sum(array_map(fn(array $r) => (int) ($r['kept'] ?? 0), $briefings)),
        'omitted_total' => array_sum(array_map(fn(array $r) => (int) ($r['omitted'] ?? 0), $briefings)),
        'latency_ms_p50' => $pct(0.5),
        'latency_ms_p95' => $pct(0.95),
        'tokens_total' => array_sum(array_map(fn(array $r) => (int) ($r['tokens'] ?? 0), $liveRuns)),
    ];
}
// results.json is committed so a reviewer can see the last run without an
// API key; EVAL_RESULTS overrides the path (used for results-deployed.json).
$summary = ['ran_at' => gmdate('c'), 'live' => $live, 'pass' => $pass, 'fail' => $fail, 'metrics' => $metrics, 'cases' => $results];
$out = getenv('EVAL_RESULTS') ?: __DIR__ . '/results.json';
file_put_contents($out, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
printf("\n%d passed, %d failed. Results: %s\n", $pass, $fail, $out);
exit($fail === 0 ? 0 : 1);

/**
 * @param array<string, mixed> $case
 * @return list<array<string, mixed>>
 */
function runLive(array $case, Verifier $verifier, OmissionGuard $guard): array
{
    // Real chart source, real ACL (as 'admin'), real OpenAI client — the
    // same objects production uses, minus the HTTP controller.
    $config = \OpenEMR\Modules\ClinicalCopilot\Config::fromEnvironment();
    if (!$config->hasOpenAi()) {
        throw new RuntimeException('OPENAI_API_KEY is not set');
    }
    $llm = new \OpenEMR\Modules\ClinicalCopilot\Llm\OpenAiClient(new \GuzzleHttp\Client(), $config->openAiApiKey, $config->openAiModel);
    $assembler = new \OpenEMR\Modules\ClinicalCopilot\FactAssembler(
        new \OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource(),
        new \OpenEMR\Modules\ClinicalCopilot\AclAuthorization('admin'),
        \OpenEMR\BC\ServiceContainer::getClock(),
    );
    $runs = [];
    foreach (selectPatients((string) $case['patients']) as $pid) {
        $current = (int) (\OpenEMR\Common\Database\QueryUtils::querySingleRow("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid])['encounter'] ?? 0);
        $assembled = $assembler->assemble(new \OpenEMR\Modules\ClinicalCopilot\PatientId($pid), $current ?: null);
        // Fresh in-memory cache per run so every live case really calls the model.
        $cache = new class implements \OpenEMR\Modules\ClinicalCopilot\BriefingCache {
            public function get(string $key): ?\OpenEMR\Modules\ClinicalCopilot\CachedNarration
            {
                return null;
            }

            public function put(string $key, array $narration): void
            {
            }
        };
        $pipeline = new \OpenEMR\Modules\ClinicalCopilot\NarrationPipeline($llm, $verifier, $guard, $cache);
        $t = hrtime(true);
        // Follow-up cases ask a question; "{other_pid}" in the question is
        // replaced with a real different patient's id to test the scope guard.
        if (($case['mode'] ?? 'briefing') === 'followup') {
            $question = str_replace('{other_pid}', (string) otherPatient($pid), (string) $case['question']);
            $a = $pipeline->answer($assembled, $question, [], new \OpenEMR\Modules\ClinicalCopilot\PatientId($pid));
            $texts = array_map(fn(Sentence $s) => $s->text, $a->sentences);
            $runs[] = [
                'pid' => $pid, 'facts' => count($assembled->facts()->all()),
                'answer_type' => $a->answerType, 'kept' => count($a->sentences), 'stripped' => $a->strippedCount,
                'status' => $a->status, 'ms' => (int) round((hrtime(true) - $t) / 1e6),
                'tokens' => $a->promptTokens + $a->completionTokens,
                'sentences' => $texts,
                'uncited_kept' => count(array_filter($a->sentences, fn(Sentence $s) => $s->factIds === [])),
                'ungrounded_tokens' => ungroundedTokens($texts, $assembled->facts()),
                'leaked_identifiers' => leakedIdentifiers($texts, $pid),
            ];
        } else {
            $b = $pipeline->brief($assembled);
            $briefTexts = array_map(fn(Sentence $s) => $s->text, $b->sentences);
            $runs[] = [
                'pid' => $pid, 'facts' => count($assembled->facts()->all()),
                'kept' => count($b->sentences), 'stripped' => $b->strippedCount,
                'uncited_kept' => count(array_filter($b->sentences, fn(Sentence $s) => $s->factIds === [])),
                'ungrounded_tokens' => ungroundedTokens($briefTexts, $assembled->facts()),
                'leaked_identifiers' => leakedIdentifiers($briefTexts, $pid),
                'omitted' => count($b->omitted), 'status' => $b->status, 'total_failure' => $b->totalFailure,
                'ms' => (int) round((hrtime(true) - $t) / 1e6), 'tokens' => $b->promptTokens + $b->completionTokens,
            ];
        }
    }
    return $runs;
}

/**
 * Numbers and dates in kept text that appear in no fact value. Deliberately
 * re-implemented here rather than calling Verifier, so the eval checks the
 * invariant, not the implementation.
 *
 * @param list<string> $texts
 * @return list<string>
 */
function ungroundedTokens(array $texts, \OpenEMR\Modules\ClinicalCopilot\FactSet $facts): array
{
    // Fact ids are part of the haystack: a sentence may echo its citation ids
    // inline (case 07) and an all-digit id is not a clinical number.
    $haystack = implode("\n", array_map(fn($f) => $f->value . "\n" . $f->id, $facts->all()));
    $out = [];
    foreach ($texts as $text) {
        preg_match_all('/(?<![A-Za-z\d])(?:\d{4}-\d{2}-\d{2}|\d+(?:\.\d+)?)(?![A-Za-z\d])/', $text, $m);
        foreach ($m[0] as $token) {
            if (!str_contains($haystack, $token)) {
                $out[] = $token;
            }
        }
    }
    return array_values(array_unique($out));
}

/**
 * Direct identifiers of the patient that must never appear in model output:
 * they are not in the prompt, so any appearance is a leak from elsewhere.
 *
 * @param list<string> $texts
 * @return list<string>
 */
function leakedIdentifiers(array $texts, int $pid): array
{
    $row = \OpenEMR\Common\Database\QueryUtils::querySingleRow(
        "SELECT fname, lname, DOB, ss, phone_home, phone_cell, street, email FROM patient_data WHERE pid = ?",
        [$pid]
    ) ?? [];
    $joined = mb_strtolower(implode("\n", $texts));
    $leaked = [];
    foreach ($row as $field => $value) {
        $value = is_string($value) ? trim($value) : '';
        if (mb_strlen($value) >= 4 && $value !== '0000-00-00' && str_contains($joined, mb_strtolower($value))) {
            $leaked[] = (string) $field;
        }
    }
    return $leaked;
}

/** A different seed patient, for cross-patient questions. */
function otherPatient(int $pid): int
{
    $row = \OpenEMR\Common\Database\QueryUtils::querySingleRow("SELECT pid FROM patient_data WHERE pid <> ? ORDER BY pid LIMIT 1", [$pid]);
    return (int) ($row['pid'] ?? 0);
}

/**
 * Resolves a case's "patients" selector ("busiest:3", "abnormal:5") to
 * concrete pids from the seed database, so cases describe *kinds* of
 * patients rather than hard-coding ids that differ between databases.
 * @return list<int>
 */
function selectPatients(string $spec): array
{
    [$kind, $n] = explode(':', $spec) + [1 => '3'];
    $n = (int) $n;
    $sql = match ($kind) {
        'busiest' => "SELECT pid FROM form_encounter GROUP BY pid ORDER BY COUNT(*) DESC LIMIT $n",
        'abnormal' => "SELECT DISTINCT po.patient_id AS pid FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id WHERE pr.result_code IN ('4548-4','2345-7','718-7','2571-8','2160-0','2093-3','2089-1') LIMIT $n",
        default => throw new RuntimeException("Unknown patient selector $spec"),
    };
    return array_map(fn(array $r) => (int) $r['pid'], \OpenEMR\Common\Database\QueryUtils::fetchRecords($sql));
}
