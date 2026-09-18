<?php

/**
 * StepRecorder keeps the ordered, timed steps of one request, including why a step failed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * StepRecorder::measure(): returns the closure's result, records steps in
 * order with detail derived from the result, and on exception records the
 * error text (including the previous exception) and rethrows the original.
 */
final class StepRecorderTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testStepsAreRecordedInOrderWithDetailFromTheResult(): void
    {
        $steps = new StepRecorder();

        $facts = explode(',', 'a,b,c');
        $first = $steps->measure('assemble', static fn() => $facts, static fn(array $r) => ['facts' => count($r)]);
        $steps->measure('verify', static fn() => 'ok');

        self::assertSame($facts, $first);
        self::assertSame(['assemble', 'verify'], array_map(static fn($s) => $s->name, $steps->all()));
        self::assertSame(['facts' => 3], $steps->all()[0]->detail);
        self::assertNull($steps->all()[0]->error);
        self::assertGreaterThanOrEqual(0, $steps->all()[0]->durationMs);
        self::assertSame([], $steps->failed());
    }

    public function testAFailingStepIsRecordedWithItsCauseAndRethrown(): void
    {
        $steps = new StepRecorder();
        $cause = new \RuntimeException('HTTP 503 from upstream');
        $boom = new \LogicException('Upstream HTTP 503', 503, $cause);
        $caught = null;

        try {
            $steps->measure('llm.briefing', static fn() => throw $boom);
        } catch (\LogicException $e) {
            $caught = $e;
        }

        self::assertSame($boom, $caught);

        $failed = $steps->failed();
        self::assertCount(1, $failed);
        self::assertSame('llm.briefing', $failed[0]->name);
        self::assertSame('LogicException: Upstream HTTP 503 (caused by RuntimeException: HTTP 503 from upstream)', $failed[0]->error);
        self::assertSame(['step' => 'llm.briefing', 'ms' => $failed[0]->durationMs, 'error' => $failed[0]->error], $failed[0]->toLogContext());
    }
}
