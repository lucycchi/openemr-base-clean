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
use OpenEMR\Modules\ClinicalCopilot\BriefingRating;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Ops\ReadinessReport;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Modules\ClinicalCopilot\PrewarmRunStatus;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatusPayload;
use OpenEMR\Modules\ClinicalCopilot\Prompt;
use OpenEMR\Modules\ClinicalCopilot\RatingSubmission;
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

    /**
     * Validates $document against contracts/<contract>.schema.json and fails with the validator's error list.
     *
     * @param array<string, mixed> $document
     */
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
            'rate response' => ['chat.rate.response'],
            'error response' => ['chat.error.response'],
            'health response' => ['health.response'],
            'ready response' => ['ready.response'],
            'alerts response' => ['alerts.response'],
            'prewarm response' => ['prewarm.response'],
            'llm briefing output' => ['llm.briefing.output'],
            'llm follow-up output' => ['llm.followup.output'],
            'fact' => ['fact'],
            'citation' => ['citation'],
            'lab trends' => ['trends'],
            'cited sentence' => ['sentence'],
            'lab report extraction' => ['lab-report'],
            'intake form extraction' => ['intake-form'],
            'handoff' => ['handoff'],
            'run request' => ['run.request'],
            'run response' => ['run.response'],
            'run error' => ['run.error'],
            'documents request' => ['documents.request'],
            'documents list response' => ['documents.list.response'],
            'documents upload response' => ['documents.upload.response'],
            'documents extract response' => ['documents.extract.response'],
            'documents error response' => ['documents.error.response'],
            'llm lab proposal output' => ['llm.lab-proposal.output'],
            'llm intake proposal output' => ['llm.intake-proposal.output'],
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
        $routed = PanelPayload::briefing($this->assembled(), $briefing, self::CORRELATION_ID);
        $routed['handoffs'] = \OpenEMR\Modules\ClinicalCopilot\AnswerRoute::forBriefing($briefing, 900);
        self::assertConforms('chat.briefing.response', $routed);
        self::assertNull(self::path($routed, 'narration', 'cache_key'), 'a narration that is not cached cannot be rated');

        // A cached narration carries its key so the panel can rate it.
        $key = str_repeat('ab', 32);
        $cached = new BriefingResult([new Sentence('A new medication was started.', [Fact::idFor('PrescriptionService', 17, 'drug')])], 0, [], null, true, false, 0, 0, '2026-09-24T08:00:00-05:00', $key);
        $payload = PanelPayload::briefing($this->assembled(), $cached, self::CORRELATION_ID);
        self::assertConforms('chat.briefing.response', $payload);
        self::assertSame($key, self::path($payload, 'narration', 'cache_key'));
    }

    public function testRatePayloadConformsToContractAndNeverCarriesTheComment(): void
    {
        $rating = new RatingSubmission(BriefingRating::Down, 'Mr Example also saw cardiology.', str_repeat('ab', 32), self::CORRELATION_ID);
        $payload = PanelPayload::rated($rating, self::CORRELATION_ID);
        self::assertConforms('chat.rate.response', $payload);
        self::assertSame(['correlation_id' => self::CORRELATION_ID, 'rating' => 'down', 'comment_saved' => true], $payload);
        self::assertConforms('chat.rate.response', PanelPayload::rated(new RatingSubmission(BriefingRating::Up, null, str_repeat('ab', 32), self::CORRELATION_ID), self::CORRELATION_ID));
        self::assertViolates('chat.rate.response', ['correlation_id' => self::CORRELATION_ID, 'rating' => 'up', 'comment_saved' => false, 'comment' => 'x']);
    }

    public function testBriefingPayloadWithGuidelineCardsConformsToContract(): void
    {
        // One sentence restates a card's passage, so it cites the chunk id, not a fact.
        $briefing = new BriefingResult([new Sentence('Guidelines recommend a statin here.', ['aaaaaaaaaaaa'])], 0, [], null, false, false, 0, 0);
        $run = \OpenEMR\Modules\ClinicalCopilot\Documents\RunResult::fromArray(['correlation_id' => 'c', 'extractions' => [], 'chunks' => [], 'evidence' => [
            ['trigger_id' => 'lipids', 'chunks' => [['chunk_id' => 'aaaaaaaaaaaa', 'source_id' => 'acc-aha-2018-cholesterol', 'section' => 'T > S', 'quote' => 'A passage.', 'score' => 0.8]], 'applicable' => true, 'reason' => 'no restriction stated'],
            ['trigger_id' => 'anemia', 'chunks' => [['chunk_id' => 'bbbbbbbbbbbb', 'source_id' => 'anemia-adults-primary-care', 'section' => 'T > S', 'quote' => 'B passage.', 'score' => 0.7]], 'applicable' => null, 'reason' => null],
        ], 'handoffs' => [], 'usage' => []]);
        $triggers = [
            new \OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger('lipids', 'Cholesterol management', 'q', 'acc-aha-2018-cholesterol', [Fact::idFor('PrescriptionService', 17, 'drug')], []),
            new \OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger('anemia', 'Anemia', 'q', 'anemia-adults-primary-care', [], ['on the problem list: Anemia']),
        ];
        $section = \OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection::fromRun($triggers, $run, new \OpenEMR\Modules\ClinicalCopilot\GuidelineManifest());
        $payload = PanelPayload::briefing($this->assembled(), $briefing, self::CORRELATION_ID, $section);
        self::assertConforms('chat.briefing.response', $payload);
        $sentence = self::pathArray($payload, 'narration', 'sentences', 0);
        $citations = self::pathArray($sentence, 'citations');
        self::assertSame(['source_type' => 'guideline', 'source_id' => 'acc-aha-2018-cholesterol', 'page_or_section' => 'T > S', 'field_or_chunk_id' => 'aaaaaaaaaaaa', 'quote_or_value' => 'A passage.', 'anchored' => true], $citations[0]);
        self::assertSame($citations[0], self::path($payload, 'guidelines', 'cards', 0, 'chunks', 0, 'citation'));
        // With the section unavailable the chunk id resolves to nothing, so the
        // sentence would go out with no citation: it is dropped and counted instead.
        $uncited = PanelPayload::briefing($this->assembled(), $briefing, self::CORRELATION_ID, \OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection::none('unavailable'));
        self::assertConforms('chat.briefing.response', $uncited);
        self::assertSame([], self::path($uncited, 'narration', 'sentences'));
        self::assertSame(1, self::path($uncited, 'narration', 'stripped'));
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
        // With the route the controller adds for the "Why this result" drawer.
        $routed = PanelPayload::answer($this->assembled(), $answer, self::CORRELATION_ID);
        $routed['handoffs'] = [
            ['from' => 'supervisor', 'to' => 'evidence_retriever', 'reason' => 'question_present', 'state_keys_changed' => [], 'ms' => 1],
            ['from' => 'evidence_retriever', 'to' => 'supervisor', 'reason' => 'worker_finished', 'state_keys_changed' => ['chunks'], 'ms' => 300],
            ['from' => 'supervisor', 'to' => 'done', 'reason' => 'worker_finished', 'state_keys_changed' => [], 'ms' => 0],
            ...\OpenEMR\Modules\ClinicalCopilot\AnswerRoute::forAnswer($answer, 700, 1),
        ];
        self::assertConforms('chat.answer.response', $routed);
    }

    /**
     * The PRD's citation contract: every clinical claim carries machine-readable
     * citation metadata in the five-field shape. Each sentence carries one full
     * citation per id it cites, a chart fact's or a guideline passage's, and each
     * guideline passage carries its own.
     */
    public function testEverySentenceCarriesTheFiveFieldCitationOfEachIdItCites(): void
    {
        $drug = Fact::idFor('PrescriptionService', 17, 'drug');
        $chunk = new EvidenceChunk('cccccccccccc', 'ada-2025-standards', 'Pharmacologic therapy > Metformin', 'Metformin is the preferred initial agent.', 0.9, 'ADA Standards of Care 2025', 'https://example.org/ada');
        $answer = new AnswerResult('cited', [
            new Sentence('Lisinopril 10 MG is on the chart.', [$drug]),
            new Sentence('Guidelines prefer metformin first.', ['cccccccccccc']),
        ], 0, null, 400, 30, [$chunk]);
        $payload = PanelPayload::answer($this->assembled(), $answer, self::CORRELATION_ID);
        self::assertConforms('chat.answer.response', $payload);

        $chart = self::pathArray($payload, 'answer', 'sentences', 0, 'citations');
        self::assertCount(1, $chart);
        self::assertSame('chart', self::path($chart, 0, 'source_type'));
        self::assertSame($drug, self::path($chart, 0, 'field_or_chunk_id'));

        $guideline = self::pathArray($payload, 'answer', 'sentences', 1, 'citations');
        self::assertSame(['source_type' => 'guideline', 'source_id' => 'ada-2025-standards', 'page_or_section' => 'Pharmacologic therapy > Metformin', 'field_or_chunk_id' => 'cccccccccccc', 'quote_or_value' => 'Metformin is the preferred initial agent.', 'anchored' => true], $guideline[0]);
        self::assertSame($guideline[0], self::path($payload, 'answer', 'guidelines', 0, 'citation'));
    }

    /**
     * No citation, no claim, held twice: the sentence contract refuses a
     * claim with no ids, no citations or an id of the wrong shape, and
     * PanelPayload never emits a sentence that cites an id it cannot resolve
     * (it drops it and counts it as stripped) or that has lost its text.
     */
    public function testNoSentenceGoesOutWithoutACitationForEveryIdItCites(): void
    {
        $drug = Fact::idFor('PrescriptionService', 17, 'drug');
        $citation = ['source_type' => 'chart', 'source_id' => 'PrescriptionService#17', 'page_or_section' => 'drug', 'field_or_chunk_id' => $drug, 'quote_or_value' => 'Lisinopril 10 MG', 'anchored' => true];
        self::assertConforms('sentence', ['text' => 'Lisinopril was started.', 'fact_ids' => [$drug], 'citations' => [$citation]]);
        self::assertViolates('sentence', ['text' => 'Lisinopril was started.', 'fact_ids' => [], 'citations' => []]);
        self::assertViolates('sentence', ['text' => 'Lisinopril was started.', 'fact_ids' => [$drug], 'citations' => []]);
        self::assertViolates('sentence', ['text' => 'Lisinopril was started.', 'fact_ids' => ['PrescriptionService#17'], 'citations' => [$citation]]);
        self::assertViolates('sentence', ['text' => '', 'fact_ids' => [$drug], 'citations' => [$citation]]);

        $answer = new AnswerResult('cited', [
            new Sentence('Lisinopril 10 MG is on the chart.', [$drug]),
            new Sentence('Half of this claim has no source.', [$drug, 'deadbeef']),
            new Sentence('', [$drug]),
        ], 2, null, 400, 30);
        $payload = PanelPayload::answer($this->assembled(), $answer, self::CORRELATION_ID);
        self::assertConforms('chat.answer.response', $payload);
        $sentences = self::pathArray($payload, 'answer', 'sentences');
        self::assertCount(1, $sentences);
        self::assertSame('Lisinopril 10 MG is on the chart.', self::path($sentences, 0, 'text'));
        self::assertSame(4, self::path($payload, 'answer', 'stripped'), 'the Verifier\'s 2 plus the 2 dropped here');
    }

    /**
     * Walks nested arrays by key and returns the value, failing the test when a key is missing.
     *
     * @param array<mixed> $a
     */
    private static function path(array $a, int|string ...$keys): mixed
    {
        $node = $a;
        foreach ($keys as $k) {
            self::assertIsArray($node);
            self::assertArrayHasKey($k, $node);
            $node = $node[$k];
        }
        return $node;
    }

    /**
     * path() for a node the test goes on to index or count: asserts it is an array.
     *
     * @param array<mixed> $a
     * @return array<mixed>
     */
    private static function pathArray(array $a, int|string ...$keys): array
    {
        $node = self::path($a, ...$keys);
        self::assertIsArray($node);
        return $node;
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
        $ready = new ReadinessReport(true, ['contracts' => 'ok', 'database' => 'ok', 'openai' => 'ok', 'langfuse' => 'ok', 'sidecar' => 'ok'], [], false, 0, '2026-09-16T12:00:00Z');
        self::assertConforms('ready.response', $ready->toArray());
        $degraded = new ReadinessReport(true, ['contracts' => 'ok', 'database' => 'ok', 'openai' => 'ok', 'langfuse' => 'langfuse unreachable', 'sidecar' => 'sidecar not ready'], ['langfuse', 'sidecar'], true, 30, '2026-09-16T12:00:00Z');
        self::assertConforms('ready.response', $degraded->toArray());
        $down = new ReadinessReport(false, ['contracts' => 'ok', 'database' => 'database query failed', 'openai' => 'ok', 'langfuse' => 'ok', 'sidecar' => 'ok'], [], false, 0, '2026-09-16T12:00:00Z');
        self::assertConforms('ready.response', $down->toArray());
        self::assertViolates('ready.response', (new ReadinessReport(true, ['database' => 'ok', 'openai' => 'ok', 'langfuse' => 'ok'], [], false, 0, '2026-09-16T12:00:00Z'))->toArray());
        // A build without the validator library is not ready, and says so in the contract's vocabulary.
        self::assertConforms('ready.response', (new ReadinessReport(false, ['contracts' => 'contract validator missing', 'database' => 'ok', 'openai' => 'ok', 'langfuse' => 'ok', 'sidecar' => 'ok'], [], false, 0, '2026-09-16T12:00:00Z'))->toArray());
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
