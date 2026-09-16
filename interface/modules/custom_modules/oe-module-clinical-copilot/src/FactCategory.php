<?php

/**
 * Fixed categories of chart facts the briefing can surface.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

enum FactCategory: string
{
    case Encounter = 'encounter';
    case MedicationNew = 'medication_new';
    case MedicationChanged = 'medication_changed';
    case MedicationActive = 'medication_active';
    case AllergyNew = 'allergy_new';
    case AllergyActive = 'allergy_active';
    case AllergyMedicationHit = 'allergy_medication_hit';
    case LabAbnormal = 'lab_abnormal';
    case LabDelta = 'lab_delta';
    case ProblemNew = 'problem_new';
    case Truncation = 'truncation';

    public function mustSurface(): bool
    {
        return match ($this) {
            self::MedicationNew,
            self::MedicationChanged,
            self::AllergyNew,
            self::AllergyMedicationHit,
            self::LabAbnormal,
            self::ProblemNew,
            self::Truncation => true,
            self::Encounter,
            self::MedicationActive,
            self::AllergyActive,
            self::LabDelta => false,
        };
    }
}
