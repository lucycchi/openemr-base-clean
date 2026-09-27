<?php

/**
 * Seeds everything the Standard REST API cannot create, for a stack where fixtures/seed.mjs and
 * fixtures/seed-encounters.mjs have run: the manual steps in TEST-PATIENTS.md and the parity-gap
 * data from seed-parity-gaps.php, found by patient name and record title instead of the dev
 * database's ids. Used to seed the droplet (ARC-05). Every patient is synthetic. Safe to re-run.
 *
 * Run as the apache user inside the openemr container:
 *   docker compose cp seed-demo.php openemr:/tmp/seed-demo.php
 *   docker compose exec -u apache openemr php /tmp/seed-demo.php
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

const ADMIN_USER = 1;
const FRED_STONE = 5;
const DONNA_LEE = 6;
const GREAT_CLINIC = 3;

function pidOf(string $fname, string $lname): int
{
    $pid = QueryUtils::fetchSingleValue('SELECT pid FROM patient_data WHERE fname = ? AND lname = ?', 'pid', [$fname, $lname]);
    if ($pid === null) {
        fwrite(STDERR, "missing patient {$fname} {$lname}: run fixtures/seed.mjs first\n");
        exit(1);
    }
    return (int) $pid;
}

function listId(int $pid, string $type, string $title): int
{
    $id = QueryUtils::fetchSingleValue('SELECT id FROM lists WHERE pid = ? AND type = ? AND title = ?', 'id', [$pid, $type, $title]);
    if ($id === null) {
        fwrite(STDERR, "missing {$type} '{$title}' for pid {$pid}\n");
        exit(1);
    }
    return (int) $id;
}

function encounterOf(int $pid, string $reason): int
{
    $encounter = QueryUtils::fetchSingleValue('SELECT encounter FROM form_encounter WHERE pid = ? AND reason = ?', 'encounter', [$pid, $reason]);
    if ($encounter === null) {
        fwrite(STDERR, "missing visit '{$reason}' for pid {$pid}: run fixtures/seed-encounters.mjs first\n");
        exit(1);
    }
    return (int) $encounter;
}

function exists(string $sql, array $binds): bool
{
    return (int) QueryUtils::fetchSingleValue($sql, 'c', $binds) > 0;
}

function prescription(int $pid, string $drug, int $active, int $onMedicationList, ?string $endDate, int $refills): int
{
    $id = QueryUtils::fetchSingleValue('SELECT id FROM prescriptions WHERE patient_id = ? AND drug = ?', 'id', [$pid, $drug]);
    if ($id !== null) {
        return (int) $id;
    }
    return (int) QueryUtils::sqlInsert(
        'INSERT INTO prescriptions (uuid, patient_id, provider_id, drug, quantity, refills, active, medication, end_date,'
        . ' start_date, date_added, date_modified, txDate, request_intent, request_intent_title, usage_category,'
        . " usage_category_title, dosage, user) VALUES (?, ?, ?, ?, '30', ?, ?, ?, ?, CURDATE(), NOW(), NOW(), CURDATE(),"
        . " 'order', 'Order', 'outpatient', 'Outpatient', '1', 'admin')",
        [UuidRegistry::getRegistryForTable('prescriptions')->createUuid(), $pid, ADMIN_USER, $drug, $refills, $active, $onMedicationList, $endDate],
    );
}

function listMedication(int $listId, string $dosage, ?string $intent, ?int $prescriptionId = null): void
{
    if (exists('SELECT COUNT(*) AS c FROM lists_medication WHERE list_id = ?', [$listId])) {
        return;
    }
    QueryUtils::sqlInsert(
        'INSERT INTO lists_medication (list_id, drug_dosage_instructions, usage_category, usage_category_title,'
        . ' request_intent, request_intent_title, prescription_id, is_primary_record) VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
        [
            $listId,
            $dosage,
            $intent === null ? null : 'outpatient',
            // The title columns are NOT NULL; strict MariaDB (the droplet's) refuses NULL, so an entry
            // with no intent stores '' as a lax server does. request_intent stays NULL, which FHIR reads as plan.
            $intent === null ? '' : 'Outpatient',
            $intent,
            $intent === null ? '' : 'Order',
            $prescriptionId,
        ],
    );
}

function careTeam(int $pid, string $name): int
{
    $id = QueryUtils::fetchSingleValue('SELECT id FROM care_teams WHERE pid = ? AND team_name = ?', 'id', [$pid, $name]);
    if ($id !== null) {
        return (int) $id;
    }
    return (int) QueryUtils::sqlInsert(
        "INSERT INTO care_teams (uuid, pid, status, team_name, date_created, created_by, updated_by) VALUES (?, ?, 'active', ?, NOW(), ?, ?)",
        [UuidRegistry::getRegistryForTable('care_teams')->createUuid(), $pid, $name, ADMIN_USER, ADMIN_USER],
    );
}

function member(int $teamId, ?int $userId, ?int $contactId, string $role, ?int $facility, string $since): void
{
    if (exists('SELECT COUNT(*) AS c FROM care_team_member WHERE care_team_id = ? AND (user_id <=> ?) AND (contact_id <=> ?)', [$teamId, $userId, $contactId])) {
        return;
    }
    QueryUtils::sqlInsert(
        'INSERT INTO care_team_member (care_team_id, user_id, contact_id, role, facility_id, provider_since, status, date_created,'
        . " created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), ?, ?)",
        [$teamId, $userId, $contactId, $role, $facility, $since, ADMIN_USER, ADMIN_USER],
    );
}

$typical = pidOf('Tessa', 'Typical');
$history = pidOf('Hugo', 'History');
$long = pidOf('Lena', 'LongLists');
$nka = pidOf('Nora', 'NoKnownAllergies');

// Staff: Donna Lee gets the test NPI, so the FHIR Practitioner endpoint serves her (BM-028).
QueryUtils::sqlStatementThrowException("UPDATE users SET npi = '1234567893' WHERE id = ? AND (npi IS NULL OR npi = '')", [DONNA_LEE]);

// TP-TYPICAL: allergy reaction and severity, problem occurrences (BM-017).
QueryUtils::sqlStatementThrowException("UPDATE lists SET reaction = 'hives', severity_al = 'moderate' WHERE id = ?", [listId($typical, 'allergy', 'Penicillin')]);
QueryUtils::sqlStatementThrowException('UPDATE lists SET occurrence = 1 WHERE id = ?', [listId($typical, 'medical_problem', 'Hyperlipidaemia')]);
QueryUtils::sqlStatementThrowException('UPDATE lists SET occurrence = 4 WHERE id = ?', [listId($typical, 'medical_problem', 'Essential hypertension')]);

// TP-TYPICAL medications: Atorvastatin marked Order (BM-019), Metformin dosage, Lisinopril ending 2027-06-30 (BM-044).
listMedication(listId($typical, 'medication', 'Atorvastatin 20 mg'), '1 tablet at night', 'order');
listMedication(listId($typical, 'medication', 'Metformin 500 mg'), '1 tablet twice daily', null);
QueryUtils::sqlStatementThrowException("UPDATE lists SET enddate = '2027-06-30 00:00:00' WHERE id = ?", [listId($typical, 'medication', 'Lisinopril 10 mg')]);

// TP-TYPICAL prescriptions: Omeprazole (not on the list, ends 2027-03-31, 2 refills) and Amlodipine,
// linked to its own medication-list entry (BM-020).
prescription($typical, 'Omeprazole 20 mg', 1, 0, '2027-03-31', 2);
$amlodipineRx = prescription($typical, 'Amlodipine 5 mg', 1, 1, null, 0);
if (!exists("SELECT COUNT(*) AS c FROM lists WHERE pid = ? AND type = 'medication' AND title = 'Amlodipine 5 mg'", [$typical])) {
    QueryUtils::sqlInsert(
        "INSERT INTO lists (uuid, date, type, title, pid, outcome, activity, user, groupname) VALUES (?, NOW(), 'medication', 'Amlodipine 5 mg', ?, 6, 1, 'admin', 'Default')",
        [UuidRegistry::getRegistryForTable('lists')->createUuid(), $typical],
    );
}
listMedication(listId($typical, 'medication', 'Amlodipine 5 mg'), '1 in', 'order', $amlodipineRx);

// TP-TYPICAL care team: Fred Stone at Great Clinic and a related person (BM-028, BM-031, BM-045).
$team = careTeam($typical, 'practitioner');
member($team, FRED_STONE, null, 'nurse_practitioner', GREAT_CLINIC, date('Y-m-d'));
$personId = QueryUtils::fetchSingleValue("SELECT id FROM person WHERE first_name = 'martha' AND last_name = 'mom'", 'id');
if ($personId === null) {
    $personId = QueryUtils::sqlInsert(
        "INSERT INTO person (uuid, first_name, middle_name, last_name, active, created_date, created_by, updated_by) VALUES (?, 'martha', 'm', 'mom', 1, NOW(), ?, ?)",
        [UuidRegistry::getRegistryForTable('person')->createUuid(), ADMIN_USER, ADMIN_USER],
    );
}
$contactId = QueryUtils::fetchSingleValue("SELECT id FROM contact WHERE foreign_table_name = 'person' AND foreign_id = ?", 'id', [$personId]);
if ($contactId === null) {
    $contactId = QueryUtils::sqlInsert("INSERT INTO contact (foreign_table_name, foreign_id) VALUES ('person', ?)", [$personId]);
}
member($team, null, (int) $contactId, '', null, date('Y-m-d'));

// TP-TYPICAL: Type 2 diabetes linked to two visits (BM-043).
$diabetes = listId($typical, 'medical_problem', 'Type 2 diabetes mellitus');
foreach (['Diabetes review', 'Annual physical'] as $reason) {
    $encounter = encounterOf($typical, $reason);
    if (!exists('SELECT COUNT(*) AS c FROM issue_encounter WHERE pid = ? AND list_id = ? AND encounter = ?', [$typical, $diabetes, $encounter])) {
        (new PatientIssuesService())->linkIssueToEncounter((string) $typical, (string) $encounter, (string) $diabetes, ADMIN_USER);
    }
}

// TP-HISTORY: a discontinued prescription (BM-024), a second visit on the same day, and a problem
// inserted the way the Fee Sheet does, with no activity (BM-051).
prescription($history, 'Lisinopril 5 mg', -1, 0, null, 0);
if (!exists("SELECT COUNT(*) AS c FROM form_encounter WHERE pid = ? AND reason = 'Same-day follow-up'", [$history])) {
    $puuid = QueryUtils::fetchSingleValue('SELECT uuid FROM patient_data WHERE pid = ?', 'uuid', [$history]);
    (new EncounterService())->insertEncounter(UuidRegistry::uuidToString($puuid), [
        'date' => '2024-10-26', 'reason' => 'Same-day follow-up', 'pc_catid' => '9', 'provider_id' => (string) DONNA_LEE,
        'class_code' => 'AMB', 'facility_id' => (string) GREAT_CLINIC, 'billing_facility' => (string) GREAT_CLINIC,
        'sensitivity' => 'normal', 'user' => 'admin', 'group' => 'Default',
    ]);
}
if (!exists("SELECT COUNT(*) AS c FROM lists WHERE pid = ? AND type = 'medical_problem' AND title = 'Fee sheet problem'", [$history])) {
    QueryUtils::sqlInsert(
        "INSERT INTO lists(date, begdate, type, occurrence, classification, pid, diagnosis, title, modifydate) VALUES (NOW(), '2024-10-26', 'medical_problem', 0, 0, ?, '', 'Fee sheet problem', NOW())",
        [$history],
    );
}

// TP-DECEASED: died 2025-11-02 (BM-005); set on the dev stack by a separate API call.
QueryUtils::sqlStatementThrowException(
    "UPDATE patient_data SET deceased_date = '2025-11-02 00:00:00' WHERE pid = ? AND deceased_date IS NULL",
    [pidOf('Dora', 'Deceased')],
);

// TP-NKA: the list was saved once and is now empty (BM-012).
if (!exists("SELECT COUNT(*) AS c FROM lists_touch WHERE pid = ? AND type = 'allergy'", [$nka])) {
    QueryUtils::sqlStatementThrowException("INSERT INTO lists_touch (pid, type, date) VALUES (?, 'allergy', NOW())", [$nka]);
}

// TP-LONG: a severe allergy, a resolved one, one ending 2027-12-31 (BM-016, BM-047); a middle name
// (BM-052); a care team with Donna Lee (NPI) since 2025-01-15.
QueryUtils::sqlStatementThrowException("UPDATE lists SET severity_al = 'severe' WHERE id = ?", [listId($long, 'allergy', 'Long-list allergen 01')]);
QueryUtils::sqlStatementThrowException('UPDATE lists SET outcome = 1, enddate = NULL WHERE id = ?', [listId($long, 'allergy', 'Long-list allergen 02')]);
QueryUtils::sqlStatementThrowException("UPDATE lists SET enddate = '2027-12-31 00:00:00' WHERE id = ?", [listId($long, 'allergy', 'Long-list allergen 03')]);
QueryUtils::sqlStatementThrowException("UPDATE patient_data SET mname = 'Quinn' WHERE pid = ?", [$long]);
member(careTeam($long, 'LongLists team'), DONNA_LEE, null, 'physician', null, '2025-01-15');

// A non-admin clinician in OpenEMR's Physicians group, for the demo (password tp-physician-pass).
if (!exists("SELECT COUNT(*) AS c FROM users WHERE username = 'tp-physician'", [])) {
    $physician = QueryUtils::sqlInsert(
        "INSERT INTO users (username, password, authorized, active, lname, fname, facility_id, calendar, cal_ui) VALUES ('tp-physician', 'NoLongerUsed', 1, 1, 'Physician', 'Test', ?, 1, 3)",
        [GREAT_CLINIC],
    );
    QueryUtils::sqlStatementThrowException(
        "INSERT INTO users_secure (id, username, password, last_update_password) VALUES (?, 'tp-physician', ?, NOW())",
        [$physician, password_hash('tp-physician-pass', PASSWORD_DEFAULT)],
    );
    QueryUtils::sqlStatementThrowException("INSERT INTO `groups` (name, user) VALUES ('Default', 'tp-physician')");
    AclExtended::setUserAro(['Physicians'], 'tp-physician', 'Test', '', 'Physician');
}

echo "seed-demo: done for pids {$typical}, {$history}, {$long}, {$nka}\n";
