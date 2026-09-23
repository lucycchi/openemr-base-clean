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

/**
 * The life of an uploaded document, as recorded in copilot_document.status:
 *
 *   stored -> extracted   (the sidecar read it and the rows were written)
 *   stored -> failed      (the sidecar refused it; failure_reason says why)
 *   failed -> stored      (a retry resets it before sending the bytes again)
 *
 * The sidecar's own reply is only ever "extracted" or "failed"; "stored" is
 * PHP's word for "uploaded, not yet read", which is why ExtractionResult
 * refuses a reply that says stored.
 */
enum DocumentStatus: string
{
    case Stored = 'stored';
    case Extracted = 'extracted';
    case Failed = 'failed';
}
