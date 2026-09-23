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
    case PriorVisitPlan = 'prior_visit_plan';
    case PriorVisitAssessment = 'prior_visit_assessment';
    case Encounter = 'encounter';
    case MedicationNew = 'medication_new';
    case MedicationChanged = 'medication_changed';
    case MedicationActive = 'medication_active';
    case MedicationStopped = 'medication_stopped';
    case AllergyNew = 'allergy_new';
    case AllergyActive = 'allergy_active';
    case AllergyMedicationHit = 'allergy_medication_hit';
    case LabCritical = 'lab_critical';
    case LabAbnormal = 'lab_abnormal';
    case LabDelta = 'lab_delta';
    case LabNormal = 'lab_normal';
    case VitalAbnormal = 'vital_abnormal';
    case VitalDelta = 'vital_delta';
    case ProblemNew = 'problem_new';
    case ProblemResolved = 'problem_resolved';
    case LabPending = 'lab_pending';
    case Truncation = 'truncation';
    /** Week 2: a value or row from an uploaded document that could not be verified against the page. */
    case ExtractionUnverified = 'extraction_unverified';
    /** Week 2, intake form: the reason for visit as the patient wrote it. */
    case IntakeChiefConcern = 'intake_chief_concern';
    /** Week 2, intake form: a medication the patient listed (with dose/frequency as written). */
    case IntakeMedication = 'intake_med';
    /** Week 2, intake form: an allergy the patient listed. */
    case IntakeAllergy = 'intake_allergy';
    /** Week 2, intake form: a family-history line. */
    case IntakeFamilyHistory = 'intake_family_history';
    /** Week 2: the name, date of birth, sex or phone printed on a document disagrees with the chart. */
    case DocumentMismatch = 'document_mismatch';

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
            self::MedicationStopped,
            self::LabPending,
            self::AllergyNew,
            self::AllergyMedicationHit,
            self::LabCritical,
            self::LabAbnormal,
            self::VitalAbnormal,
            self::ProblemNew,
            self::Truncation,
            self::ExtractionUnverified,
            self::IntakeMedication,
            self::IntakeAllergy,
            self::DocumentMismatch,
            self::PriorVisitPlan => true,
            self::PriorVisit,
            self::PriorVisitAssessment,
            self::Encounter,
            self::MedicationActive,
            self::AllergyActive,
            self::IntakeChiefConcern,
            self::IntakeFamilyHistory,
            self::LabNormal,
            self::ProblemResolved,
            self::VitalDelta,
            self::LabDelta => false,
        };
    }
}
