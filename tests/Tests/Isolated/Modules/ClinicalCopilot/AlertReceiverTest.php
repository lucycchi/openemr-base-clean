<?php

/**
 * AlertReceiver: the webhook Langfuse alerts POST to. Authenticates with a
 * shared secret, parses the payload into an AlertEvent, never throws on
 * unexpected shapes.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Ops\AlertReceiver;
use OpenEMR\Modules\ClinicalCopilot\Ops\AlertRejected;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class AlertReceiverTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private const SECRET = 'a-long-random-secret';

    private function receiver(string $secret = self::SECRET): AlertReceiver
    {
        return new AlertReceiver($secret);
    }

    public function testRejectsWhenNoSecretIsConfigured(): void
    {
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Alert webhook is not configured');
        $this->receiver('')->receive('', '{}');
    }

    public function testRejectsAWrongToken(): void
    {
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Invalid token');
        $this->receiver()->receive('wrong', '{}');
    }

    public function testRejectsABodyThatIsNotAJsonObject(): void
    {
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Body is not a JSON object');
        $this->receiver()->receive(self::SECRET, '[1,2]');
    }

    public function testParsesTheFieldsAnAlertCarriesAndKeepsTheRest(): void
    {
        $event = $this->receiver()->receive(self::SECRET, json_encode([
            'id' => 'evt_1',
            'type' => 'alert.triggered',
            'timestamp' => '2026-09-16T12:00:00Z',
            'alert' => ['name' => 'copilot p95 latency', 'severity' => 'critical'],
            'metric' => ['name' => 'duration_ms', 'value' => 18211, 'threshold' => 15000],
            'projectId' => 'p1',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('copilot p95 latency', $event->name);
        self::assertSame('critical', $event->severity);
        self::assertSame('alert.triggered', $event->type);
        self::assertSame('evt_1', $event->id);
        self::assertSame(18211.0, $event->value);
        self::assertSame(15000.0, $event->threshold);
        self::assertSame('p1', $event->payload['projectId']);
    }

    public function testFallsBackToTopLevelNameAndSeverityAndToUnknown(): void
    {
        $event = $this->receiver()->receive(self::SECRET, '{"name":"error rate","severity":"warning","value":"0.07"}');
        self::assertSame('error rate', $event->name);
        self::assertSame('warning', $event->severity);
        self::assertSame(0.07, $event->value);
        self::assertNull($event->threshold);

        $bare = $this->receiver()->receive(self::SECRET, '{"hello":"world"}');
        self::assertSame('unknown', $bare->name);
        self::assertSame('unknown', $bare->severity);
        self::assertSame('unknown', $bare->type);
    }

    public function testLogContextHasNoRawPayloadOnlyBoundedSummary(): void
    {
        $event = $this->receiver()->receive(self::SECRET, json_encode(['name' => 'tool failure rate', 'blob' => str_repeat('x', 10000)], JSON_THROW_ON_ERROR));
        $context = $event->toLogContext();
        self::assertSame('tool failure rate', $context['alert']);
        self::assertArrayHasKey('payload_keys', $context);
        self::assertSame(['name', 'blob'], $context['payload_keys']);
        self::assertLessThan(2048, strlen(json_encode($context, JSON_THROW_ON_ERROR)));
    }

    public function testAuditCommentIsOneLine(): void
    {
        $event = $this->receiver()->receive(self::SECRET, '{"alert":{"name":"p95"},"severity":"critical","value":16000,"threshold":15000}');
        self::assertSame('alert=p95 severity=critical value=16000 threshold=15000 type=unknown', $event->auditComment());
    }
}
