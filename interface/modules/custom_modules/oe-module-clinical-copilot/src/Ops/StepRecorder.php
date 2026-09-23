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

/**
 * Collects timed Steps for one request. The pipeline wraps each stage in
 * measure(); the controller reads all() at the end to build the trace and
 * the log lines. One recorder per request — it is created fresh by the
 * factory, never shared.
 */
final class StepRecorder
{
    /**
     * Steps in the order they ran.
     *
     * @var list<Step>
     */
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
        // Wall-clock start (for the trace timeline) and a monotonic start (for
        // an accurate duration that clock adjustments cannot skew).
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

    /**
     * Records a step that was not run through measure() (e.g. an instant decision like a scope refusal).
     *
     * @param array<string, scalar|null> $detail
     */
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

    /** "LlmTimeout: ... (caused by ConnectException: ...)" — short class names, no stack trace. */
    public static function describe(\Throwable $e): string
    {
        // Class names only, never the message: exception messages can carry SQL
        // fragments, file paths or request text, none of which belongs in a
        // log line or a trace (log-field allowlist, W2_ARCHITECTURE.md).
        $text = (new \ReflectionClass($e))->getShortName() . ($e->getCode() !== 0 ? ' (code ' . $e->getCode() . ')' : '');
        $previous = $e->getPrevious();
        return $previous === null ? $text : $text . ' (caused by ' . (new \ReflectionClass($previous))->getShortName() . ')';
    }

    private static function elapsedMs(int|float $startedHr): int
    {
        return (int) round((hrtime(true) - $startedHr) / 1e6);
    }
}
