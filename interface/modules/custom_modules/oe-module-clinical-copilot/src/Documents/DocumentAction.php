<?php

/**
 * Actions public/documents.php accepts.
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
 * The "action" field of a documents.php request, as a closed list:
 * upload a PDF, run the extraction on one stored document, or list the
 * patient's documents. Backed by strings because the value arrives as a
 * form field and DocumentRequest::fromBag turns it into a case with
 * tryFrom(), which returns null (a 400) for anything not listed here.
 */
enum DocumentAction: string
{
    case Upload = 'upload';
    case Extract = 'extract';
    case List = 'list';
}
