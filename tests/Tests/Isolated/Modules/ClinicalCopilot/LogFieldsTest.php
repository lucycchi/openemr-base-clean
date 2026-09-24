<?php

/**
 * LogFields: the runtime log-field allowlist CorrelatedLogger enforces.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenEMR\Modules\ClinicalCopilot\Ops\CorrelatedLogger;
use OpenEMR\Modules\ClinicalCopilot\Ops\LogFields;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class LogFieldsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testAllowlistedKeysPassThroughUnchanged(): void
    {
        $context = ['pid' => 30, 'status' => 'extracted', 'steps' => [['step' => 'x', 'ms' => 1, 'error' => null]]];
        self::assertSame($context, LogFields::filter($context));
    }

    public function testAKeyOffTheListIsDroppedAndNamedButItsValueNeverWritten(): void
    {
        $filtered = LogFields::filter(['pid' => 30, 'patient_name' => 'Test Zeta', 'raw_text' => 'chest tightness']);
        self::assertSame(['pid' => 30, 'dropped_fields' => ['patient_name', 'raw_text']], $filtered);
        self::assertStringNotContainsString('Test Zeta', json_encode($filtered, JSON_THROW_ON_ERROR));
    }

    /**
     * Pins the runtime guarantee: whatever a controller passes, the line written through
     * CorrelatedLogger carries only allowlisted keys, plus the correlation id.
     */
    public function testCorrelatedLoggerWritesOnlyAllowlistedKeys(): void
    {
        $handler = new TestHandler();
        (new CorrelatedLogger(new Logger('t', [$handler]), 'corr-1234'))->notice('copilot document extracted', ['document_id' => 7, 'filename' => 'zeta-labs.pdf']);
        $context = $handler->getRecords()[0]->context;
        self::assertSame(['document_id' => 7, 'correlation_id' => 'corr-1234', 'dropped_fields' => ['filename']], $context);
    }
}
