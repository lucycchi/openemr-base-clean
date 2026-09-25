<?php

/**
 * GuidelineEvidence builds a briefing's guideline section for chart open and
 * for the 06:00 pre-warm, so both read and write the same cached cards.
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
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingCache;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Demographics;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineEvidence;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineStatus;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeBriefingCache;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

/**
 * Drives GuidelineEvidence with a fake chart, an in-memory cache and a
 * SidecarClient over a Guzzle mock that replays recorded brief replies and
 * records what was sent. Covers every status the pre-warm receipt can hold,
 * the cache round trip, and that the day and correlation id the caller
 * passes are the ones used.
 */
final class GuidelineEvidenceTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private FakeChartSource $chart;
    private FakeBriefingCache $cache;
    /** @var list<RequestInterface> */
    private array $sent = [];
    private DateTimeImmutable $day;

    protected function setUp(): void
    {
        $this->day = new DateTimeImmutable('2026-09-24 00:00:00');
        $this->chart = new FakeChartSource();
        // 55 on the day: inside the statin rule's 40-75 window.
        $this->chart->demographics = new Demographics('F', new DateTimeImmutable('1971-03-02'));
        $this->cache = new FakeBriefingCache();
    }

    private function highLdl(): AssembledFacts
    {
        $ldl = new Fact(Fact::idFor('ObservationLabService', 901, 'result'), 'ObservationLabService', 901, 'result', 'LDL Cholesterol 165 mg/dL on 2026-09-10 (above the standard range 0-129 mg/dL)', FactCategory::LabAbnormal, null, ['loinc' => '2089-1', 'direction' => 'above']);
        return new AssembledFacts(new FactSet([$ldl]), null);
    }

    /**
     * A recorded sidecar brief reply for the lipids trigger.
     *
     * @return array<string, mixed>
     */
    private function reply(?bool $applicable = true, bool $criticRan = true): array
    {
        $hop = static fn(string $from, string $to, string $reason): array => ['from' => $from, 'to' => $to, 'reason' => $reason, 'state_keys_changed' => [], 'ms' => 1];
        $handoffs = [$hop('supervisor', 'evidence_retriever', 'chart_triggers'), $hop('evidence_retriever', 'supervisor', 'worker_finished')];
        if ($criticRan) {
            $handoffs[] = $hop('supervisor', 'critic', 'applicability_check');
            $handoffs[] = $hop('critic', 'supervisor', $applicable === null ? 'worker_failed' : 'worker_finished');
        }
        $handoffs[] = $hop('supervisor', 'done', 'worker_finished');
        return [
            'correlation_id' => 'corr-guidelines',
            'extractions' => [],
            'chunks' => [],
            'handoffs' => $handoffs,
            'usage' => $criticRan ? [['model' => 'gpt-4o-mini', 'kind' => 'chat', 'input' => 400, 'output' => 20]] : [],
            'evidence' => [[
                'trigger_id' => 'lipids',
                'chunks' => [['chunk_id' => 'a1b2c3d4e5f6', 'source_id' => 'acc-aha-2018-cholesterol', 'section' => 'Statin therapy', 'quote' => 'In adults 40 to 75 years of age with LDL-C 70 to 189 mg/dL, moderate-intensity statin therapy is recommended.', 'score' => 0.9]],
                'applicable' => $criticRan ? $applicable : null,
                'reason' => $applicable === null ? null : 'age 55 is within 40 to 75',
            ]],
        ];
    }

    /** @param list<Response> $responses */
    private function evidence(array $responses): GuidelineEvidence
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->sent[] = $request;
        }));
        $config = new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '');
        return new GuidelineEvidence($this->chart, new SidecarClient(new Client(['handler' => $stack]), $config), $config, new NullLogger(), fn(PatientId $pid, string $factsHash): BriefingCache => $this->cache);
    }

    /** @param array<string, mixed> $body */
    private static function ok(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function sentBody(int $i): array
    {
        $body = json_decode((string) $this->sent[$i]->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    public function testNothingFiredMeansNoCardsAndNoSidecarCall(): void
    {
        $outcome = $this->evidence([])->build(new AssembledFacts(new FactSet([]), null), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(GuidelineStatus::NoneFired, $outcome->status);
        self::assertSame('no_triggers', $outcome->section->status);
        self::assertSame([], $this->sent);
    }

    public function testACompleteRunIsBuiltCachedAndCarriesTheSidecarRoute(): void
    {
        $outcome = $this->evidence([self::ok($this->reply())])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(GuidelineStatus::Built, $outcome->status);
        self::assertFalse($outcome->fromCache);
        self::assertCount(1, $outcome->section->cards);
        self::assertCount(1, $this->cache->entries, 'a complete section is cached');
        self::assertSame('critic', $outcome->handoffs[2]['to']);
        self::assertSame('chat', $outcome->usage[0]['kind']);
        self::assertGreaterThan(0.0, $outcome->costUsd());
    }

    public function testTheCallersCorrelationIdIsSentToTheSidecar(): void
    {
        $this->evidence([self::ok($this->reply())])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-sweep-row-7');

        self::assertCount(1, $this->sent);
        self::assertSame('corr-sweep-row-7', $this->sentBody(0)['correlation_id']);
        self::assertSame('brief', $this->sentBody(0)['mode']);
    }

    public function testASecondBuildOfTheSameChartIsServedFromTheCache(): void
    {
        $evidence = $this->evidence([self::ok($this->reply())]);
        $first = $evidence->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');
        $second = $evidence->build($this->highLdl(), new PatientId(7), $this->day, 'corr-2');

        self::assertCount(1, $this->sent, 'the second build makes no sidecar call');
        self::assertSame(GuidelineStatus::Built, $second->status);
        self::assertTrue($second->fromCache);
        self::assertSame($first->section->toArray(), $second->section->toArray());
        self::assertSame([], $second->handoffs, 'no sidecar route when the cards came from the cache');
    }

    public function testAgeIsTakenOnTheDayPassedNotOnTheRealClock(): void
    {
        // 74 on 2026-09-24, 75 on her birthday the next day. The age is part of the cache key and
        // the critic's input, so `copilot:prewarm --date=tomorrow` must use tomorrow's.
        $this->chart->demographics = new Demographics('F', new DateTimeImmutable('1951-09-25'));
        $evidence = $this->evidence([self::ok($this->reply()), self::ok($this->reply())]);

        $evidence->build($this->highLdl(), new PatientId(7), new DateTimeImmutable('2026-09-24 00:00:00'), 'corr-1');
        $tomorrow = $evidence->build($this->highLdl(), new PatientId(7), new DateTimeImmutable('2026-09-25 00:00:00'), 'corr-2');

        self::assertCount(2, $this->sent, 'a different age is a different cached section');
        self::assertFalse($tomorrow->fromCache);
        self::assertSame(['age' => 74, 'sex' => 'F'], $this->sentBody(0)['patient']);
        self::assertSame(['age' => 75, 'sex' => 'F'], $this->sentBody(1)['patient']);
    }

    public function testAnUnreachableSidecarIsUnavailableAndNothingIsCached(): void
    {
        $outcome = $this->evidence([new Response(503, [], 'down')])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(GuidelineStatus::Unavailable, $outcome->status);
        self::assertSame('unavailable', $outcome->section->status);
        self::assertSame([], $this->cache->entries);
    }

    public function testACriticFailureIsPartialShownAndNotCached(): void
    {
        $outcome = $this->evidence([self::ok($this->reply(null))])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(GuidelineStatus::Partial, $outcome->status);
        self::assertCount(1, $outcome->section->cards, 'the unassessed card is still shown');
        self::assertNull($outcome->section->cards[0]->applicable);
        self::assertSame([], $this->cache->entries, 'a partial section is never cached, so the next open retries');
        self::assertSame([], $outcome->vettedChunks(), 'an unassessed passage never reaches the summary');
    }

    public function testOnlyAPassageTheCriticJudgedApplicableIsOfferedToTheSummary(): void
    {
        $outcome = $this->evidence([self::ok($this->reply(true))])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(['a1b2c3d4e5f6'], array_map(static fn($c): string => $c->chunkId, $outcome->vettedChunks()));
    }

    public function testACardTheCriticRejectedIsDroppedAndTheSectionIsStillBuilt(): void
    {
        $outcome = $this->evidence([self::ok($this->reply(false))])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(GuidelineStatus::Built, $outcome->status, 'every card rejected is a complete answer, not a gap');
        self::assertSame([], $outcome->section->cards);
        self::assertSame(1, $outcome->section->dropped);
        self::assertCount(1, $this->cache->entries);
        self::assertSame([], $outcome->vettedChunks());
    }

    public function testWithoutTheCriticTheCardIsShownUnassessedAndNotCached(): void
    {
        // A sidecar with no model key skips the critic: no verdict on any card.
        $outcome = $this->evidence([self::ok($this->reply(true, false))])->build($this->highLdl(), new PatientId(7), $this->day, 'corr-1');

        self::assertSame(GuidelineStatus::Partial, $outcome->status);
        self::assertCount(1, $outcome->section->cards);
        self::assertNull($outcome->section->cards[0]->applicable);
        self::assertSame([], $this->cache->entries, 'cached, it would stay unassessed after the key is added');
        self::assertSame([], $outcome->vettedChunks());
    }
}
