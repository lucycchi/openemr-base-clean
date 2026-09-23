<?php

/**
 * One cited intake-form entry: a chief concern, a medication, an allergy or a family-history line (contracts/intake-form.schema.json).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

/**
 * One line the patient wrote on the intake form, flattened to a uniform
 * shape so the persistence and fact layers do not need to know the form's
 * nested structure. kind says which section it came from; fieldPath is the
 * JSON pointer into the sidecar's extraction (e.g. /medications/0/name);
 * value is the main text (drug name, allergen, condition, complaint);
 * detail is the secondary text (dose and frequency, reaction, relative).
 * Each item is built by IntakeExtraction::fromArray, not from JSON directly.
 */
final readonly class IntakeItem
{
    /**
     * Refuses an unknown kind or an empty value, so a blank line on the form
     * can never become a fact that says nothing.
     */
    public function __construct(
        public string $kind,
        public string $fieldPath,
        public string $value,
        public ?string $detail,
        public Citation $citation,
    ) {
        if (!in_array($kind, ['chief_concern', 'medication', 'allergy', 'family_history', 'form_date'], true) || $value === '') {
            throw new SidecarException('schema_mismatch');
        }
    }
}
