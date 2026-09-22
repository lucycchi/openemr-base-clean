<?php

/**
 * Extraction status of an uploaded document (copilot_document.status).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

enum DocumentStatus: string
{
    case Stored = 'stored';
    case Extracted = 'extracted';
    case Failed = 'failed';
}
