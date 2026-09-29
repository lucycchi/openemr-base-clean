<?php

/**
 * Server-authenticated assistant turns for the chat transcript.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Crypto\CryptoInterface;

/**
 * The panel keeps the conversation in the browser and sends it back with
 * every question, so anything it sends is client-controlled. A user turn is
 * the clinician's own words and is passed on as such. An assistant turn is a
 * claim that the Co-Pilot said something, and the model trusts its own prior
 * answers; before this class, a forged "assistant" turn could plant a claim
 * the model then restated (AgentForge finding AF-2026-3214).
 *
 * Every answer now carries a turn token: the answer's text sealed with
 * OpenEMR's authenticated encryption, bound to the patient and the facts
 * hash it was given for. On the next question the server opens each token
 * and uses the sealed text, never the text the browser sent beside it. An
 * assistant turn with no token, a tampered token, or a token minted for
 * another patient or another version of the chart is dropped. Keeping the
 * text inside the token (rather than in the session) keeps the endpoint
 * stateless and keeps answer text out of session storage and its logs.
 */
final readonly class ConversationTurns
{
    private const VERSION = 1;

    public function __construct(private CryptoInterface $crypto)
    {
    }

    /** A token for an answer the server just produced for this patient and chart version. */
    public function seal(PatientId $pid, string $factsHash, string $text): string
    {
        return $this->crypto->encryptStandard(json_encode(
            ['v' => self::VERSION, 'pid' => $pid->value, 'facts_hash' => $factsHash, 'text' => $text],
            JSON_THROW_ON_ERROR,
        ));
    }

    /** The sealed text, or null when the token is forged, altered, or minted for another patient or chart. */
    public function open(PatientId $pid, string $factsHash, string $token): ?string
    {
        if (!$this->crypto->cryptCheckStandard($token)) {
            return null;
        }
        $plain = $this->crypto->decryptStandard($token);
        if (!is_string($plain) || $plain === '') {
            return null;
        }
        try {
            $payload = json_decode($plain, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ($payload['pid'] ?? null) !== $pid->value
            || ($payload['facts_hash'] ?? null) !== $factsHash
            || !is_string($payload['text'] ?? null)
            || $payload['text'] === ''
        ) {
            return null;
        }
        return $payload['text'];
    }

    /**
     * The transcript the model may see: user turns as sent, assistant turns
     * replaced by their sealed server text, unauthenticated assistant turns
     * dropped.
     *
     * @param list<array{role: string, text: string, turn_token?: string}> $transcript
     * @return list<array{role: string, text: string}>
     */
    public function authenticate(PatientId $pid, string $factsHash, array $transcript): array
    {
        $out = [];
        foreach ($transcript as $turn) {
            if ($turn['role'] !== 'assistant') {
                $out[] = ['role' => $turn['role'], 'text' => $turn['text']];
                continue;
            }
            $text = isset($turn['turn_token']) ? $this->open($pid, $factsHash, $turn['turn_token']) : null;
            if ($text !== null) {
                $out[] = ['role' => 'assistant', 'text' => $text];
            }
        }
        return $out;
    }
}
