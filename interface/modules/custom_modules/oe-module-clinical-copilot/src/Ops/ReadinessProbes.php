<?php

/**
 * Real dependency probes for ready.php, bounded to 2s each.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Contracts;

/**
 * Builds the production Readiness checker with its four probes. Each probe
 * is a closure returning null when healthy or a short reason string when
 * not. Kept out of Readiness itself so that class stays pure and testable
 * with fake probes; probes() is public so the real closures can be tested
 * against a mocked HTTP client (ReadinessProbesTest).
 */
final class ReadinessProbes
{
    public static function readiness(?Config $config = null, ?ClientInterface $http = null): Readiness
    {
        // Short timeouts: a readiness endpoint must answer quickly even when a
        // dependency is hanging. http_errors=false so a 4xx/5xx is a status
        // code to inspect rather than an exception.
        $config ??= Config::fromEnvironment();
        $http ??= new Client(['timeout' => 2.0, 'connect_timeout' => 1.0, 'http_errors' => false]);
        return new Readiness(self::probes($config, $http), FileReadinessStore::inSiteDirectory(OEGlobalsBag::getInstance()->getString('OE_SITE_DIR')), ServiceContainer::getClock());
    }

    /**
     * The four probes: OpenEMR's database, the model provider, the
     * observability backend, and (Week 2) the sidecar's own readiness.
     *
     * @return array<string, callable(): ?string>
     */
    public static function probes(Config $config, ClientInterface $http): array
    {
        return [
            // The module's own runtime pieces: the JSON Schema validator every
            // sidecar reply is checked with, and the contract files it reads.
            // A production image built without them answers 500 on every
            // extraction and question; this probe makes that a not_ready
            // instead (found on the 2026-09-23 deploy, when the validator was
            // still a dev-only dependency).
            'contracts' => static function (): ?string {
                if (!class_exists(\JsonSchema\Validator::class)) {
                    return 'contract validator missing';
                }
                try {
                    Contracts::schema('run.response');
                    Contracts::schema('llm.briefing.output');
                } catch (\RuntimeException | \InvalidArgumentException | \JsonException) {
                    return 'contract files unreadable';
                }
                return null;
            },
            'database' => static function (): ?string {
                try {
                    $row = QueryUtils::querySingleRow('SELECT 1', [], false);
                } catch (\RuntimeException) {
                    return 'database query failed';
                }
                return $row === false ? 'database returned nothing' : null;
            },
            'openai' => static function () use ($http, $config): ?string {
                if (!$config->hasOpenAi()) {
                    return 'openai not configured';
                }
                try {
                    $code = $http->request('GET', 'https://api.openai.com/v1/models', ['headers' => ['Authorization' => 'Bearer ' . $config->openAiApiKey]])->getStatusCode();
                } catch (GuzzleException) {
                    return 'openai unreachable';
                }
                // Anonymous callers get no upstream status: a 401/429 would reveal key validity or quota state.
                return $code === 200 ? null : 'openai unavailable';
            },
            'langfuse' => static function () use ($http, $config): ?string {
                if (!$config->hasLangfuse()) {
                    return 'langfuse not configured';
                }
                try {
                    $code = $http->request('GET', rtrim($config->langfuseHost, '/') . '/api/public/health')->getStatusCode();
                } catch (GuzzleException) {
                    return 'langfuse unreachable';
                }
                return $code === 200 ? null : 'langfuse unavailable';
            },
            // Week 2: the sidecar checks its own local dependencies (contracts
            // mounted, LOINC map, retrieval index, tesseract, OpenAI key) on
            // GET /ready and answers 503 when one is missing. Optional here,
            // like Langfuse: without it briefings still work, uploads are stored
            // for a later retry and questions are answered from facts only.
            'sidecar' => static function () use ($http, $config): ?string {
                try {
                    $code = $http->request('GET', rtrim($config->sidecarUrl, '/') . '/ready')->getStatusCode();
                } catch (GuzzleException) {
                    return 'sidecar unreachable';
                }
                return match (true) {
                    $code === 200 => null,
                    $code === 503 => 'sidecar not ready',
                    default => 'sidecar unavailable',
                };
            },
        ];
    }
}
