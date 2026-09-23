<?php

/**
 * Did this chart open hit its pre-warm receipt, and if not, why? Pure: the
 * receipt and the just-assembled facts in, a result and a reason out.
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
 * Answers, at chart open, "did the pre-warm pay off, and if not, why?".
 * Compares the receipt written by last night's sweep against the facts
 * assembled right now. A hit means the cache key will match; a miss is
 * classified into a WarmMissReason so the dashboard can distinguish
 * "the chart genuinely changed" from "we warmed as the wrong user" or
 * "someone bumped the prompt version". Pure comparison, no I/O.
 */
final readonly class WarmOutcome
{
    // The only services the sensitivity filter can add or remove facts for;
    // a difference confined to these (or to a category flip on an unchanged
    // value, which a moved "since" boundary causes) is the viewer, not the chart.
    private const VIEWER_SENSITIVE_SERVICES = ['EncounterService', 'ObservationLabService'];

    /**
     * @param list<string> $newFactIds
     * @param list<string> $goneFactIds
     */
    private function __construct(
        public bool $hit,
        public ?WarmMissReason $reason,
        public ?PrewarmReceipt $receipt,
        public array $newFactIds,
        public array $goneFactIds,
    ) {
    }

    /**
     * Decision order: no receipt -> prompt changed -> model changed -> hash
     * equal (hit) -> diff the fact lines to decide viewer-vs-chart drift.
     */
    public static function evaluate(?PrewarmReceipt $receipt, AssembledFacts $assembled, string $openerUsername, string $promptVersion, string $model): self
    {
        if ($receipt === null) {
            return new self(false, WarmMissReason::NoRow, null, [], []);
        }
        // Prompt version and model are part of the cache key, so a receipt
        // warmed under another one cannot be the row this open will read
        // even when the facts are identical.
        if ($receipt->promptVersion !== $promptVersion) {
            return new self(false, WarmMissReason::PromptVersion, $receipt, [], []);
        }
        if ($receipt->model !== $model) {
            return new self(false, WarmMissReason::ModelChanged, $receipt, [], []);
        }
        if ($receipt->factsHash === $assembled->facts()->hash()) {
            return new self(true, null, $receipt, [], []);
        }

        // Hash differs. Diff the recorded fact lines against today's to find
        // which ids were added, removed or changed.
        $warmed = self::byId($receipt->factLines);
        $now = self::byId($assembled->facts()->lines());
        $changedIds = [];
        foreach (array_keys($now + $warmed) as $id) {
            if (($now[$id] ?? null) !== ($warmed[$id] ?? null)) {
                $changedIds[] = $id;
            }
        }
        $newIds = array_values(array_diff(array_keys($now), array_keys($warmed)));
        $goneIds = array_values(array_diff(array_keys($warmed), array_keys($now)));

        // If a *different* user is opening and every difference is one the
        // sensitivity filter could cause, blame the viewer, not the chart.
        $reason = $openerUsername !== $receipt->providerUsername && self::onlyViewerEffects($changedIds, $warmed, $now)
            ? WarmMissReason::ViewerDiffers
            : WarmMissReason::HashDrift;
        return new self(false, $reason, $receipt, $newIds, $goneIds);
    }

    /**
     * Parses FactSet::lines() ("id\tservice\tcategory\tvalue") into a map keyed by id.
     * @param list<string> $lines
     * @return array<string, array{service: string, category: string, value: string}>
     */
    private static function byId(array $lines): array
    {
        $byId = [];
        foreach ($lines as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) !== 4) {
                continue;
            }
            [$id, $service, $category, $value] = $parts;
            $byId[$id] = ['service' => $service, 'category' => $category, 'value' => $value];
        }
        return $byId;
    }

    /**
     * @param list<string> $changedIds
     * @param array<string, array{service: string, category: string, value: string}> $warmed
     * @param array<string, array{service: string, category: string, value: string}> $now
     */
    private static function onlyViewerEffects(array $changedIds, array $warmed, array $now): bool
    {
        // Every changed id must be either from a viewer-sensitive service, or a
        // category-only change with identical value text. One real change -> false.
        foreach ($changedIds as $id) {
            $service = ($now[$id] ?? $warmed[$id])['service'];
            if (in_array($service, self::VIEWER_SENSITIVE_SERVICES, true)) {
                continue;
            }
            $categoryOnly = isset($now[$id], $warmed[$id]) && $now[$id]['value'] === $warmed[$id]['value'];
            if (!$categoryOnly) {
                return false;
            }
        }
        return true;
    }

    /**
     * Scalar-only so it can ride on the request trace's metadata as-is; fact
     * id lists are comma-joined.
     *
     * @return array<string, string|null>
     */
    public function toLogContext(): array
    {
        return [
            'warm_result' => $this->hit ? 'hit' : 'miss',
            'warm_reason' => $this->reason?->value,
            'warm_run_id' => $this->receipt?->runId,
            'warm_provider' => $this->receipt?->providerUsername,
            'warm_generated_at' => $this->receipt?->createdAt,
            'warm_new_fact_ids' => implode(',', $this->newFactIds),
            'warm_gone_fact_ids' => implode(',', $this->goneFactIds),
        ];
    }
}
