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

final readonly class IntakeItem
{
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
