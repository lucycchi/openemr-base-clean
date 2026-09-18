<?php

/**
 * Tracer that discards everything (observability not configured).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

/** Tracer that does nothing — used when Langfuse keys are not set, so callers never branch on "is tracing on?". */
final class NullTracer implements Tracer
{
    public function record(RequestTrace $trace): void
    {
    }
}
