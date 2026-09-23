<?php

/**
 * Base class for every failure of the language-model call.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

/**
 * Base class for every way a model call can fail. Each concrete subclass
 * (timeout, rate limit, refusal, schema mismatch, upstream error) supplies a
 * short user-facing label; the pipeline catches this base type and never has
 * to know which one it got. Subclasses have no fields of their own — the
 * class name IS the error code.
 */
abstract class LlmException extends \RuntimeException
{
    private int $attempts = 1;

    /** Short, safe-to-display reason, e.g. "AI summary unavailable: timed out". Never includes internals. */
    abstract public function statusLabel(): string;

    /** HTTP attempts made before giving up: 1, or 2 when the one retry was used. */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /** Fluent setter used by OpenAiClient right before throwing; returns $this. */
    public function withAttempts(int $attempts): static
    {
        $this->attempts = $attempts;
        return $this;
    }
}
