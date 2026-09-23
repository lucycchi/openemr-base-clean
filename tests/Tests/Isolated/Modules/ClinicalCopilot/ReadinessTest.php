<?php

/**
 * Readiness: real dependency probes, cached, bounded, with Langfuse as degraded-not-down.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\Ops\Readiness;
use OpenEMR\Modules\ClinicalCopilot\Ops\ReadinessCache;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FixedClock;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Readiness with scripted probes and a fixed clock. Covers the three
 * states (ready / not_ready / degraded), the 60-second cache (probes must
 * not re-run at +30s, must at +61s — this is what stops ready.php being
 * used to burn OpenAI quota), and that a probe throwing is reported as a
 * failed dependency rather than crashing the endpoint.
 */
final class ReadinessTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @var \ArrayObject<string, int> */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        $this->calls = new \ArrayObject();
    }

    private function callsTo(string $name): int
    {
        return $this->calls[$name] ?? 0;
    }

    /**
     * Builds the three named probes; each counts its own invocations in
     * $this->calls and returns null (ok) or "<name> unreachable".
     * @return array<string, callable(): ?string>
     */
    private function probes(bool $db = true, bool $openai = true, bool $langfuse = true, bool $sidecar = true): array
    {
        $probe = fn(string $name, bool $ok) => function () use ($name, $ok): ?string {
            $this->calls[$name] = ($this->calls[$name] ?? 0) + 1;
            return $ok ? null : "$name unreachable";
        };
        return ['database' => $probe('database', $db), 'openai' => $probe('openai', $openai), 'langfuse' => $probe('langfuse', $langfuse), 'sidecar' => $probe('sidecar', $sidecar)];
    }

    public function testSidecarDownIsDegradedButReady(): void
    {
        // Week 2: no sidecar means no extraction and facts-only answers, but briefings still work.
        $readiness = new Readiness($this->probes(sidecar: false), new ReadinessCache(), new FixedClock(new DateTimeImmutable('2026-09-15 10:00:00')));
        $report = $readiness->check();
        self::assertTrue($report->ready);
        self::assertSame(200, $report->httpStatus());
        self::assertSame(['sidecar'], $report->degraded);
        self::assertSame('degraded', $report->toArray()['status']);
        self::assertSame('sidecar unreachable', $report->dependencies['sidecar']);
    }

    public function testAllDependenciesUpIsReady(): void
    {
        $readiness = new Readiness($this->probes(), new ReadinessCache(), new FixedClock(new DateTimeImmutable('2026-09-15 10:00:00')));

        $report = $readiness->check();

        self::assertTrue($report->ready);
        self::assertSame(200, $report->httpStatus());
        self::assertSame(['database' => 'ok', 'openai' => 'ok', 'langfuse' => 'ok', 'sidecar' => 'ok'], $report->dependencies);
        self::assertSame([], $report->degraded);
    }

    public function testDatabaseDownIsNotReady(): void
    {
        $readiness = new Readiness($this->probes(db: false), new ReadinessCache(), new FixedClock(new DateTimeImmutable('2026-09-15 10:00:00')));

        $report = $readiness->check();

        self::assertFalse($report->ready);
        self::assertSame(503, $report->httpStatus());
        self::assertSame('database unreachable', $report->dependencies['database']);
    }

    public function testOpenAiDownIsNotReady(): void
    {
        $readiness = new Readiness($this->probes(openai: false), new ReadinessCache(), new FixedClock(new DateTimeImmutable('2026-09-15 10:00:00')));

        self::assertFalse($readiness->check()->ready);
    }

    public function testLangfuseDownIsDegradedButReady(): void
    {
        $readiness = new Readiness($this->probes(langfuse: false), new ReadinessCache(), new FixedClock(new DateTimeImmutable('2026-09-15 10:00:00')));

        $report = $readiness->check();

        self::assertTrue($report->ready);
        self::assertSame(200, $report->httpStatus());
        self::assertSame(['langfuse'], $report->degraded);
        self::assertSame('langfuse unreachable', $report->dependencies['langfuse']);
    }

    public function testResultIsCachedForSixtySecondsSoProbesAreNotAnAbuseVector(): void
    {
        $cache = new ReadinessCache();
        $t0 = new DateTimeImmutable('2026-09-15 10:00:00');
        $first = (new Readiness($this->probes(), $cache, new FixedClock($t0)))->check();
        $second = (new Readiness($this->probes(), $cache, new FixedClock($t0->modify('+30 seconds'))))->check();
        self::assertSame(1, $this->callsTo('openai'));
        self::assertFalse($first->fromCache);
        self::assertTrue($second->fromCache);
        self::assertSame(30, $second->ageSeconds);

        $third = (new Readiness($this->probes(), $cache, new FixedClock($t0->modify('+61 seconds'))))->check();
        self::assertFalse($third->fromCache);
        self::assertSame(2, $this->callsTo('openai'));
    }

    public function testProbeExceptionIsReportedNotThrown(): void
    {
        $probes = $this->probes();
        $probes['openai'] = fn() => throw new \RuntimeException('boom');
        $readiness = new Readiness($probes, new ReadinessCache(), new FixedClock(new DateTimeImmutable('2026-09-15 10:00:00')));

        $report = $readiness->check();

        self::assertFalse($report->ready);
        self::assertSame('openai probe failed', $report->dependencies['openai']);
    }
}
