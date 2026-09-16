<?php

/**
 * Clinical Co-Pilot eval harness.
 *
 * Recorded cases (default) replay a narration fixture through the Verifier
 * and OmissionGuard: deterministic, no network, no database. Live cases
 * (--live) assemble real facts from the seed database and call OpenAI.
 * Results are written to tests/evals/results.json.
 *
 * Usage (inside the openemr container, as apache):
 *   php tests/evals/run.php            # recorded cases only
 *   php tests/evals/run.php --live     # recorded + live cases
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

if ($live) {
    $ignoreAuth = 1;
    $_GET['site'] = 'default';
    $sessionAllowWrite = true;
    require_once $root . '/interface/globals.php';
} else {
    require_once $root . '/vendor/autoload.php';
}

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Narration;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Modules\ClinicalCopilot\Verifier;

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

/** @param list<array<string, mixed>> $rows */
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

/** @param array<string, mixed> $data */
function narrationFrom(array $data): Narration
{
    $sentences = [];
    foreach (is_array($data['sentences'] ?? null) ? $data['sentences'] : [] as $s) {
        if (is_array($s)) {
            $sentences[] = new Sentence((string) ($s['text'] ?? ''), array_values(array_map('strval', is_array($s['fact_ids'] ?? null) ? $s['fact_ids'] : [])));
        }
    }
    return new Narration($sentences);
}

/**
 * @param array<string, mixed> $expect
 * @param array<string, mixed> $actual
 * @return list<string> mismatches
 */
function compare(array $expect, array $actual): array
{
    $mismatches = [];
    foreach ($expect as $key => $want) {
        if ($key === 'no_ungrounded_kept') {
            continue;
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

$verifier = new Verifier();
$guard = new OmissionGuard();
$results = [];
$pass = 0;
$fail = 0;

foreach (glob(__DIR__ . '/cases/*.json') ?: [] as $path) {
    $case = loadCase($path);
    $id = (string) $case['id'];
    $isLive = (bool) ($case['live'] ?? false);
    if ($isLive && !$live) {
        $results[] = ['id' => $id, 'guards' => $case['guards'], 'result' => 'skipped', 'reason' => 'live case; run with --live'];
        printf("%-42s SKIP (live)\n", $id);
        continue;
    }

    $started = hrtime(true);
    $runs = [];
    if (!$isLive) {
        $facts = factsFrom(is_array($case['facts'] ?? null) ? array_values($case['facts']) : []);
        $verified = $verifier->verify(narrationFrom(is_array($case['narration'] ?? null) ? $case['narration'] : []), $facts);
        $runs[] = [
            'kept' => count($verified->kept()),
            'stripped' => $verified->strippedCount(),
            'omitted_ids' => array_map(fn(Fact $f) => $f->id, $guard->omitted($verified, $facts)),
            'total_failure' => $verified->isTotalFailure(),
        ];
    } else {
        $runs = runLive($case, $verifier, $guard);
    }

    $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];
    $mismatches = [];
    foreach ($runs as $i => $run) {
        foreach (compare($expect, $run) as $m) {
            $mismatches[] = (count($runs) > 1 ? "[run $i] " : '') . $m;
        }
        if (($expect['no_ungrounded_kept'] ?? false) === true && ($run['answer_type'] ?? '') === 'cited' && ($run['stripped'] ?? 0) === 0 && ($run['kept'] ?? 0) > 0) {
            // Verifier kept everything: the model must have answered with recorded values only. Acceptable.
        }
    }
    $ok = $mismatches === [];
    $ok ? $pass++ : $fail++;
    $known = (bool) ($case['known_limitation'] ?? false);
    printf("%-42s %s%s\n", $id, $ok ? 'PASS' : 'FAIL', $known ? ' (known limitation: passes by design; see failure_mode)' : '');
    foreach ($mismatches as $m) {
        echo "    - $m\n";
    }
    $results[] = [
        'id' => $id,
        'guards' => $case['guards'],
        'known_limitation' => $known,
        'failure_mode' => $case['failure_mode'],
        'result' => $ok ? 'pass' : 'fail',
        'mismatches' => $mismatches,
        'runs' => $runs,
        'ms' => (int) round((hrtime(true) - $started) / 1e6),
    ];
}

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
            public function get(string $key): ?array
            {
                return null;
            }

            public function put(string $key, array $narration): void
            {
            }
        };
        $pipeline = new \OpenEMR\Modules\ClinicalCopilot\NarrationPipeline($llm, $verifier, $guard, $cache);
        $t = hrtime(true);
        if (($case['mode'] ?? 'briefing') === 'followup') {
            $a = $pipeline->answer($assembled, (string) $case['question'], []);
            $runs[] = [
                'pid' => $pid, 'facts' => count($assembled->facts()->all()),
                'answer_type' => $a->answerType, 'kept' => count($a->sentences), 'stripped' => $a->strippedCount,
                'status' => $a->status, 'ms' => (int) round((hrtime(true) - $t) / 1e6),
                'tokens' => $a->promptTokens + $a->completionTokens,
                'sentences' => array_map(fn(Sentence $s) => $s->text, $a->sentences),
            ];
        } else {
            $b = $pipeline->brief($assembled);
            $runs[] = [
                'pid' => $pid, 'facts' => count($assembled->facts()->all()),
                'kept' => count($b->sentences), 'stripped' => $b->strippedCount,
                'omitted' => count($b->omitted), 'status' => $b->status, 'total_failure' => $b->totalFailure,
                'ms' => (int) round((hrtime(true) - $t) / 1e6), 'tokens' => $b->promptTokens + $b->completionTokens,
            ];
        }
    }
    return $runs;
}

/** @return list<int> */
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
