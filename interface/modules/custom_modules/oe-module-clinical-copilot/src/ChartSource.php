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

    /**
     * Week 2: unanchored extracted fields and unextracted rows from this patient's uploaded documents.
     *
     * @return list<UnverifiedExtraction>
     */
    public function unverifiedExtractions(PatientId $pid): array;

    /**
     * Week 2: cited intake-form entries and document-vs-chart mismatches for this patient's uploaded documents.
     *
     * @return list<IntakeRecord>
     */
    public function intakeRecords(PatientId $pid): array;

    /** Sex at birth and date of birth, for reference-interval selection and age gates; never a fact. */
    public function demographics(PatientId $pid): Demographics;
}
