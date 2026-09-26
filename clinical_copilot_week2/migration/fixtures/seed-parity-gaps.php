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

use OpenEMR\Common\Database\QueryUtils;
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
