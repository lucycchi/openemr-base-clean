<?php

/**
 * A sidecar call that did not produce a usable run.response; the code is one of run.error's codes plus schema_mismatch and unavailable.
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
 * Every way a sidecar call can fail, reduced to one short code the caller
 * can branch on and log: unavailable (could not connect), timeout, the
 * codes the sidecar itself returns in a run.error body, and
 * schema_mismatch (the reply did not conform to the contract or a typed
 * parser refused it). The original exception, when there is one, is kept
 * as the "previous" so a log can still show the cause; only the code is
 * ever shown to a user.
 */
final class SidecarException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct('sidecar: ' . $errorCode, 0, $previous);
    }
}
