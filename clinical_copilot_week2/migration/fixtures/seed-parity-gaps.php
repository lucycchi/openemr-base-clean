<?php

/**
 * Seeds the synthetic TP-TYPICAL and TP-LONG data that exposes the parity gaps Codex found on 2026-09-26.
 * Dev stack only; every patient here is synthetic. Safe to re-run.
 *
 *  - Type 2 diabetes (lists 1238) is linked to two visits (2488, 2490), so FHIR drops it from
 *    the problem-list-item search and returns it twice as an encounter-diagnosis.
 *  - Omeprazole (prescriptions 2480) gets a future end date and 2 refills, so FHIR reports it as
 *    "completed" with 0 refills while the old card shows it as active with 2.
 *  - Fred Stone's care-team membership (care_team_member 1) gets facility 3, Great Clinic.
 *  - Lisinopril on TP-TYPICAL's medication list (lists 1242) ends 2027-06-30, so FHIR reports it
 *    as "completed" while the old card, which keeps a future end date, still lists it (BM-044).
 *  - TP-HISTORY gets a second visit on 2024-10-26, "Same-day follow-up", so two visits share a date and
 *    the tie order can be compared with the old page (date desc, then id desc).
 *  - TP-HISTORY gets a problem inserted the way the Fee Sheet does (fee_sheet_queries.php:72), with no
 *    activity value, so FHIR's problem list leaves it out while the old card shows it (Fable review 2).
 *  - TP-LONG gets the middle name Quinn: the old identity bar shows first and last name only, the new
 *    header all given names (BM-052).
 *  - Metformin on TP-TYPICAL's medication list (lists 1241) gets dosage text, so the dosage parity
 *    check has something to compare on the Medications card (Codex review 3).
 *  - TP-LONG gets a care team, "LongLists team", with Donna Lee (who has an NPI) as a member since
 *    2025-01-15, so care-team parity must show a real resolved name (Opus review 4).
 *  - A non-admin dev user, tp-physician (password tp-physician-pass, dev stack only), in OpenEMR's
 *    default Physicians group, so tests can see what a clinician sees: the API lets only
 *    administrators read Practitioner and Organization (Fable review F1).
 *  - TP-LONG's "Long-list allergen 02" (lists 1248) is marked resolved with no end date (FHIR still
 *    says active; the old card hides it), and "Long-list allergen 03" (lists 1249) ends 2027-12-31
 *    (FHIR says inactive; the old card still lists it). Fable review F2.
 *  - TP-LONG's "Long-list allergen 01" (lists 1247) becomes severe, so the old card highlights it
 *    and FHIR reports criticality high: no fixture had a high-risk allergy before.
 *
 * Run as the apache user (OpenEMR refuses CLI scripts as root), from docker/development-easy:
 *   docker compose exec -u apache openemr php \
 *     /var/www/localhost/htdocs/openemr/clinical_copilot_week2/migration/fixtures/seed-parity-gaps.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = true;
$_GET['site'] = 'default';
require_once '/var/www/localhost/htdocs/openemr/interface/globals.php';

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientIssuesService;

const TYPICAL_PID = '36';
const DIABETES_ISSUE = '1238';
const ADMIN_USER = 1;

$issues = new PatientIssuesService();
foreach (['2488', '2490'] as $encounter) {
    $linked = QueryUtils::fetchSingleValue(
        'SELECT COUNT(*) AS c FROM issue_encounter WHERE pid = ? AND list_id = ? AND encounter = ?',
        'c',
        [TYPICAL_PID, DIABETES_ISSUE, $encounter],
    );
    if ((int) $linked === 0) {
        $issues->linkIssueToEncounter(TYPICAL_PID, $encounter, DIABETES_ISSUE, ADMIN_USER);
    }
    echo "linked issue " . DIABETES_ISSUE . " to encounter {$encounter}\n";
}

// date_modified is left alone so the old card's sort (active, date_modified desc) does not change.
QueryUtils::sqlStatementThrowException(
    "UPDATE prescriptions SET end_date = '2027-03-31', refills = 2 WHERE id = 2480 AND patient_id = ?",
    [TYPICAL_PID],
);
echo "prescription 2480: end_date 2027-03-31, refills 2\n";

QueryUtils::sqlStatementThrowException('UPDATE care_team_member SET facility_id = 3 WHERE id = 1 AND user_id = 5');
echo "care_team_member 1: facility 3\n";

QueryUtils::sqlStatementThrowException("UPDATE lists SET severity_al = 'severe' WHERE id = 1247 AND pid = 41 AND type = 'allergy'");
echo "allergy 1247 (TP-LONG): severity severe\n";

QueryUtils::sqlStatementThrowException(
    "UPDATE lists SET enddate = '2027-06-30 00:00:00' WHERE id = 1242 AND pid = ? AND type = 'medication'",
    [TYPICAL_PID],
);
echo "medication 1242 (Lisinopril): end date 2027-06-30\n";

const HISTORY_PID = '39';
$followUps = QueryUtils::fetchSingleValue(
    "SELECT COUNT(*) AS c FROM form_encounter WHERE pid = ? AND reason = 'Same-day follow-up'",
    'c',
    [HISTORY_PID],
);
if ((int) $followUps === 0) {
    $puuid = QueryUtils::fetchSingleValue('SELECT uuid FROM patient_data WHERE pid = ?', 'uuid', [HISTORY_PID]);
    $result = (new EncounterService())->insertEncounter(
        \OpenEMR\Common\Uuid\UuidRegistry::uuidToString($puuid),
        [
            'date' => '2024-10-26',
            'reason' => 'Same-day follow-up',
            'pc_catid' => '9',
            'provider_id' => '6',
            'class_code' => 'AMB',
            'facility_id' => '3',
            'billing_facility' => '3',
            'sensitivity' => 'normal',
            'user' => 'admin',
            'group' => 'Default',
        ],
    );
    if (!$result->isValid() || $result->hasErrors()) {
        fwrite(STDERR, "encounter insert failed: " . json_encode($result->getValidationMessages()) . "\n");
        exit(1);
    }
}
echo "TP-HISTORY: second visit on 2024-10-26\n";

const PHYSICIAN = 'tp-physician';
$physicianId = QueryUtils::fetchSingleValue('SELECT id FROM users WHERE username = ?', 'id', [PHYSICIAN]);
if ($physicianId === null) {
    $physicianId = QueryUtils::sqlInsert(
        "INSERT INTO users (username, password, authorized, active, lname, fname, facility_id, calendar, cal_ui)"
        . " VALUES (?, 'NoLongerUsed', 1, 1, 'Physician', 'Test', 3, 1, 3)",
        [PHYSICIAN],
    );
    QueryUtils::sqlStatementThrowException(
        'INSERT INTO users_secure (id, username, password, last_update_password) VALUES (?, ?, ?, NOW())',
        [$physicianId, PHYSICIAN, password_hash('tp-physician-pass', PASSWORD_DEFAULT)],
    );
    QueryUtils::sqlStatementThrowException("INSERT INTO `groups` (name, user) VALUES ('Default', ?)", [PHYSICIAN]);
    AclExtended::setUserAro(['Physicians'], PHYSICIAN, 'Test', '', 'Physician');
}
echo "user " . PHYSICIAN . " (Physicians group)\n";

QueryUtils::sqlStatementThrowException("UPDATE lists SET outcome = 1, enddate = NULL WHERE id = 1248 AND pid = 41 AND type = 'allergy'");
QueryUtils::sqlStatementThrowException(
    "UPDATE lists SET enddate = '2027-12-31 00:00:00' WHERE id = 1249 AND pid = 41 AND type = 'allergy'",
);
echo "allergies 1248 (resolved, no end date) and 1249 (ends 2027-12-31) on TP-LONG\n";

$feeSheetProblems = QueryUtils::fetchSingleValue(
    "SELECT COUNT(*) AS c FROM lists WHERE pid = ? AND type = 'medical_problem' AND title = 'Fee sheet problem'",
    'c',
    [HISTORY_PID],
);
if ((int) $feeSheetProblems === 0) {
    // The Fee Sheet's own insert (interface/forms/fee_sheet/review/fee_sheet_queries.php:72): no activity.
    QueryUtils::sqlInsert(
        "INSERT INTO lists(date, begdate, type, occurrence, classification, pid, diagnosis, title, modifydate)"
        . " VALUES (NOW(), '2024-10-26', 'medical_problem', 0, 0, ?, '', 'Fee sheet problem', NOW())",
        [HISTORY_PID],
    );
}
echo "TP-HISTORY: Fee Sheet problem with no activity\n";

QueryUtils::sqlStatementThrowException("UPDATE patient_data SET mname = 'Quinn' WHERE pid = 41");
echo "TP-LONG: middle name Quinn\n";

$metforminDosage = QueryUtils::fetchSingleValue('SELECT COUNT(*) AS c FROM lists_medication WHERE list_id = 1241', 'c');
if ((int) $metforminDosage === 0) {
    QueryUtils::sqlInsert("INSERT INTO lists_medication (list_id, drug_dosage_instructions) VALUES (1241, '1 tablet twice daily')");
}
echo "Metformin (lists 1241): dosage 1 tablet twice daily\n";

$longTeam = QueryUtils::fetchSingleValue("SELECT id FROM care_teams WHERE pid = 41 AND team_name = 'LongLists team'", 'id');
if ($longTeam === null) {
    $longTeam = QueryUtils::sqlInsert(
        "INSERT INTO care_teams (uuid, pid, status, team_name, date_created, created_by, updated_by)"
        . " VALUES (?, 41, 'active', 'LongLists team', NOW(), 1, 1)",
        [UuidRegistry::getRegistryForTable('care_teams')->createUuid()],
    );
    QueryUtils::sqlInsert(
        "INSERT INTO care_team_member (care_team_id, user_id, role, provider_since, status, date_created, created_by, updated_by)"
        . " VALUES (?, 6, 'physician', '2025-01-15', 'active', NOW(), 1, 1)",
        [$longTeam],
    );
}
echo "TP-LONG: care team with Donna Lee\n";
