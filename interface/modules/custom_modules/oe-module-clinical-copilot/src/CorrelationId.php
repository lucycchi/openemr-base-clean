<?php

/**
 * One id per request, carried through logs, tool calls, and the LLM call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * Makes a random 32-hex-char id for one request. It is written into every
 * log line, the Langfuse trace, and the response headers, so a single id
 * ties together everything that happened for one chart open or question.
 */
final class CorrelationId
{
    /** 16 random bytes, hex-encoded — cryptographically random, no timestamp leak. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
