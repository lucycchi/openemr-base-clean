<?php

/**
 * One entry read from an uploaded intake form (chief concern, medication,
 * allergy, family-history line) or a document-vs-chart mismatch flag, with
 * its citation into the document (week 2).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocType;

/**
 * A copilot_intake row read back for the fact assembler: the persisted
 * counterpart of Documents\IntakeItem. Where IntakeItem is what the sidecar
 * said, this is what the chart now holds, with the row id and the upload
 * date, and it knows how to describe itself as a fact sentence.
 */
final readonly class IntakeRecord
{
    public function __construct(
        public int $id,
        public int $documentId,
        /** chief_concern | medication | allergy | family_history | form_date | demographics_mismatch | patient_mismatch */
        public string $kind,
        public string $value,
        public ?string $detail,
        public \DateTimeImmutable $uploadedAt,
        public Citation $citation,
        // Which kind of upload the entry came from, and (for a medication list) the
        // date printed on it, so the wording never calls a pharmacy list an intake form.
        public DocType $docType = DocType::IntakeForm,
        public ?string $printedDate = null,
    ) {
    }

    /**
     * Which fact category this row surfaces under in the briefing, or null
     * for a row that is context only. The two mismatch kinds share one
     * category: to the clinician they are the same warning, "this document
     * may not be this patient's".
     */
    public function category(): ?FactCategory
    {
        return match ($this->kind) {
            'chief_concern' => FactCategory::IntakeChiefConcern,
            'medication' => FactCategory::IntakeMedication,
            'allergy' => FactCategory::IntakeAllergy,
            'family_history' => FactCategory::IntakeFamilyHistory,
            'demographics_mismatch', 'patient_mismatch' => FactCategory::DocumentMismatch,
            default => null, // form_date is context, not a fact
        };
    }

    /**
     * The fact sentence for this row. Each
     * wording names the intake form and its date, so the reader knows this
     * is what the patient wrote, not a verified chart entry. The detail
     * (dose, reaction, relative) is appended only when present.
     */
    public function describe(): string
    {
        if ($this->docType === DocType::MedicationList) {
            return $this->describeMedicationList();
        }
        return match ($this->kind) {
            'chief_concern' => sprintf('Reason for visit on the intake form (%s): %s', $this->uploadedAt->format('Y-m-d'), $this->value),
            'medication' => sprintf('Patient lists %s%s on the intake form (%s)', $this->value, $this->detail !== null && $this->detail !== '' ? ' ' . $this->detail : '', $this->uploadedAt->format('Y-m-d')),
            'allergy' => sprintf('Patient lists allergy to %s%s on the intake form (%s)', $this->value, $this->detail !== null && $this->detail !== '' ? ' (' . $this->detail . ')' : '', $this->uploadedAt->format('Y-m-d')),
            'family_history' => sprintf('Family history on the intake form: %s, %s', $this->detail ?? 'relative', $this->value),
            'demographics_mismatch' => sprintf('Intake form uploaded %s: %s', $this->uploadedAt->format('Y-m-d'), $this->value),
            'patient_mismatch' => sprintf('Lab report uploaded %s: %s', $this->uploadedAt->format('Y-m-d'), $this->value),
            default => $this->value,
        };
    }

    /**
     * Wording for an entry read from an outside medication list. It names
     * the list and the date printed on it (a months-old pharmacy printout
     * must not read as current); an undated list says so and gives the
     * upload date instead.
     */
    private function describeMedicationList(): string
    {
        $when = $this->printedDate !== null && $this->printedDate !== '' ? 'dated ' . $this->printedDate : 'undated, uploaded ' . $this->uploadedAt->format('Y-m-d');
        return match ($this->kind) {
            'medication' => sprintf('Outside medication list (%s) shows %s%s', $when, $this->value, $this->detail !== null && $this->detail !== '' ? ' ' . $this->detail : ''),
            'patient_mismatch' => sprintf('Medication list uploaded %s: %s', $this->uploadedAt->format('Y-m-d'), $this->value),
            default => $this->value,
        };
    }
}
