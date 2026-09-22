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

final readonly class UsageEntry
{
    public function __construct(public string $model, public string $kind, public int $input, public int $output)
    {
        if (!in_array($kind, ['chat', 'embedding', 'rerank'], true)) {
            throw new SidecarException('schema_mismatch');
        }
    }

    /** @param array<mixed> $a  decoded JSON; every value is narrowed here */
    public static function fromArray(array $a): self
    {
        if (!is_string($a['model'] ?? null) || !is_string($a['kind'] ?? null) || !is_int($a['input'] ?? null) || !is_int($a['output'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        return new self($a['model'], $a['kind'], $a['input'], $a['output']);
    }
}
