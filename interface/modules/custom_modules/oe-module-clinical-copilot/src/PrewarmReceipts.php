<?php

/**
 * Where pre-warm receipts are kept and read back at chart open.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

interface PrewarmReceipts
{
    public function record(string $runId, PrewarmRow $row): void;

    /**
     * The receipt to compare against for a chart open on $ymd: the one
     * warmed as the opener if there is one, else the latest for the patient.
     */
    public function latestFor(string $ymd, PatientId $pid, string $openerUsername): ?PrewarmReceipt;
}
