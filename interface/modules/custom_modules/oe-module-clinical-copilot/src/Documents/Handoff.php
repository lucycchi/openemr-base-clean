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

/**
 * One step of the sidecar's routing: which worker handed control to which,
 * why, what it wrote, and how long it took. The supervisor appends one per
 * transition, so the list is the audit trail of a multi-agent run. PHP
 * records each as a Langfuse span and the panel shows the list in its
 * "Why this answer" drawer.
 *
 * JSON field -> property: from -> from, to -> to, reason -> reason (a fixed
 * code, never free text, so it is safe to log), state_keys_changed ->
 * stateKeysChanged, ms -> ms.
 */
final readonly class Handoff
{
    /** @param list<string> $stateKeysChanged */
    public function __construct(public string $from, public string $to, public string $reason, public array $stateKeysChanged, public int $ms)
    {
    }

    /**
     * Builds a step from decoded JSON. The four scalar fields are required;
     * the list of changed keys is optional and any non-string entries in it
     * are dropped rather than refused.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        if (!is_string($a['from'] ?? null) || !is_string($a['to'] ?? null) || !is_string($a['reason'] ?? null) || !is_int($a['ms'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        $keys = is_array($a['state_keys_changed'] ?? null) ? array_values(array_filter($a['state_keys_changed'], 'is_string')) : [];
        return new self($a['from'], $a['to'], $a['reason'], $keys, $a['ms']);
    }

    /**
     * The step in its wire shape again, for the response body and the trace.
     *
     * @return array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}
     */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'reason' => $this->reason, 'state_keys_changed' => $this->stateKeysChanged, 'ms' => $this->ms];
    }
}
