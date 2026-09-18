<?php

/**
 * Read-only access to a patient chart; implemented over OpenEMR services at runtime.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * Read-only view of one patient's chart, already filtered to what the current
 * user may see. Five typed lists; nothing else about OpenEMR's schema leaks
 * past this boundary. OpenEmrChartSource is the real one (SQL); tests use
 * FakeChartSource with hand-built records.
 */
interface ChartSource
{
    /** @return list<EncounterRecord> */
    public function encounters(PatientId $pid): array;

    /** @return list<MedicationRecord> */
    public function medications(PatientId $pid): array;

    /** @return list<AllergyRecord> */
    public function allergies(PatientId $pid): array;

    /** @return list<LabRecord> numeric results only */
    public function labs(PatientId $pid): array;

    /** @return list<ProblemRecord> active problems only */
    public function problems(PatientId $pid): array;
}
