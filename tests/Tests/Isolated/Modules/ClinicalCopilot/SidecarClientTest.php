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

    /** @param array<string, mixed> $reply */
    private function client(array $reply): SidecarClient
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($reply, JSON_THROW_ON_ERROR))]));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->sent = $request;
        }));
        return new SidecarClient(new Client(['handler' => $stack]), new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', ''));
    }

    /** @return array<string, mixed> */
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

    public function testAnUnknownTopLevelKeyIsRefused(): void
    {
        $this->expectException(SidecarException::class);
        $this->client(self::reply() + ['debug' => 'trace text'])->answer(self::CORRELATION_ID, str_repeat('0', 64), 'q');
    }
}
