<?php

/**
 * Historical spike B (companion to spike.php): quick queries against the
 * seed data to check which lab/allergy/medication columns are populated and
 * what the ACL returns for a restricted user. Not part of the test suite.
 */

declare(strict_types=1);

$ignoreAuth = 1; $_GET['site'] = 'default'; $sessionAllowWrite = true;
require_once __DIR__ . '/../../../interface/globals.php';
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
echo "=== B2. ACL by explicit user (tool path must pass \$user) ===\n";
foreach (['admin','physician','clinician','receptionist','accountant','portal-user'] as $u) {
    $r = [];
    foreach ([['patients','med'],['encounters','notes'],['sensitivities','normal'],['admin','super']] as [$s,$v]) {
        $r[] = "$s/$v=" . (AclMain::aclCheckCore($s,$v,$u) ? 'Y' : 'n');
    }
    echo str_pad($u, 14) . implode('  ', $r) . "\n";
}
echo "\n=== E. Can 'abnormal' be derived from range? ===\n";
$r = QueryUtils::querySingleRow("SELECT COUNT(*) c, SUM(`range` <> '' AND `range` IS NOT NULL) has_range, SUM(result REGEXP '^[0-9.]+$') numeric_result FROM procedure_result");
echo "results {$r['c']}, with range {$r['has_range']}, numeric result {$r['numeric_result']}\n";
foreach (QueryUtils::fetchRecords("SELECT result_text, result, units, `range`, abnormal FROM procedure_result WHERE result <> '' LIMIT 6") as $x) {
    echo "  {$x['result_text']}: {$x['result']} {$x['units']} range='{$x['range']}' abnormal='{$x['abnormal']}'\n";
}
echo "\n=== F. Allergy titles vs drug names sample ===\n";
foreach (QueryUtils::fetchRecords("SELECT title FROM lists WHERE type='allergy' LIMIT 8") as $a) echo "  allergy: {$a['title']}\n";
foreach (QueryUtils::fetchRecords("SELECT DISTINCT drug FROM prescriptions LIMIT 8") as $d) echo "  drug: {$d['drug']}\n";
