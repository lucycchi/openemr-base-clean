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
 * log line (CorrelatedLogger), the Langfuse trace, the response headers,
 * the OpenAI call (`user` field + header), the sidecar request (body +
 * header, then every sidecar log line), the Cohere call and the
 * copilot_document row, so a single id ties together everything that
 * happened for one chart open, question or document. PHP is the only
 * origin: the sidecar echoes, never mints. The full boundary table and the
 * trade-offs: clinical_copilot_week2/ENGINEERING_REQUIREMENTS.md section 2.
 */
final class CorrelationId
{
    /** 16 random bytes, hex-encoded — cryptographically random, no timestamp leak. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
