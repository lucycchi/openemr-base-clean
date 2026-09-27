<?php

/**
 * The lab trend lines the panel draws (contracts/trends.schema.json).
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
 * Groups a patient's lab results into one line per test, for the panel's
 * trend chart. Built from the same LabRecord list FactAssembler already
 * read (ACL checked, hidden encounters removed), so the chart shows nothing
 * the fact table could not. It is display data, never a fact: it does not
 * enter the facts hash, the prompt or the briefing cache key.
 *
 * Which results make a line, and why each rule exists:
 *   - numeric values only: "<5" or "positive" has no height on a chart;
 *   - a LOINC code is required: grouping rows with no code would join
 *     unrelated tests that happen to share a unit (glucose and LDL, both mg/dL);
 *   - no undated rows: the chart source stands a missing date in as
 *     1970-01-01, which would stretch the time axis by fifty years;
 *   - no unit-mismatch rows: the printed unit is not the one the LOINC map
 *     expects, so the value cannot share an axis (it still shows as a fact);
 *   - one line per LOINC and unit (spelling-insensitive, "mg/dL" = "mg/dl"),
 *     so a real unit change starts a new line rather than a mixed one;
 *   - the same value twice on one day is one draw recorded twice (typed into
 *     the chart and read from the PDF): the cited copy is kept;
 *   - a line needs two points, or there is no trend to show.
 * At most MAX_SERIES lines, each with its MAX_POINTS latest points; the
 * number of lines left out is `more`. Which lines are shown: most recently
 * resulted first; on the same day (one panel resulting many tests at once),
 * a line with a point from an uploaded document first, so a report just
 * uploaded is always drawn; then the tests clinicians most often follow over
 * time (PRIORITY); then by code, so the order is the same on every request.
 */
final readonly class LabTrends
{
    public const MAX_SERIES = 6;
    public const MAX_POINTS = 8;

    /**
     * Commonly trended tests, most useful first: HbA1c, LDL (calculated,
     * direct, and the map's code), creatinine and eGFR, potassium, glucose,
     * hemoglobin, TSH, total cholesterol, HDL, triglycerides.
     */
    private const PRIORITY = ['4548-4', '2089-1', '13457-7', '18262-6', '2160-0', '33914-3', '62238-1', '2823-3', '2345-7', '718-7', '3016-3', '2093-3', '2085-9', '2571-8'];

    /** @param list<LabSeries> $series */
    public function __construct(
        public array $series,
        public int $more,
    ) {
    }

    public static function none(): self
    {
        return new self([], 0);
    }

    /** @param list<LabRecord> $labs in any order */
    public static function fromLabs(array $labs): self
    {
        $sentinel = '1970-01-01';
        // Each kept result travels as a pair: the record (for its id, name and
        // unit) and the point drawn for it (its value, known here to be a number).
        /** @var array<string, list<array{LabRecord, TrendPoint}>> $groups */
        $groups = [];
        foreach ($labs as $l) {
            $value = $l->value;
            if ($value === null || $l->loinc === '' || $l->unitMismatch || $l->date->format('Y-m-d') === $sentinel) {
                continue;
            }
            $groups[$l->loinc . '|' . strtolower(trim($l->units))][] = [$l, new TrendPoint($l->date, $value, $l->citation)];
        }

        $series = [];
        foreach ($groups as $rows) {
            // Oldest first; the id breaks ties so the order is the same on every request.
            usort($rows, static fn(array $a, array $b): int => [$a[0]->date, $a[0]->id] <=> [$b[0]->date, $b[0]->id]);
            $kept = array_slice(self::withoutRepeatedDraws($rows), -self::MAX_POINTS);
            if (count($kept) < 2) {
                continue;
            }
            [$latest] = $kept[count($kept) - 1];
            $points = array_map(static fn(array $pair): TrendPoint => $pair[1], $kept);
            $series[] = new LabSeries($latest->loinc, $latest->name, trim($latest->units), $points);
        }

        usort($series, static fn(LabSeries $a, LabSeries $b): int => [$b->latest()->date->format('Y-m-d'), self::cited($b), self::rank($a), $a->loinc] <=> [$a->latest()->date->format('Y-m-d'), self::cited($a), self::rank($b), $b->loinc]);
        return new self(array_slice($series, 0, self::MAX_SERIES), max(0, count($series) - self::MAX_SERIES));
    }

    /** 1 when any point on the line was read from an uploaded document. */
    private static function cited(LabSeries $s): int
    {
        foreach ($s->points as $p) {
            if ($p->citation !== null) {
                return 1;
            }
        }
        return 0;
    }

    /** Position in PRIORITY, or after every listed test. */
    private static function rank(LabSeries $s): int
    {
        $i = array_search($s->loinc, self::PRIORITY, true);
        return $i === false ? count(self::PRIORITY) : $i;
    }

    /**
     * Drops a result that repeats a value already kept for the same day,
     * preferring the copy that carries a document citation.
     *
     * @param list<array{LabRecord, TrendPoint}> $rows oldest first
     * @return list<array{LabRecord, TrendPoint}>
     */
    private static function withoutRepeatedDraws(array $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            $point = $row[1];
            $repeat = null;
            foreach ($kept as $i => [, $k]) {
                if ($k->date->format('Y-m-d') === $point->date->format('Y-m-d') && abs($k->value - $point->value) < 1e-9) {
                    $repeat = $i;
                    break;
                }
            }
            if ($repeat === null) {
                $kept[] = $row;
            } elseif ($kept[$repeat][1]->citation === null && $point->citation !== null) {
                $kept[$repeat] = $row;
            }
        }
        return $kept;
    }

    /** @return array{series: list<array{loinc: string, name: string, unit: string, points: list<array{date: string, value: float, citation: array<string, mixed>|null}>}>, more: int} */
    public function toArray(): array
    {
        return [
            'series' => array_map(static fn(LabSeries $s): array => $s->toArray(), $this->series),
            'more' => $this->more,
        ];
    }
}
