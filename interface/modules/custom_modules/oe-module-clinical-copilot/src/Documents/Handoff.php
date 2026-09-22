<?php

/**
 * One supervisor routing step (contracts/handoff.schema.json).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

final readonly class Handoff
{
    /** @param list<string> $stateKeysChanged */
    public function __construct(public string $from, public string $to, public string $reason, public array $stateKeysChanged, public int $ms)
    {
    }

    /** @param array<mixed> $a  decoded JSON; every value is narrowed here */
    public static function fromArray(array $a): self
    {
        if (!is_string($a['from'] ?? null) || !is_string($a['to'] ?? null) || !is_string($a['reason'] ?? null) || !is_int($a['ms'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        $keys = is_array($a['state_keys_changed'] ?? null) ? array_values(array_filter($a['state_keys_changed'], 'is_string')) : [];
        return new self($a['from'], $a['to'], $a['reason'], $keys, $a['ms']);
    }

    /** @return array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'reason' => $this->reason, 'state_keys_changed' => $this->stateKeysChanged, 'ms' => $this->ms];
    }
}
