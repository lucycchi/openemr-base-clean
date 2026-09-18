<?php

/**
 * Receipts sink for callers that do not persist (tests, ad-hoc runs).
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
 * "Null object" implementation: records nothing and never finds a receipt.
 * Used when the receipts table is not installed, so the rest of the code
 * can call the interface unconditionally instead of checking for null.
 */
final readonly class NullPrewarmReceipts implements PrewarmReceipts
{
    public function record(string $runId, PrewarmRow $row): void
    {
    }

    public function latestFor(string $ymd, PatientId $pid, string $openerUsername): ?PrewarmReceipt
    {
        return null;
    }
}
