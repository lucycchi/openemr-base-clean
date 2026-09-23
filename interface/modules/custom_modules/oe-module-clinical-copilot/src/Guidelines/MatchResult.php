<?php

/**
 * The outcome of one trigger matcher: whether it matched and which facts or
 * plain-text reasons made it match.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

final readonly class MatchResult
{
    /**
     * @param list<string> $factIds
     * @param list<string> $reasons
     */
    public function __construct(public bool $matched, public array $factIds = [], public array $reasons = [])
    {
    }

    public static function none(): self
    {
        return new self(false);
    }

    public static function of(bool $matched): self
    {
        return new self($matched);
    }
}
