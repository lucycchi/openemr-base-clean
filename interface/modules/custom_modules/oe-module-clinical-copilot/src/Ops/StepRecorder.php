<?php

/**
 * Collects the ordered steps of one request so the log line and the trace
 * can answer "what ran, in what order, how long, and what failed".
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

final class StepRecorder
{
    /** @var list<Step> */
    private array $steps = [];

    /**
     * Runs $fn as a timed step. $detail receives $fn's result and returns the
     * scalars worth recording about it. A thrown exception is recorded as the
     * step's error (class and message; the previous exception's message too,
     * since that is usually the transport-level "why") and rethrown.
     *
     * @template T
     * @param \Closure(): T $fn
     * @param null|\Closure(T): array<string, scalar|null> $detail
     * @return T
     */
    public function measure(string $name, \Closure $fn, ?\Closure $detail = null): mixed
    {
        $startedAtMs = (int) round(microtime(true) * 1000);
        $started = hrtime(true);
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->steps[] = new Step($name, $startedAtMs, self::elapsedMs($started), self::describe($e), []);
            throw $e;
        }
        $this->steps[] = new Step($name, $startedAtMs, self::elapsedMs($started), null, $detail === null ? [] : $detail($result));
        return $result;
    }

    /** @param array<string, scalar|null> $detail */
    public function add(string $name, int $startedAtMs, int $durationMs, ?string $error = null, array $detail = []): void
    {
        $this->steps[] = new Step($name, $startedAtMs, $durationMs, $error, $detail);
    }

    /** @return list<Step> */
    public function all(): array
    {
        return $this->steps;
    }

    /** @return list<Step> */
    public function failed(): array
    {
        return array_values(array_filter($this->steps, static fn(Step $s): bool => $s->error !== null));
    }

    public static function describe(\Throwable $e): string
    {
        $text = (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
        $previous = $e->getPrevious();
        return $previous === null ? $text : $text . ' (caused by ' . (new \ReflectionClass($previous))->getShortName() . ': ' . $previous->getMessage() . ')';
    }

    private static function elapsedMs(int|float $startedHr): int
    {
        return (int) round((hrtime(true) - $startedHr) / 1e6);
    }
}
