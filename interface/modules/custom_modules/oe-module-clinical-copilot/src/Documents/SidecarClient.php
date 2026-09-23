<?php

/**
 * HTTP client for the week 2 sidecar: builds run.request, validates the reply against the run.response contract before parsing it into typed objects, maps every failure to a SidecarException code. No patient identifier is ever put on the wire.
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
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger;

/**
 * The only place PHP talks to the Python sidecar. One HTTP POST per run:
 *
 *   build run.request -> POST /run -> map transport errors to codes
 *     -> decode JSON -> validate against run.response contract
 *     -> RunResult::fromArray (typed objects) -> caller
 *
 * Three modes share the path: extract (documents in, extractions out) and
 * answer (a question in, guideline chunks out). Nothing that identifies a
 * patient is in either body: documents travel as bytes plus a hash, and the
 * facts hash is a fingerprint, not data.
 *
 * "final" means no subclassing; the two readonly fields are set once in the
 * constructor.
 */
final class SidecarClient
{
    /** How long one run may take end to end; a large scanned PDF with OCR needs most of this. */
    public const TIMEOUT_S = 60.0;

    /**
     * The HTTP client is injected so tests can hand in one with canned
     * replies (see SidecarClientTest) and never touch the network.
     */
    public function __construct(private readonly Client $http, private readonly Config $config)
    {
    }

    /**
     * Production wiring: a real client with the run timeout and a short
     * connect timeout, so a sidecar that is down fails in five seconds
     * rather than sixty.
     */
    public static function fromConfig(Config $config): self
    {
        return new self(new Client(['timeout' => self::TIMEOUT_S, 'connect_timeout' => 5.0]), $config);
    }

    /**
     * Extract every stored document in $documents (id, type, hash, bytes).
     *
     * The bytes are base64-encoded into the JSON body. The hash lets the
     * sidecar confirm it received what PHP stored, and status is always
     * "stored" because only unread documents are sent.
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

    /** Guideline evidence for a question (mode=answer): no documents, no patient identifiers. */
    /**
     * Brief mode: the chart's fired guideline triggers as fixed queries, the
     * cited fact lines and the patient's age and sex for the critic. No
     * question, no documents, no identifiers.
     *
     * @param list<FiredTrigger> $triggers
     * @param list<string> $factLines
     */
    public function brief(string $correlationId, string $factsHash, array $triggers, array $factLines, ?int $age, ?string $sex): RunResult
    {
        return $this->run([
            'mode' => 'brief',
            'correlation_id' => $correlationId,
            'facts_hash' => $factsHash,
            'question' => null,
            'documents' => [],
            'queries' => array_map(static fn(FiredTrigger $t): array => $t->toQuery(), $triggers),
            'patient' => ['age' => $age, 'sex' => $sex],
            'facts' => $factLines,
        ], self::BRIEF_TIMEOUT_S);
    }

    public function answer(string $correlationId, string $factsHash, string $question): RunResult
    {
        return $this->run(['mode' => 'answer', 'correlation_id' => $correlationId, 'facts_hash' => $factsHash, 'question' => $question, 'documents' => []]);
    }

    /** Seconds a brief run may take end to end: retrieval plus one round of critic calls, well inside the panel's 30 s abandon. */
    public const BRIEF_TIMEOUT_S = 15.0;

    /**
     * The one POST. Every failure becomes a SidecarException with a short
     * code so the controller can log it and tell the user "stored, retry
     * later" without ever showing an upstream message. A per-call timeout
     * overrides the client's default for the run.
     *
     * @param array<string, mixed> $body
     */
    private function run(array $body, ?float $timeout = null): RunResult
    {
        try {
            // The id travels in the body (the contract) and as a header, so the
            // sidecar binds it to its log lines before the body is even parsed.
            $headers = ['Accept' => 'application/json'];
            if (is_string($body['correlation_id'] ?? null)) {
                $headers['X-Correlation-Id'] = $body['correlation_id'];
            }
            $options = ['json' => $body, 'headers' => $headers];
            if ($timeout !== null) {
                $options['timeout'] = $timeout;
            }
            $response = $this->http->post(rtrim($this->config->sidecarUrl, '/') . '/run', $options);
        } catch (ConnectException $e) {
            // Could not open a connection at all: the sidecar container is down or unreachable.
            throw new SidecarException('unavailable', $e);
        } catch (RequestException $e) {
            // The connection worked but the request did not. With no response body,
            // tell a timeout apart from any other transport failure by the message.
            $res = $e->getResponse();
            if ($res === null) {
                throw new SidecarException(str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'unavailable', $e);
            }
            // An HTTP error status: the sidecar's run.error body names the reason
            // (encrypted, too_many_pages, ...). A body that cannot be parsed is "internal".
            $err = json_decode((string) $res->getBody(), true);
            $code = is_array($err) && is_string($err['code'] ?? null) ? $err['code'] : 'internal';
            throw new SidecarException($code, $e);
        }
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new SidecarException('schema_mismatch');
        }
        // The contract is the gate, not the parser: anything the schema rejects
        // (an unknown key, a reason code outside the enum, a malformed chunk id)
        // is refused here, before any of it is typed or persisted.
        if (Contracts::violations('run.response', $decoded) !== []) {
            throw new SidecarException('schema_mismatch');
        }
        /** @var array<string, mixed> $decoded */
        return RunResult::fromArray($decoded);
    }
}
