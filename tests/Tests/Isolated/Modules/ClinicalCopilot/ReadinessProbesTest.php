<?php

/**
 * The real readiness probes against a mocked HTTP client: what each network
 * dependency's answer (200, 503, transport failure) becomes in the report,
 * and that no upstream detail leaks into the reason strings.
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
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Ops\ReadinessProbes;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class ReadinessProbesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @param list<Response|ConnectException> $answers */
    private static function probe(string $name, array $answers, ?Config $config = null): ?string
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($answers)), 'http_errors' => false]);
        $config ??= new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', 'pk', 'sk');
        return ReadinessProbes::probes($config, $client)[$name]();
    }

    public function testTheProbesAreTheFourDependenciesTheReportNames(): void
    {
        $config = new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', 'pk', 'sk');
        self::assertSame(['contracts', 'database', 'openai', 'langfuse', 'sidecar'], array_keys(ReadinessProbes::probes($config, new Client())));
    }

    public function testTheContractsProbeIsOkWhenTheValidatorAndTheFilesArePresent(): void
    {
        // In this test process the library is installed and the files are on disk: the probe passes.
        // The failure branches (library missing, files unreadable) are what a production build without them reports.
        self::assertNull(self::probe('contracts', []));
    }

    public function testSidecarReadyIsOkNotReadyIsAReasonAndUnreachableIsAReason(): void
    {
        self::assertNull(self::probe('sidecar', [new Response(200, [], '{"status":"ready"}')]));
        self::assertSame('sidecar not ready', self::probe('sidecar', [new Response(503, [], '{"status":"not_ready"}')]));
        self::assertSame('sidecar unavailable', self::probe('sidecar', [new Response(500, [], 'Internal Server Error: /app/secret.py line 3')]));
        self::assertSame('sidecar unreachable', self::probe('sidecar', [new ConnectException('cURL error 7: connection refused to 10.0.0.9', new Request('GET', 'http://copilot-sidecar:8000/ready'))]));
    }

    public function testOpenAiReasonsNeverRevealKeyOrQuotaState(): void
    {
        self::assertNull(self::probe('openai', [new Response(200, [], '{"data":[]}')]));
        self::assertSame('openai unavailable', self::probe('openai', [new Response(401, [], '{"error":"invalid api key sk-test"}')]));
        self::assertSame('openai unavailable', self::probe('openai', [new Response(429, [], '{"error":"quota exceeded"}')]));
        self::assertSame('openai not configured', self::probe('openai', [], new Config('', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '')));
    }

    public function testLangfuseUnconfiguredOrDownIsAFixedReason(): void
    {
        self::assertNull(self::probe('langfuse', [new Response(200, [], 'OK')]));
        self::assertSame('langfuse unavailable', self::probe('langfuse', [new Response(502)]));
        self::assertSame('langfuse not configured', self::probe('langfuse', [], new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '')));
    }
}
