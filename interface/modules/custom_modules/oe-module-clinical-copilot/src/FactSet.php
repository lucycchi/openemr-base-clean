<?php

/**
 * The complete, deterministic set of facts for one patient briefing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class FactSet
{
    /** @var array<string, Fact> */
    private array $byId;

    /** @param list<Fact> $facts */
    public function __construct(array $facts)
    {
        $byId = [];
        foreach ($facts as $fact) {
            $byId[$fact->id] = $fact;
        }
        $this->byId = $byId;
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function get(string $id): Fact
    {
        return $this->byId[$id] ?? throw new \OutOfBoundsException("Unknown fact id $id");
    }

    /** @return list<Fact> */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
