<?php

/**
 * One boolean score attached to an existing trace after the request that made
 * the trace has finished (the physician's rating of a briefing).
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
 * $id makes the write idempotent: Langfuse replaces a score with the same id,
 * so a second rating by the same user on the same briefing replaces the first,
 * as the database row does. $note is fixed text written by code, never
 * anything a user typed (scores leave the building).
 */
final readonly class Score
{
    public function __construct(
        public string $id,
        public string $traceId,
        public string $name,
        public bool $value,
        public ?string $note = null,
    ) {
    }
}
