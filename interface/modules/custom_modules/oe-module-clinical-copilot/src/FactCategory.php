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

/**
 * What kind of thing a Fact is. Drives two decisions: how the prompt groups
 * facts, and — via mustSurface() — which ones the OmissionGuard refuses to
 * let the model leave out. String-backed because the category is emitted in
 * the JSON payload to the panel.
 */
enum FactCategory: string
{
    case PriorVisit = 'prior_visit';
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
    /** Week 2: a value or row from an uploaded document that could not be verified against the page. */
    case ExtractionUnverified = 'extraction_unverified';

    /**
     * Must this fact appear in the briefing even if the model skipped it?
     * "Yes" for anything new, changed or abnormal; "no" for background
     * context the model may reasonably summarise away. The `match` lists
     * every case with no default, so adding a new category is a compile-time
     * (PHPStan) error until someone decides which side it belongs on.
     */
    public function mustSurface(): bool
    {
        return match ($this) {
            self::MedicationNew,
            self::MedicationChanged,
            self::AllergyNew,
            self::AllergyMedicationHit,
            self::LabAbnormal,
            self::ProblemNew,
            self::Truncation,
            self::ExtractionUnverified => true,
            self::PriorVisit,
            self::Encounter,
            self::MedicationActive,
            self::AllergyActive,
            self::LabDelta => false,
        };
    }
}
