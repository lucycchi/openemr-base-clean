<?php

/**
 * KEY_METRICS.md metric 7: chat adoption per patient encounter.
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
 * Pure arithmetic over AdoptionCounts, so it is tested without a database.
 *
 * An encounter counts as used when the physician who is its provider asked
 * at least one question on it. Questions on someone else's encounter, or on
 * an encounter outside the window, do not count: the numerator is always a
 * subset of the denominator, so the share never passes 100.
 */
final readonly class AdoptionReport
{
    public function __construct(private AdoptionCounts $counts)
    {
    }

    /** @return list<AdoptionRow> one per physician per day with at least one encounter, by day then physician */
    public function days(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        foreach ($this->perEncounter($from, $to) as $physician => $days) {
            foreach ($days as $day => $asksPerEncounter) {
                $rows[] = self::row($physician, $day, $asksPerEncounter);
            }
        }
        usort($rows, static fn(AdoptionRow $a, AdoptionRow $b): int => [$a->period, $a->physician] <=> [$b->period, $b->physician]);
        return $rows;
    }

    /** @return list<AdoptionRow> one per physician per ISO week (for example 2026-W39), by week then physician */
    public function weeks(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $grouped = [];
        foreach ($this->perEncounter($from, $to) as $physician => $days) {
            foreach ($days as $day => $asksPerEncounter) {
                $week = (new \DateTimeImmutable($day))->format('o-\WW');
                $grouped[$physician][$week] = ($grouped[$physician][$week] ?? []) + $asksPerEncounter;
            }
        }
        $rows = [];
        foreach ($grouped as $physician => $weeks) {
            foreach ($weeks as $week => $asksPerEncounter) {
                $rows[] = self::row($physician, $week, $asksPerEncounter);
            }
        }
        usort($rows, static fn(AdoptionRow $a, AdoptionRow $b): int => [$a->period, $a->physician] <=> [$b->period, $b->physician]);
        return $rows;
    }

    /** Every physician over the whole window, for the headline number. */
    public function total(\DateTimeImmutable $from, \DateTimeImmutable $to): AdoptionRow
    {
        $all = [];
        foreach ($this->perEncounter($from, $to) as $physician => $days) {
            foreach ($days as $asksPerEncounter) {
                foreach ($asksPerEncounter as $encounter => $asks) {
                    $all[$physician . '#' . $encounter] = $asks;
                }
            }
        }
        return self::row('all', $from->format('Y-m-d') . '..' . $to->modify('-1 day')->format('Y-m-d'), $all);
    }

    /**
     * physician => day => encounter => questions the physician asked on it (0 when none).
     *
     * @return array<string, array<string, array<int|string, int>>>
     */
    private function perEncounter(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $asks = $this->counts->asks($from, $to);
        $out = [];
        foreach ($this->counts->encounters($from, $to) as $physician => $days) {
            foreach ($days as $day => $encounters) {
                foreach (array_unique($encounters) as $encounter) {
                    $out[$physician][$day][$encounter] = $asks[$physician][$encounter] ?? 0;
                }
            }
        }
        return $out;
    }

    /** @param array<int|string, int> $asksPerEncounter */
    private static function row(string $physician, string $period, array $asksPerEncounter): AdoptionRow
    {
        $used = array_filter($asksPerEncounter, static fn(int $n): bool => $n > 0);
        return new AdoptionRow($physician, $period, count($asksPerEncounter), count($used), array_sum($used));
    }
}
