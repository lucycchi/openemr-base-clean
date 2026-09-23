<?php

/**
 * Runtime validation spike for the Clinical Co-Pilot design (T2).
 * Run: openemr-cmd e 'php /var/www/localhost/htdocs/openemr/tests/evals/spike/spike.php'
 *
 * Historical, pre-implementation script: before writing the module, this
 * measured how long OpenEMR's own service classes take to load each chart
 * section and whether the ACL calls behave as documented. Its findings
 * (see spike-results.md) drove the decision to write OpenEmrChartSource
 * with direct SQL rather than go through the service layer. Kept for the
 * record; not part of the test suite.
 */

declare(strict_types=1);

$ignoreAuth = 1;
$_GET['site'] = 'default';
$sessionAllowWrite = true;
require_once __DIR__ . '/../../../interface/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Services\AllergyIntoleranceService;
use OpenEMR\Services\ConditionService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\ObservationLabService;
use OpenEMR\Services\PrescriptionService;

function ms(callable $fn): array
{
    $t = hrtime(true);
    try {
        $r = $fn();
        $err = null;
    } catch (\Throwable $e) {
        $r = null;
        $err = $e::class . ': ' . $e->getMessage();
    }
    return [round((hrtime(true) - $t) / 1e6, 1), $r, $err];
}

function rows($result): int
{
    if (is_array($result)) {
        return count($result);
    }
    if (is_object($result) && method_exists($result, 'getData')) {
        return count($result->getData() ?? []);
    }
    return -1;
}

echo "=== A. Seed-wide data quality ===\n";
$q = fn(string $sql) => sqlQuery($sql);
echo "patients: " . $q("SELECT COUNT(*) c FROM patient_data")['c'] . "\n";
echo "encounters: " . $q("SELECT COUNT(*) c FROM form_encounter")['c']
    . "  with sensitivity set: " . $q("SELECT COUNT(*) c FROM form_encounter WHERE sensitivity <> '' AND sensitivity IS NOT NULL")['c']
    . "  distinct sensitivity values: " . implode(',', array_map(fn($r) => $r['sensitivity'], \OpenEMR\Common\Database\QueryUtils::fetchRecords("SELECT DISTINCT sensitivity FROM form_encounter"))) . "\n";
echo "patients with >=2 encounters: " . $q("SELECT COUNT(*) c FROM (SELECT pid FROM form_encounter GROUP BY pid HAVING COUNT(*) >= 2) t")['c'] . "\n";
echo "encounters with NULL/zero date: " . $q("SELECT COUNT(*) c FROM form_encounter WHERE date IS NULL OR date = '0000-00-00 00:00:00'")['c'] . "\n";
echo "encounters dated in the future: " . $q("SELECT COUNT(*) c FROM form_encounter WHERE date > NOW()")['c'] . "\n";
echo "lab results total: " . $q("SELECT COUNT(*) c FROM procedure_result")['c']
    . "  abnormal flag set: " . $q("SELECT COUNT(*) c FROM procedure_result WHERE abnormal <> '' AND abnormal IS NOT NULL")['c']
    . "  distinct abnormal values: " . implode(',', array_map(fn($r) => $r['abnormal'], \OpenEMR\Common\Database\QueryUtils::fetchRecords("SELECT DISTINCT abnormal FROM procedure_result"))) . "\n";
echo "patients with >=1 abnormal result: " . $q("SELECT COUNT(DISTINCT po.patient_id) c FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id WHERE pr.abnormal <> '' AND pr.abnormal IS NOT NULL")['c'] . "\n";
echo "allergies (lists type=allergy): " . $q("SELECT COUNT(*) c FROM lists WHERE type='allergy'")['c'] . "\n";
echo "prescriptions: " . $q("SELECT COUNT(*) c FROM prescriptions")['c'] . "\n";
$overlap = \OpenEMR\Common\Database\QueryUtils::fetchRecords(
    "SELECT DISTINCT l.pid, l.title AS allergy, p.drug FROM lists l JOIN prescriptions p ON p.patient_id = l.pid
     WHERE l.type='allergy' AND l.title <> '' AND LOWER(p.drug) LIKE CONCAT('%', LOWER(SUBSTRING_INDEX(l.title,' ',1)), '%') LIMIT 10"
);
echo "allergy/drug substring overlaps (first word): " . count($overlap) . "\n";
foreach ($overlap as $o) {
    echo "   pid {$o['pid']}: allergy '{$o['allergy']}' vs drug '{$o['drug']}'\n";
}

