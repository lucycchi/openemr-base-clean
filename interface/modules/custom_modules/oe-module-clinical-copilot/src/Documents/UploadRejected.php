<?php

/**
 * An upload refused at the boundary (too large, not a PDF, unsupported type); the code is shown to the user.
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
 * Thrown by DocumentStore::store when a file is refused before anything is
 * written. The reason is a short code (too_large, not_a_pdf) rather than a
 * sentence: the controller maps it to the user-facing message and returns
 * it in the JSON body, so the wording lives in one place.
 */
final class UploadRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
