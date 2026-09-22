<?php

/**
 * HTTP client for the week 2 sidecar: builds run.request, parses run.response into typed objects, maps every failure to a SidecarException code. No patient identifier is ever put on the wire.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use OpenEMR\Modules\ClinicalCopilot\Config;

final class SidecarClient
{
    public const TIMEOUT_S = 60.0;

    public function __construct(private readonly Client $http, private readonly Config $config)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(new Client(['timeout' => self::TIMEOUT_S, 'connect_timeout' => 5.0]), $config);
    }

    /**
     * Extract every stored document in $documents (id, type, hash, bytes).
     *
     * @param list<array{document_id: int, doc_type: DocType, sha3_512: string, bytes: string}> $documents
     */
    public function extract(string $correlationId, string $factsHash, array $documents): RunResult
    {
        $body = [
            'mode' => 'extract',
            'correlation_id' => $correlationId,
            'facts_hash' => $factsHash,
            'question' => null,
            'documents' => array_map(static fn(array $d): array => [
                'document_id' => $d['document_id'],
                'doc_type' => $d['doc_type']->value,
                'status' => 'stored',
                'sha3_512' => $d['sha3_512'],
                'bytes_base64' => base64_encode($d['bytes']),
            ], $documents),
        ];
        return $this->run($body);
    }

    /** @param array<string, mixed> $body */
    private function run(array $body): RunResult
    {
        try {
            $response = $this->http->post(rtrim($this->config->sidecarUrl, '/') . '/run', ['json' => $body, 'headers' => ['Accept' => 'application/json']]);
        } catch (ConnectException $e) {
            throw new SidecarException('unavailable', $e);
        } catch (RequestException $e) {
            $res = $e->getResponse();
            if ($res === null) {
                throw new SidecarException(str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'unavailable', $e);
            }
            $err = json_decode((string) $res->getBody(), true);
            $code = is_array($err) && is_string($err['code'] ?? null) ? $err['code'] : 'internal';
            throw new SidecarException($code, $e);
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new SidecarException('schema_mismatch');
        }
        /** @var array<string, mixed> $decoded */
        return RunResult::fromArray($decoded);
    }
}
