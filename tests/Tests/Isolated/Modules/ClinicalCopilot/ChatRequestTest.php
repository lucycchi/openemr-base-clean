<?php

/**
 * ChatRequest parses the chat.php body into a typed object and must accept
 * and reject exactly what contracts/chat.request.schema.json does.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use JsonSchema\Validator;
use OpenEMR\Modules\ClinicalCopilot\ChatAction;
use OpenEMR\Modules\ClinicalCopilot\ChatRequest;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\InvalidRequest;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * ChatRequest parsing. The headline test is table-driven and dual-checks
 * each body against the JSON Schema contract AND the PHP parser, asserting
 * they agree — so the contract file and the code cannot silently diverge.
 * The rest pin individual rules: question trimming/cap, transcript
 * filtering to the last 10 well-formed turns, malformed JSON -> empty
 * transcript, and that a missing CSRF token is 403 not 400.
 */
final class ChatRequestTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private const HASH = 'a3f1c2d4e5b6a7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e8f9a0b1c2';

    /**
     * Every body here is judged by the contract file first; the parser must agree.
     *
     * @return array<string, array{array<string, string|int>, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function bodies(): array
    {
        return [
            'brief' => [['csrf_token_form' => 't', 'action' => 'brief'], true],
            'ask' => [['csrf_token_form' => 't', 'action' => 'ask', 'question' => 'What changed?', 'facts_hash' => self::HASH], true],
            'ask with transcript' => [['csrf_token_form' => 't', 'action' => 'ask', 'question' => 'Why?', 'facts_hash' => self::HASH, 'transcript' => '[{"role":"user","text":"hi"}]'], true],
            'unknown action' => [['csrf_token_form' => 't', 'action' => 'delete'], false],
            'missing action' => [['csrf_token_form' => 't'], false],
            'ask without question' => [['csrf_token_form' => 't', 'action' => 'ask', 'facts_hash' => self::HASH], false],
            'ask with blank question' => [['csrf_token_form' => 't', 'action' => 'ask', 'question' => '   ', 'facts_hash' => self::HASH], false],
            'ask without facts_hash' => [['csrf_token_form' => 't', 'action' => 'ask', 'question' => 'Why?'], false],
            'ask with malformed facts_hash' => [['csrf_token_form' => 't', 'action' => 'ask', 'question' => 'Why?', 'facts_hash' => 'nope'], false],
            'missing csrf' => [['action' => 'brief'], false],
            'pid in body' => [['csrf_token_form' => 't', 'action' => 'brief', 'pid' => 3], false],
        ];
    }

    /** @param array<string, string|int> $body */
    #[DataProvider('bodies')]
    public function testParserAgreesWithTheContract(array $body, bool $contractAccepts): void
    {
        // 1. The contract's verdict (justinrainbow/json-schema validator).
        $validator = new Validator();
        $document = json_decode(json_encode($body, JSON_THROW_ON_ERROR));
        $validator->validate($document, Contracts::schema('chat.request'));
        self::assertSame($contractAccepts, $validator->isValid(), 'contract verdict differs from the provider expectation');

        // 2. The parser's verdict. Form fields arrive as strings, hence the cast.
        $bag = new InputBag(array_map(static fn($v) => is_int($v) ? (string) $v : $v, $body));
        if ($contractAccepts) {
            self::assertSame($body['action'], ChatRequest::fromBag($bag)->action->value);
            return;
        }
        $this->expectException(InvalidRequest::class);
        ChatRequest::fromBag($bag);
    }

    public function testBriefRequestExposesActionAndCsrf(): void
    {
        $r = ChatRequest::fromBag(new InputBag(['csrf_token_form' => 'tok', 'action' => 'brief']));
        self::assertSame(ChatAction::Brief, $r->action);
        self::assertSame('tok', $r->csrfToken);
        self::assertNull($r->question);
        self::assertNull($r->factsHash);
        self::assertSame([], $r->transcript);
    }

    public function testQuestionIsTrimmedAndCappedAt500Characters(): void
    {
        $long = str_repeat('x', 600);
        $r = ChatRequest::fromBag(new InputBag(['csrf_token_form' => 't', 'action' => 'ask', 'question' => "  $long  ", 'facts_hash' => self::HASH]));
        self::assertSame(500, mb_strlen((string) $r->question));
        self::assertSame(self::HASH, $r->factsHash);
    }

    public function testTranscriptKeepsLastTenWellFormedTurnsAndIgnoresGarbage(): void
    {
        $turns = [];
        for ($i = 1; $i <= 12; $i++) {
            $turns[] = ['role' => $i % 2 === 1 ? 'user' : 'assistant', 'text' => "turn $i"];
        }
        $turns[] = ['role' => 'system', 'text' => 'ignored role'];
        $turns[] = ['role' => 'user', 'text' => ''];
        $turns[] = 'not an object';
        $r = ChatRequest::fromBag(new InputBag(['csrf_token_form' => 't', 'action' => 'ask', 'question' => 'q', 'facts_hash' => self::HASH, 'transcript' => json_encode($turns, JSON_THROW_ON_ERROR)]));
        self::assertCount(10, $r->transcript);
        self::assertSame('turn 3', $r->transcript[0]['text']);
        self::assertSame('turn 12', $r->transcript[9]['text']);
    }

    public function testMalformedTranscriptJsonIsAnEmptyTranscript(): void
    {
        $r = ChatRequest::fromBag(new InputBag(['csrf_token_form' => 't', 'action' => 'ask', 'question' => 'q', 'facts_hash' => self::HASH, 'transcript' => '{not json']));
        self::assertSame([], $r->transcript);
    }

    public function testInvalidRequestCarriesAPhysicianFacingMessage(): void
    {
        try {
            ChatRequest::fromBag(new InputBag(['csrf_token_form' => 't', 'action' => 'ask', 'facts_hash' => self::HASH]));
            self::fail('expected InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame('Question is required', $e->getMessage());
        }
        try {
            ChatRequest::fromBag(new InputBag(['csrf_token_form' => 't', 'action' => 'nope']));
            self::fail('expected InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame('Unknown action', $e->getMessage());
            self::assertSame(400, $e->httpStatus);
        }
    }

    public function testMissingCsrfTokenIsAForbiddenNotABadRequest(): void
    {
        try {
            ChatRequest::fromBag(new InputBag(['action' => 'brief']));
            self::fail('expected InvalidRequest');
        } catch (InvalidRequest $e) {
            self::assertSame('CSRF verification failed', $e->getMessage());
            self::assertSame(403, $e->httpStatus);
        }
    }
}
