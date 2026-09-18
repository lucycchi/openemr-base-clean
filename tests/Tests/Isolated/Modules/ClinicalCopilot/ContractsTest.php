<?php

/**
 * The JSON Schema files under the module's contracts/ directory are the
 * source of truth for every tool input and output. These tests prove the
 * PHP implementation conforms to them, not the other way round.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use DateTimeImmutable;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use OpenEMR\Modules\ClinicalCopilot\AnswerResult;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Ops\ReadinessReport;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Modules\ClinicalCopilot\PrewarmRunStatus;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatusPayload;
use OpenEMR\Modules\ClinicalCopilot\Prompt;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The JSON Schema contracts in the module's contracts/ directory are the
 * source of truth for every wire shape. This test keeps code and contracts
 * in lockstep from three directions:
 *   1. every contract file is itself a valid, strict schema and loads by name;
 *   2. the schemas Prompt sends to OpenAI ARE the contract files (not a
 *      hand-maintained copy that could drift);
 *   3. every real payload the module produces (PanelPayload variants,
 *      ReadinessReport, prewarm status, error bodies, each Fact) validates
 *      against its contract, and the fact contract's category enum lists
 *      every FactCategory case.
 * The chat.request contract is exercised the other way: sample bodies must
 * be accepted/rejected as ChatRequestTest expects.
 */
