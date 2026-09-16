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
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\LabRecord;
use OpenEMR\Modules\ClinicalCopilot\PatientId;

final class FakeChartSource implements ChartSource
{
    public int $reads = 0;
    /** @var list<EncounterRecord> */
    public array $encounters = [];
    /** @var list<MedicationRecord> */
    public array $medications = [];
    /** @var list<AllergyRecord> */
    public array $allergies = [];
    /** @var list<LabRecord> */
    public array $labs = [];

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
}
