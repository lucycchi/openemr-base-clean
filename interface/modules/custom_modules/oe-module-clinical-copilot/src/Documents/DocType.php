<?php

/**
 * Document types the Co-Pilot ingests (contracts: run.request doc_type).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

enum DocType: string
{
    case LabPdf = 'lab_pdf';
    case IntakeForm = 'intake_form';

    /** OpenEMR stock document category the file is filed under. */
    public function categoryName(): string
    {
        return match ($this) {
            self::LabPdf => 'Lab Report',
            self::IntakeForm => 'Patient Information',
        };
    }
}
