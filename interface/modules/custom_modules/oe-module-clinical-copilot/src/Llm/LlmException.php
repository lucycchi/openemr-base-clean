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

abstract class LlmException extends \RuntimeException
{
    private int $attempts = 1;

    abstract public function statusLabel(): string;

    /** HTTP attempts made before giving up: 1, or 2 when the one retry was used. */
    public function attempts(): int
    {
        return $this->attempts;
    }

    public function withAttempts(int $attempts): static
    {
        $this->attempts = $attempts;
        return $this;
    }
}
