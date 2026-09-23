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

/**
 * The complete set of facts for one briefing, indexed by id. Two important
 * derived values live here: lines() (a human-readable record for receipts)
 * and hash() (the content fingerprint that feeds the cache key). Insertion
 * order is preserved for all(), but hash() is order-independent.
 */
final readonly class FactSet
{
    /**
     * Fact id -> Fact.
     *
     * @var array<string, Fact>
     */
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

    /** Throws on an unknown id; callers should check has() first unless the id came from this set. */
    public function get(string $id): Fact
    {
        return $this->byId[$id] ?? throw new \OutOfBoundsException("Unknown fact id $id");
    }

    /** @return list<Fact> */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /**
     * One line per fact, sorted, with the service named so a later diff can
     * tell a sensitivity-filtered encounter from a real chart change. This is
     * the receipt's record of what was warmed; it is not what is hashed.
     *
     * @return list<string> "id\tservice\tcategory\tvalue"
     */
    public function lines(): array
    {
        $lines = array_map(fn(Fact $f) => $f->id . "\t" . $f->service . "\t" . $f->category->value . "\t" . $f->value, $this->byId);
        sort($lines);
        return $lines;
    }

    // Order-independent so a re-assembly that merely reorders rows is a cache hit.
    // Note this deliberately omits the service name that lines() includes: an
    // encounter hidden by ACL and a genuinely missing encounter hash the same.
    public function hash(): string
    {
        $lines = array_map(fn(Fact $f) => $f->id . "\t" . $f->category->value . "\t" . $f->value, $this->byId);
        sort($lines);
        return hash('sha256', implode("\n", $lines));
    }
}
