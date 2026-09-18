<?php

/**
 * Narrator for a site with no OpenAI key: every row fails loudly instead of
 * silently warming nothing.
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
 * Stand-in narrator used when OPENAI_API_KEY is not set. Lets the module
 * load and the panel render on a server with no AI configured; the first
 * attempt to brief throws, and the controller reports "not configured"
 * instead of crashing at boot.
 */
final readonly class UnconfiguredNarrator implements BriefingNarrator
{
    public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult
    {
        throw new \RuntimeException('AI is not configured on this server (OPENAI_API_KEY unset)');
    }
}
