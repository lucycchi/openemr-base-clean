<?php

/**
 * Typed chat.php request, parsed once at the boundary from the form body.
 * Accepts exactly what contracts/chat.request.schema.json accepts
 * (ChatRequestTest holds the two to the same verdicts); everything after
 * this point works with a valid request and never re-checks the body.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use Symfony\Component\HttpFoundation\InputBag;

/**
 * The parsed, validated form of a POST to chat.php. This is the
 * "parse, don't validate" boundary: raw request fields go in once, and
 * everything downstream works with typed properties that are guaranteed
 * sane (action is an enum, question is trimmed and bounded, facts_hash is a
 * real sha256, transcript is a clean list). Any problem throws
 * InvalidRequest with the right HTTP status; the constructor is private so
 * an instance can only exist via fromBag().
 */
final readonly class ChatRequest
{
    private const KNOWN_KEYS = ['csrf_token_form', 'action', 'question', 'facts_hash', 'transcript'];
    private const QUESTION_MAX = 500;   // chars; longer questions are truncated, not rejected
    private const TURN_TEXT_MAX = 1000; // chars per prior chat turn
    private const TURNS_KEPT = 10;      // only the most recent N turns are sent to the model

    /**
     * @param list<array{role: string, text: string}> $transcript
     */
    private function __construct(
        public string $csrfToken,
        public ChatAction $action,
        public ?string $question,
        public ?string $factsHash,
        public array $transcript,
    ) {
    }

    /**
     * @param InputBag<string|int|float|bool|null> $bag
     * @throws InvalidRequest
     */
    public static function fromBag(InputBag $bag): self
    {
        // Reject unknown fields outright rather than ignoring them — it
        // surfaces client bugs and blocks parameter-smuggling.
        foreach ($bag->keys() as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw new InvalidRequest('Unexpected field in request');
            }
        }
        $csrf = $bag->getString('csrf_token_form');
        if ($csrf === '') {
            // A missing token is a CSRF failure, the same as a wrong one.
            throw new InvalidRequest('CSRF verification failed', 403);
        }
        $action = ChatAction::tryFrom($bag->getString('action'));
        if ($action === null) {
            throw new InvalidRequest('Unknown action');
        }
        // 'brief' needs nothing else; the remaining checks are for 'ask'.
        if ($action === ChatAction::Brief) {
            return new self($csrf, $action, null, null, []);
        }
        $question = mb_substr(trim($bag->getString('question')), 0, self::QUESTION_MAX);
        if ($question === '') {
            throw new InvalidRequest('Question is required');
        }
        // The panel echoes back the facts_hash from its last briefing so the
        // server can detect that the chart changed underneath the conversation.
        $hash = $bag->getString('facts_hash');
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new InvalidRequest('facts_hash is required');
        }
        return new self($csrf, $action, $question, $hash, self::parseTranscript($bag->getString('transcript', '[]')));
    }

    /**
     * Decodes the prior chat turns the panel sends as a JSON string. Malformed
     * JSON or malformed turns are dropped silently (an empty transcript is a
     * valid state); role is restricted to user/assistant and text is bounded.
     * @return list<array{role: string, text: string}>
     */
    private static function parseTranscript(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $turns = [];
        foreach (is_array($decoded) ? $decoded : [] as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = $turn['role'] ?? '';
            $text = $turn['text'] ?? '';
            if (is_string($role) && is_string($text) && in_array($role, ['user', 'assistant'], true) && $text !== '') {
                $turns[] = ['role' => $role, 'text' => mb_substr($text, 0, self::TURN_TEXT_MAX)];
            }
        }
        return array_slice($turns, -self::TURNS_KEPT);
    }
}
