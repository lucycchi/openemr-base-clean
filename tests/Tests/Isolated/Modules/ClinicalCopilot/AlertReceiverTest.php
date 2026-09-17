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

    private const SIGNING = 'lf-whs-test-signing-secret';
    private const NOW = 1_789_600_000;

    private function receiver(string $secret = self::SECRET, string $signing = ''): AlertReceiver
    {
        return new AlertReceiver($secret, $signing, static fn(): int => self::NOW);
    }

    private static function sign(string $body, int $ts = self::NOW, string $secret = self::SIGNING): string
    {
        return 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    }

    public function testAValidLangfuseSignatureIsAcceptedWithoutAToken(): void
    {
        $body = '{"alert":{"name":"p95"}}';
        $event = $this->receiver('', self::SIGNING)->receive('', $body, self::sign($body));
        self::assertSame('p95', $event->name);
    }

    public function testATamperedBodyFailsTheSignature(): void
    {
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Invalid signature');
        $this->receiver('', self::SIGNING)->receive('', '{"alert":{"name":"forged"}}', self::sign('{"alert":{"name":"p95"}}'));
    }

    public function testAStaleSignatureIsRejectedAsAReplay(): void
    {
        $body = '{"alert":{"name":"p95"}}';
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Signature expired');
        $this->receiver('', self::SIGNING)->receive('', $body, self::sign($body, self::NOW - 600));
    }

    public function testASignatureWithTheWrongSecretIsRejected(): void
    {
        $body = '{}';
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Invalid signature');
        $this->receiver('', self::SIGNING)->receive('', $body, self::sign($body, self::NOW, 'other'));
    }

    public function testASignatureIsIgnoredWhenNoSigningSecretIsConfiguredAndTokenStillWorks(): void
    {
        $body = '{"name":"x"}';
        $event = $this->receiver(self::SECRET, '')->receive(self::SECRET, $body, self::sign($body));
        self::assertSame('x', $event->name);
    }

    public function testWhenBothAreConfiguredEitherCredentialIsEnough(): void
    {
        $body = '{"name":"x"}';
        self::assertSame('x', $this->receiver(self::SECRET, self::SIGNING)->receive(self::SECRET, $body, '')->name);
        self::assertSame('x', $this->receiver(self::SECRET, self::SIGNING)->receive('', $body, self::sign($body))->name);
        $this->expectException(AlertRejected::class);
        $this->receiver(self::SECRET, self::SIGNING)->receive('wrong', $body, 't=1,v1=bad');
    }

    public function testRejectsWhenNoSecretIsConfigured(): void
    {
        $this->expectException(AlertRejected::class);
        $this->expectExceptionMessage('Alert webhook is not configured');
        $this->receiver('', '')->receive('', '{}', '');
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

    public function testParsesTheRealLangfuseMonitorAlertPayload(): void
    {
        $event = $this->receiver()->receive(self::SECRET, json_encode([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'timestamp' => '2026-09-17T10:30:00Z',
            'type' => 'monitor-alert',
            'payload' => [
                'monitorId' => 'monitor_abc123',
                'severity' => 'ALERT',
                'message' => ['title' => 'p95 latency crossed alert threshold', 'body' => 'p95 latency is 18211 ms (threshold: 15000 ms)'],
            ],
        ], JSON_THROW_ON_ERROR), '');
        self::assertSame('p95 latency crossed alert threshold', $event->name);
        self::assertSame('ALERT', $event->severity);
        self::assertSame('monitor-alert', $event->type);
        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $event->id);
        self::assertSame(18211.0, $event->value);
        self::assertSame(15000.0, $event->threshold);
        self::assertSame('monitor_abc123', $event->monitorId);
        self::assertSame('alert=p95 latency crossed alert threshold severity=ALERT value=18211 threshold=15000 type=monitor-alert monitor=monitor_abc123', $event->auditComment());
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
