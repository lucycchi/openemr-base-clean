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

/**
 * The two kinds of document the Co-Pilot can read. An "enum" is a closed
 * list: a variable of this type can only ever hold one of the cases below,
 * so a typo like "lab_pfd" cannot travel through the code. It is a "backed"
 * enum (each case has a string value) because the value is written to the
 * copilot_document.doc_type column and sent to the sidecar as doc_type.
 */
enum DocType: string
{
    case LabPdf = 'lab_pdf';
    case IntakeForm = 'intake_form';

    /**
     * OpenEMR stock document category the file is filed under. OpenEMR's
     * own Documents tab groups files by category, so an uploaded lab report
     * appears where staff already look for lab reports. "match" here has no
     * default branch on purpose: adding a DocType without a category is a
     * static-analysis error, not a silent gap.
     */
    public function categoryName(): string
    {
        return match ($this) {
            self::LabPdf => 'Lab Report',
            self::IntakeForm => 'Patient Information',
        };
    }
}