echo "\n=== B. ACL on the tool path (admin) ===\n";
$admin = sqlQuery("SELECT id, username FROM users WHERE username='admin'");
$_SESSION['authUserID'] = $admin['id'];
$_SESSION['authUser'] = $admin['username'];
foreach ([['patients', 'med'], ['encounters', 'notes'], ['patients', 'demo']] as [$s, $r]) {
    echo "aclCheckCore($s,$r) as admin: " . var_export((bool) AclMain::aclCheckCore($s, $r), true) . "\n";
}
echo "users and ACL groups:\n";
foreach (\OpenEMR\Common\Database\QueryUtils::fetchRecords("SELECT u.id, u.username, GROUP_CONCAT(ga.name) grp FROM users u LEFT JOIN gacl_aro aro ON aro.value = u.username LEFT JOIN gacl_groups_aro_map m ON m.aro_id = aro.id LEFT JOIN gacl_aro_groups ga ON ga.id = m.group_id WHERE u.username <> '' GROUP BY u.id") as $u) {
    echo "   {$u['id']} {$u['username']}: {$u['grp']}\n";
}

echo "\n=== C. Per-patient fact assembly timing (services) ===\n";
$pids = array_map(fn($r) => (int) $r['pid'], \OpenEMR\Common\Database\QueryUtils::fetchRecords(
    "SELECT pid FROM form_encounter GROUP BY pid ORDER BY COUNT(*) DESC LIMIT 10"
));
$enc = new EncounterService();
$rx = new PrescriptionService();
$al = new AllergyIntoleranceService();
$lab = new ObservationLabService();
$cond = new ConditionService();
printf("%-5s %-6s %-9s %-9s %-9s %-9s %-9s %-8s\n", 'pid', 'encs', 'enc_ms', 'rx_ms', 'allergy', 'lab_ms', 'cond_ms', 'total');
foreach ($pids as $pid) {
    $total = hrtime(true);
    [$t1, $r1, $e1] = ms(fn() => $enc->search(['pid' => $pid]));
    [$t2, $r2, $e2] = ms(fn() => $rx->getAll(['patient_id' => $pid]));
    [$t3, $r3, $e3] = ms(fn() => $al->search(['lists.pid' => $pid]));
    [$t4, $r4, $e4] = ms(fn() => $lab->search(['patient_id' => $pid]));
    [$t5, $r5, $e5] = ms(fn() => $cond->search(['lists.pid' => $pid]));
    $tot = round((hrtime(true) - $total) / 1e6, 1);
    printf(
        "%-5d %-6d %-9s %-9s %-9s %-9s %-9s %-8s\n",
        $pid,
        rows($r1),
        "$t1/" . rows($r1),
        "$t2/" . rows($r2),
        "$t3/" . rows($r3),
        "$t4/" . rows($r4),
        "$t5/" . rows($r5),
        $tot
    );
    foreach ([$e1, $e2, $e3, $e4, $e5] as $e) {
        if ($e) {
            echo "   ERR: $e\n";
        }
    }
}
echo "(cells are ms/rows)\n";

echo "\n=== D. Prior-encounter selection sanity (rule 6A) ===\n";
foreach (array_slice($pids, 0, 5) as $pid) {
    $rows = \OpenEMR\Common\Database\QueryUtils::fetchRecords("SELECT encounter, date, sensitivity, facility_id FROM form_encounter WHERE pid=? ORDER BY date DESC, encounter DESC LIMIT 3", [$pid]);
    echo "pid $pid latest 3: " . implode(' | ', array_map(fn($r) => "{$r['encounter']}@{$r['date']}" . ($r['sensitivity'] ? "[{$r['sensitivity']}]" : ''), $rows)) . "\n";
}
