<?php

/**
 * Sink for request traces; Langfuse at runtime, null when unconfigured.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

/**
 * Sink for one finished request's trace (timings, steps, tokens, cost).
 * LangfuseTracer ships it to Langfuse; NullTracer discards it when Langfuse
 * is not configured. Called once, at the very end of a request.
 */
interface Tracer
{
    public function record(RequestTrace $trace): void;
}