final class ContractsTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private const CORRELATION_ID = '763e45ddfc57b76bccc793509358ad89';

    /** Validates $document against contracts/<contract>.schema.json and fails with the validator's error list. @param array<string, mixed> $document */
    private static function assertConforms(string $contract, array $document): void
    {
        $validator = new Validator();
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $validator->validate($data, Contracts::schema($contract), Constraint::CHECK_MODE_NORMAL);
        self::assertTrue(
            $validator->isValid(),
            $contract . ' violated: ' . json_encode($validator->getErrors(), JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $document */
    private static function assertViolates(string $contract, array $document): void
    {
        $validator = new Validator();
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $validator->validate($data, Contracts::schema($contract), Constraint::CHECK_MODE_NORMAL);
        self::assertFalse($validator->isValid(), $contract . ' accepted an invalid document');
    }

    /**
     * Walks a decoded contract by key and asserts an array is there.
     *
     * @return array<mixed>
     */
    private static function nested(string $contract, string ...$path): array
    {
        $node = json_decode(json_encode(Contracts::schema($contract), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            self::assertIsArray($node);
            self::assertArrayHasKey($key, $node);
            $node = $node[$key];
        }
        self::assertIsArray($node);
        return $node;
    }

    private function assembled(): AssembledFacts
    {
        $facts = new FactSet([
            new Fact(Fact::idFor('PrescriptionService', 17, 'drug'), 'PrescriptionService', 17, 'drug', 'Lisinopril 10 MG', FactCategory::MedicationNew),
            new Fact(Fact::idFor('AllergyIntoleranceService', 812, 'title'), 'AllergyIntoleranceService', 812, 'title', 'penicillin', FactCategory::AllergyNew),
        ]);
        return new AssembledFacts($facts, new EncounterRecord(99, new DateTimeImmutable('2026-09-01 10:00:00'), '', 'Follow-up'));
    }

    // -- Contract files --------------------------------------------------

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function contractNames(): array
    {
        return [
            'chat request' => ['chat.request'],
            'briefing response' => ['chat.briefing.response'],
            'answer response' => ['chat.answer.response'],
            'chart-changed response' => ['chat.chart-changed.response'],
            'error response' => ['chat.error.response'],
            'health response' => ['health.response'],
            'ready response' => ['ready.response'],
            'alerts response' => ['alerts.response'],
            'prewarm response' => ['prewarm.response'],
            'llm briefing output' => ['llm.briefing.output'],
            'llm follow-up output' => ['llm.followup.output'],
            'fact' => ['fact'],
        ];
    }

    #[DataProvider('contractNames')]
    public function testEveryContractIsAValidStrictJsonSchema(string $name): void
    {
        $schema = Contracts::schema($name);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema->{'$schema'} ?? null, "$name declares the draft");
        self::assertIsString($schema->{'$id'} ?? null, "$name has an \$id");
        self::assertIsString($schema->description ?? null, "$name has a description");
        self::assertSame('object', $schema->type ?? null);
        self::assertFalse($schema->additionalProperties ?? true, "$name forbids unknown top-level keys");
    }

    public function testUnknownContractNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Contracts::schema('does-not-exist');
    }

    // -- LLM output: Prompt must use the contract files, not its own copy --

    public function testPromptBriefingSchemaIsTheContractFile(): void
    {
        $expected = json_decode(json_encode(Contracts::schema('llm.briefing.output'), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(Contracts::forOpenAi('llm.briefing.output'), (new Prompt())->briefingSchema());
        self::assertIsArray($expected);
        self::assertArrayNotHasKey('$schema', (new Prompt())->briefingSchema(), 'OpenAI rejects $schema/$id metadata keys');
        self::assertSame($expected['properties'], (new Prompt())->briefingSchema()['properties']);
    }

    public function testPromptFollowUpSchemaIsTheContractFile(): void
    {
        self::assertSame(Contracts::forOpenAi('llm.followup.output'), (new Prompt())->followUpSchema());
        self::assertSame(['cited', 'not_in_facts'], self::nested('llm.followup.output', 'properties', 'answer_type', 'enum'));
    }

    public function testLlmOutputContractsRejectUncitedOrExtraFields(): void
    {
        self::assertConforms('llm.briefing.output', ['sentences' => [['text' => 'A drug was started.', 'fact_ids' => [Fact::idFor('PrescriptionService', 17, 'drug')]]]]);
        self::assertViolates('llm.briefing.output', ['sentences' => [['text' => 'A drug was started.']]]);
        self::assertViolates('llm.briefing.output', ['sentences' => [], 'extra' => 1]);
        self::assertConforms('llm.followup.output', ['answer_type' => 'not_in_facts', 'sentences' => []]);
        self::assertViolates('llm.followup.output', ['answer_type' => 'maybe', 'sentences' => []]);
    }

    // -- HTTP responses: PanelPayload and ReadinessReport conform -----------

    public function testBriefingPayloadConformsToContract(): void
    {
        $briefing = new BriefingResult(
            [new Sentence('A new medication was started.', [Fact::idFor('PrescriptionService', 17, 'drug')])],
            1,
            [],
            null,
            false,
            false,
            600,
            90,
        );
        self::assertConforms('chat.briefing.response', PanelPayload::briefing($this->assembled(), $briefing, self::CORRELATION_ID));
    }

    public function testBriefingPayloadWithProviderFailureConformsToContract(): void
    {
        $failed = new BriefingResult([], 0, [], 'AI summary unavailable: provider busy, try again shortly', false, false, 0, 0);
        self::assertConforms('chat.briefing.response', PanelPayload::briefing($this->assembled(), $failed, self::CORRELATION_ID));
    }

    public function testAnswerPayloadConformsToContract(): void
    {
        $answer = new AnswerResult('cited', [new Sentence('Lisinopril 10 MG.', [Fact::idFor('PrescriptionService', 17, 'drug')])], 0, null, 400, 30);
        self::assertConforms('chat.answer.response', PanelPayload::answer($this->assembled(), $answer, self::CORRELATION_ID));
        $declined = new AnswerResult('not_in_facts', [], 0, null, 0, 0);
        self::assertConforms('chat.answer.response', PanelPayload::answer($this->assembled(), $declined, self::CORRELATION_ID));
    }

    public function testChartChangedPayloadConformsToContract(): void
    {
        self::assertConforms('chat.chart-changed.response', PanelPayload::chartChanged($this->assembled(), self::CORRELATION_ID));
    }

    public function testPrewarmStatusConformsToContractWithAndWithoutARun(): void
    {
        $lastRun = new PrewarmRunStatus('44fdfdd5e4628b5a9a0c70aa7f8ad394', '2026-09-18', '2026-09-18T06:02:11+00:00', 2, 1, 1, 0, 0);
        self::assertConforms('prewarm.response', PrewarmStatusPayload::build(true, $lastRun, '2026-09-18T13:00:00+00:00'));
        self::assertConforms('prewarm.response', PrewarmStatusPayload::build(false, null, '2026-09-18T13:00:00+00:00'));
        self::assertViolates('prewarm.response', ['enabled' => true, 'time' => '2026-09-18T13:00:00+00:00']);
    }

    public function testErrorPayloadConformsToContract(): void
    {
        self::assertConforms('chat.error.response', ['error' => 'You are not authorized to view this chart', 'correlation_id' => self::CORRELATION_ID]);
        self::assertViolates('chat.error.response', ['error' => 'x', 'correlation_id' => 'short']);
    }

    public function testEveryFactInAPayloadConformsToTheFactContract(): void
    {
        $payload = PanelPayload::chartChanged($this->assembled(), self::CORRELATION_ID);
        self::assertIsArray($payload['facts']);
        self::assertNotEmpty($payload['facts']);
        foreach ($payload['facts'] as $fact) {
            self::assertIsArray($fact);
            /** @var array<string, mixed> $fact */
            self::assertConforms('fact', $fact);
        }
    }

    public function testFactContractEnumeratesEveryFactCategory(): void
    {
        $enum = self::nested('fact', 'properties', 'category', 'enum');
        $cases = array_map(static fn(FactCategory $c) => $c->value, FactCategory::cases());
        sort($enum);
        sort($cases);
        self::assertSame($cases, $enum, 'fact.schema.json category enum must match FactCategory');
    }

    public function testReadinessReportConformsToContract(): void
    {
        $ready = new ReadinessReport(true, ['database' => 'ok', 'openai' => 'ok', 'langfuse' => 'ok'], [], false, 0, '2026-09-16T12:00:00Z');
        self::assertConforms('ready.response', $ready->toArray());
        $degraded = new ReadinessReport(true, ['database' => 'ok', 'openai' => 'ok', 'langfuse' => 'langfuse unreachable'], ['langfuse'], true, 30, '2026-09-16T12:00:00Z');
        self::assertConforms('ready.response', $degraded->toArray());
        $down = new ReadinessReport(false, ['database' => 'database query failed', 'openai' => 'ok', 'langfuse' => 'ok'], [], false, 0, '2026-09-16T12:00:00Z');
        self::assertConforms('ready.response', $down->toArray());
    }

    public function testAlertsResponseContract(): void
    {
        self::assertConforms('alerts.response', ['received' => true, 'alert' => 'copilot p95 latency', 'severity' => 'critical', 'correlation_id' => self::CORRELATION_ID]);
        self::assertConforms('alerts.response', ['error' => 'Invalid token', 'correlation_id' => self::CORRELATION_ID]);
        self::assertViolates('alerts.response', ['received' => true, 'correlation_id' => self::CORRELATION_ID]);
        self::assertViolates('alerts.response', ['error' => 'x', 'received' => true, 'alert' => 'a', 'severity' => 's', 'correlation_id' => self::CORRELATION_ID]);
    }

    public function testHealthResponseContract(): void
    {
        self::assertConforms('health.response', ['status' => 'ok', 'service' => 'clinical-copilot', 'time' => '2026-09-16T12:00:00+00:00']);
        self::assertViolates('health.response', ['status' => 'ok']);
    }

    // -- HTTP request -----------------------------------------------------

    public function testChatRequestContractAcceptsBriefAndAsk(): void
    {
        self::assertConforms('chat.request', ['csrf_token_form' => 'abc', 'action' => 'brief']);
        self::assertConforms('chat.request', [
            'csrf_token_form' => 'abc',
            'action' => 'ask',
            'question' => 'What changed?',
            'facts_hash' => str_repeat('a', 64),
            'transcript' => '[{"role":"user","text":"hi"}]',
        ]);
    }

    public function testChatRequestContractRejectsUnknownActionAndAskWithoutQuestion(): void
    {
        self::assertViolates('chat.request', ['csrf_token_form' => 'abc', 'action' => 'delete']);
        self::assertViolates('chat.request', ['csrf_token_form' => 'abc', 'action' => 'ask', 'facts_hash' => str_repeat('a', 64)]);
        self::assertViolates('chat.request', ['action' => 'brief']);
        self::assertViolates('chat.request', ['csrf_token_form' => 'abc', 'action' => 'brief', 'pid' => 3]);
    }
}
