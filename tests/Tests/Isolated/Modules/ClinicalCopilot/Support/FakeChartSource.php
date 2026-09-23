<?php

/**
 * In-memory chart for tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use OpenEMR\Modules\ClinicalCopilot\AllergyRecord;
use OpenEMR\Modules\ClinicalCopilot\ChartSource;
use OpenEMR\Modules\ClinicalCopilot\Demographics;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\LabRecord;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\PendingOrderRecord;
use OpenEMR\Modules\ClinicalCopilot\ProblemRecord;
use OpenEMR\Modules\ClinicalCopilot\VitalRecord;

/**
 * ChartSource fed from public arrays. Tests build EncounterRecord /
 * MedicationRecord / etc. objects by hand and assign them; $reads counts
 * how many of the five accessors were called so a test can prove the
 * assembler reads each table exactly once.
 */
final class FakeChartSource implements ChartSource
{
    public ?Demographics $demographics = null;
    /** @var list<\OpenEMR\Modules\ClinicalCopilot\UnverifiedExtraction> */
    public array $unverified = [];
    /** @var list<\OpenEMR\Modules\ClinicalCopilot\IntakeRecord> */
    public array $intake = [];

    public int $reads = 0;
    /** @var list<EncounterRecord> */
    public array $encounters = [];
    /** @var list<MedicationRecord> */
    public array $medications = [];
    /** @var list<AllergyRecord> */
    public array $allergies = [];
    /** @var list<LabRecord> */
    public array $labs = [];
    /** @var list<ProblemRecord> */
    public array $problems = [];
    /** @var list<PendingOrderRecord> */
    public array $pendingOrders = [];
    /** @var list<VitalRecord> */
    public array $vitals = [];

    public function encounters(PatientId $pid): array
    {
        $this->reads++;
        return $this->encounters;
    }

    public function medications(PatientId $pid): array
    {
        $this->reads++;
        return $this->medications;
    }

    public function allergies(PatientId $pid): array
    {
        $this->reads++;
        return $this->allergies;
    }

    public function labs(PatientId $pid): array
    {
        $this->reads++;
        return $this->labs;
    }

    public function problems(PatientId $pid): array
    {
        $this->reads++;
        return $this->problems;
    }

    /** @return list<\OpenEMR\Modules\ClinicalCopilot\UnverifiedExtraction> */
    /** @return list<VitalRecord> */
    public function vitals(PatientId $pid): array
    {
        return $this->vitals;
    }

    /** @return list<PendingOrderRecord> */
    public function pendingOrders(PatientId $pid): array
    {
        return $this->pendingOrders;
    }

    public function unverifiedExtractions(PatientId $pid): array
    {
        return $this->unverified;
    }

    /** @return list<\OpenEMR\Modules\ClinicalCopilot\IntakeRecord> */
    public function intakeRecords(PatientId $pid): array
    {
        return $this->intake;
    }

    public function demographics(PatientId $pid): Demographics
    {
        return $this->demographics ?? Demographics::unknown();
    }
}
