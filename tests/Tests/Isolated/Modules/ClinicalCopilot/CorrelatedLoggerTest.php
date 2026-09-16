<?php

/**
 * CorrelatedLogger stamps the request's correlation id into every log entry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Ops\CorrelatedLogger;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class CorrelatedLoggerTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testEveryEntryCarriesTheCorrelationId(): void
    {
        $inner = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $entries = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $logger = new CorrelatedLogger($inner, 'corr-xyz');
        $logger->info('hello', ['action' => 'brief']);
        $logger->error('boom');

        self::assertSame(['action' => 'brief', 'correlation_id' => 'corr-xyz'], $inner->entries[0]['context']);
        self::assertSame(['correlation_id' => 'corr-xyz'], $inner->entries[1]['context']);
        self::assertSame('error', $inner->entries[1]['level']);
    }
}
