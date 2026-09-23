<?php

/**
 * One paid call the sidecar made (contracts/run.response usage[]).
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
 * One billable call the sidecar made during a run, so PHP can price the
 * whole run (Pricing) and show one Langfuse generation per call.
 *
 * JSON field -> property: model -> model, kind -> kind (chat, embedding or
 * rerank), input -> input tokens, output -> output tokens. A rerank counts
 * one search as input=1.
 */
final readonly class UsageEntry
{
    /**
     * Refuses a kind outside the three the contract allows, so an unexpected
     * call type is noticed at the boundary rather than downstream.
     */
    public function __construct(public string $model, public string $kind, public int $input, public int $output)
    {
        if (!in_array($kind, ['chat', 'embedding', 'rerank'], true)) {
            throw new SidecarException('schema_mismatch');
        }
    }

    /**
     * Builds an entry from decoded JSON; all four fields are required and
     * the token counts must be integers.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        if (!is_string($a['model'] ?? null) || !is_string($a['kind'] ?? null) || !is_int($a['input'] ?? null) || !is_int($a['output'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        return new self($a['model'], $a['kind'], $a['input'], $a['output']);
    }
}
