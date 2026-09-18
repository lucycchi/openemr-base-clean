<?php

/**
 * Live smoke: real facts -> OpenAI -> verifier -> omission guard -> cache, twice.
 * The "twice" proves the second call is a cache hit. Manual developer tool
 * for a first end-to-end run on a new environment; run.php --live is the
 * repeatable version.
 * Requires OPENAI_API_KEY in the environment (.env). Costs a few hundred tokens.
 * Run: openemr-cmd e "su -s /bin/sh apache -c 'php /var/www/localhost/htdocs/openemr/tests/evals/spike/narrate_smoke.php [pid]'"
 */

declare(strict_types=1);

$ignoreAuth = 1;
$_GET['site'] = 'default';
$sessionAllowWrite = true;
require_once __DIR__ . '/../../../interface/globals.php';

use Composer\Autoload\ClassLoader;
use GuzzleHttp\Client;
use Lcobucci\Clock\SystemClock;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\AclAuthorization;
use OpenEMR\Modules\ClinicalCopilot\DbBriefingCache;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\Llm\OpenAiClient;
use OpenEMR\Modules\ClinicalCopilot\NarrationPipeline;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Verifier;

$loaders = ClassLoader::getRegisteredLoaders();
reset($loaders)->addPsr4('OpenEMR\\Modules\\ClinicalCopilot\\', __DIR__ . '/../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/');

$apiKey = getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '');
if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "OPENAI_API_KEY not set\n");
    exit(1);
}
$model = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';

$installSql = (string) file_get_contents(__DIR__ . '/../../../interface/modules/custom_modules/oe-module-clinical-copilot/sql/install.sql');
QueryUtils::sqlStatementThrowException((string) preg_replace('/^(--.*|#.*)$/m', '', $installSql), []);

$pid = (int) ($argv[1] ?? 4);
$current = (int) (QueryUtils::querySingleRow("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid])['encounter'] ?? 0);
$assembled = (new FactAssembler(new OpenEmrChartSource(), new AclAuthorization('admin'), SystemClock::fromUTC()))
    ->assemble(new PatientId($pid), $current ?: null);
echo "pid $pid: " . count($assembled->facts()->all()) . " facts, hash " . substr($assembled->facts()->hash(), 0, 8) . "\n";

$llm = new OpenAiClient(new Client(), $apiKey, $model);
$cache = new DbBriefingCache(new PatientId($pid), $assembled->facts()->hash(), $model);
$pipeline = new NarrationPipeline($llm, new Verifier(), new OmissionGuard(), $cache);

foreach ([1, 2] as $run) {
    $t = hrtime(true);
    $r = $pipeline->brief($assembled);
    $ms = round((hrtime(true) - $t) / 1e6);
    echo "\n--- run $run: {$ms}ms cache=" . var_export($r->fromCache, true) . " stripped={$r->strippedCount} omitted=" . count($r->omitted) . " status=" . ($r->status ?? 'ok') . " tokens={$r->promptTokens}+{$r->completionTokens}\n";
    foreach ($r->sentences as $s) {
        echo "  * {$s->text}  [" . implode(',', $s->factIds) . "]\n";
    }
    foreach ($r->omitted as $f) {
        echo "  + (also on file) {$f->category->value}: {$f->value}\n";
    }
}

$t = hrtime(true);
$a = $pipeline->answer($assembled, 'Which lab was out of range and by how much?', []);
echo "\n--- follow-up: " . round((hrtime(true) - $t) / 1e6) . "ms type={$a->answerType} stripped={$a->strippedCount} status=" . ($a->status ?? 'ok') . "\n";
foreach ($a->sentences as $s) {
    echo "  * {$s->text}  [" . implode(',', $s->factIds) . "]\n";
}
$a = $pipeline->answer($assembled, 'What was her blood pressure at the last visit?', []);
echo "--- follow-up (out of window): type={$a->answerType} sentences=" . count($a->sentences) . "\n";
