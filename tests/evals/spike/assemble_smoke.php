<?php

/**
 * Smoke: run the real FactAssembler (OpenEMR adapters) for the busiest seed
 * patients as admin and as a restricted user. Manual, DB-backed check that
 * the SQL in OpenEmrChartSource and the ACL wiring behave on real seed data
 * (no model call). Superseded by run.php --live and smoke.php for CI-style
 * use, but handy for eyeballing fact output.
 * Run: openemr-cmd e "su -s /bin/sh apache -c 'php /var/www/localhost/htdocs/openemr/tests/evals/spike/assemble_smoke.php'"
 */

declare(strict_types=1);

$ignoreAuth = 1;
$_GET['site'] = 'default';
$sessionAllowWrite = true;
require_once __DIR__ . '/../../../interface/globals.php';

use Composer\Autoload\ClassLoader;
use Lcobucci\Clock\SystemClock;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\AccessDeniedException;
use OpenEMR\Modules\ClinicalCopilot\AclAuthorization;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\PatientId;

$loaders = ClassLoader::getRegisteredLoaders();
reset($loaders)->addPsr4('OpenEMR\\Modules\\ClinicalCopilot\\', __DIR__ . '/../../../interface/modules/custom_modules/oe-module-clinical-copilot/src/');

$pids = array_map(fn($r) => (int) $r['pid'], QueryUtils::fetchRecords("SELECT pid FROM form_encounter GROUP BY pid ORDER BY COUNT(*) DESC LIMIT 10"));

foreach (['admin', 'receptionist'] as $user) {
    echo "=== as $user ===\n";
    $assembler = new FactAssembler(new OpenEmrChartSource(), new AclAuthorization($user), SystemClock::fromUTC());
    foreach ($pids as $pid) {
        $t = hrtime(true);
        try {
            $current = (int) (QueryUtils::querySingleRow("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", [$pid])['encounter'] ?? 0);
            $r = $assembler->assemble(new PatientId($pid), $current ?: null);
        } catch (AccessDeniedException $e) {
            echo "pid $pid: DENIED (" . $e->getMessage() . ")\n";
            continue;
        }
        $ms = round((hrtime(true) - $t) / 1e6, 1);
        $byCat = [];
        foreach ($r->facts()->all() as $f) {
            $byCat[$f->category->value] = ($byCat[$f->category->value] ?? 0) + 1;
        }
        ksort($byCat);
        $prior = $r->priorEncounter();
        printf("pid %-3d %6sms prior=%s facts=%d %s hash=%s\n", $pid, $ms, $prior ? $prior->date->format('Y-m-d') : 'none', count($r->facts()->all()), json_encode($byCat), substr($r->facts()->hash(), 0, 8));
        if ($user === 'admin' && in_array($pid, [4, 15], true)) {
            foreach ($r->facts()->all() as $f) {
                if (in_array($f->category->value, ['lab_abnormal', 'lab_delta', 'medication_new', 'encounter'], true)) {
                    echo "   [{$f->id}] {$f->category->value}: {$f->value}\n";
                }
            }
        }
    }
}
