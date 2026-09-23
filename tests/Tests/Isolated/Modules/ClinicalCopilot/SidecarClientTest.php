<?php

/**
 * The sidecar client holds the reply to the run.response contract before
 * parsing it, and sends the correlation id on the wire.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarException;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class SidecarClientTest extends TestCase
{
    private const CORRELATION_ID = '763e45ddfc57b76bccc793509358ad89';

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private ?RequestInterface $sent = null;

    /**
     * A client whose HTTP layer answers with the given reply and records the
     * request it was sent, so a test can inspect both directions. Nothing
     * touches the network.
     *
     * @param array<string, mixed> $reply
     */
    private function client(array $reply): SidecarClient
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($reply, JSON_THROW_ON_ERROR))]));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->sent = $request;
        }));
        return new SidecarClient(new Client(['handler' => $stack]), new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', ''));
    }

    /**
     * The smallest run.response the contract accepts: one handoff, nothing
     * else. Tests start from it and break one thing.
     *
     * @return array<string, mixed>
     */
    private static function reply(): array
    {
        return [
            'correlation_id' => self::CORRELATION_ID,
            'extractions' => [],
            'chunks' => [],
            'handoffs' => [['from' => 'supervisor', 'to' => 'done', 'reason' => 'no_question', 'state_keys_changed' => [], 'ms' => 1]],
            'usage' => [],
        ];
    }

    /**
     * Pins: a valid reply becomes a RunResult, and the correlation id is
     * sent both as the X-Correlation-Id header and in the JSON body. A
     * failure means the sidecar's log lines could no longer be tied to the
     * PHP request that caused them.
     */
    public function testAConformingReplyIsParsedAndTheIdTravelsAsHeaderAndBody(): void
    {
        $result = $this->client(self::reply())->answer(self::CORRELATION_ID, str_repeat('0', 64), 'Does this patient need a statin?');
        self::assertSame(self::CORRELATION_ID, $result->correlationId);
        self::assertCount(1, $result->handoffs);
        $request = $this->sent;
        self::assertInstanceOf(RequestInterface::class, $request);
        self::assertSame(self::CORRELATION_ID, $request->getHeaderLine('X-Correlation-Id'));
        $body = json_decode((string) $request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(self::CORRELATION_ID, $body['correlation_id'] ?? null);
    }

    /**
     * Pins: the contract, not the typed parser, is the gate. A failure means
     * a reply with a reason code outside the contract's list would be
     * accepted and its free text could reach a log.
     */
    public function testAReplyTheContractRejectsIsRefusedEvenWhenTheParserWouldAcceptIt(): void
    {
        // The typed parser ignores unknown keys and accepts any reason string;
        // the contract does not, and the contract is the gate.
        $reply = self::reply();
        $reply['handoffs'] = [['from' => 'supervisor', 'to' => 'done', 'reason' => 'because', 'state_keys_changed' => [], 'ms' => 1]];
        $this->expectException(SidecarException::class);
        $this->expectExceptionMessage('schema_mismatch');
        $this->client($reply)->answer(self::CORRELATION_ID, str_repeat('0', 64), 'q');
    }

    /**
     * Pins: an extra top-level key is a contract breach. A failure means the
     * sidecar could smuggle a field (debug output, for instance) past PHP
     * without anyone noticing.
     */
    public function testAnUnknownTopLevelKeyIsRefused(): void
    {
        $this->expectException(SidecarException::class);
        $this->client(self::reply() + ['debug' => 'trace text'])->answer(self::CORRELATION_ID, str_repeat('0', 64), 'q');
    }

    public function testBriefSendsTriggerQueriesPatientAndFactsAndParsesEvidence(): void
    {
        $reply = self::reply() + ['evidence' => [['trigger_id' => 'lipids', 'chunks' => [['chunk_id' => 'aaaaaaaaaaaa', 'source_id' => 'acc-aha-2018-cholesterol', 'section' => 'T > S', 'quote' => 'A passage.', 'score' => 0.8]], 'applicable' => true, 'reason' => 'no restriction stated']]];
        $client = $this->client($reply);
        $trigger = new \OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger('lipids', 'Cholesterol management', 'statin indication', 'acc-aha-2018-cholesterol', ['0a1b2c3d'], []);

        $result = $client->brief(self::CORRELATION_ID, str_repeat('0', 64), [$trigger], ['LDL Cholesterol 165 mg/dL'], 55, 'M');

        self::assertNotNull($this->sent);
        $body = json_decode((string) $this->sent->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('brief', $body['mode']);
        self::assertNull($body['question']);
        self::assertSame([], $body['documents']);
        self::assertSame([['trigger_id' => 'lipids', 'query' => 'statin indication']], $body['queries']);
        self::assertSame(['age' => 55, 'sex' => 'M'], $body['patient']);
        self::assertSame(['LDL Cholesterol 165 mg/dL'], $body['facts']);
        self::assertCount(1, $result->evidence);
        self::assertSame('lipids', $result->evidence[0]['trigger_id']);
        self::assertTrue($result->evidence[0]['applicable']);
        self::assertSame('aaaaaaaaaaaa', $result->evidence[0]['chunks'][0]['chunk_id']);
    }

    public function testBriefWithUnknownDemographicsSendsNulls(): void
    {
        $client = $this->client(self::reply());
        $trigger = new \OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger('ckd', 'Kidney function', 'ckd staging', 'kdigo-2024-ckd', [], []);

        $client->brief(self::CORRELATION_ID, str_repeat('0', 64), [$trigger], [], null, null);

        $body = json_decode((string) $this->sent?->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['age' => null, 'sex' => null], $body['patient']);
        self::assertSame([], $body['facts']);
    }
}
