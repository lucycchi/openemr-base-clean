<?php

/**
 * Panel endpoint: session-bound patient, CSRF, ACL, then brief or answer.
 *
 * The patient id comes from the OpenEMR session only; a pid in the request
 * is ignored. Every response carries the correlation id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use GuzzleHttp\Client;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\SqlQueryException;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\AccessDeniedException;
use OpenEMR\Modules\ClinicalCopilot\AclAuthorization;
use OpenEMR\Modules\ClinicalCopilot\AnswerRoute;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingPipelineFactory;
use OpenEMR\Modules\ClinicalCopilot\BriefingRating;
use OpenEMR\Modules\ClinicalCopilot\BriefingRatings;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\ChatAction;
use OpenEMR\Modules\ClinicalCopilot\ChatRequest;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\DbBriefingCache;
use OpenEMR\Modules\ClinicalCopilot\DbBriefingRatings;
use OpenEMR\Modules\ClinicalCopilot\DbPrewarmReceipts;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarException;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\GuidelineManifest;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineTriggers;
use OpenEMR\Modules\ClinicalCopilot\InvalidRequest;
use OpenEMR\Modules\ClinicalCopilot\NarrationPipeline;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\Ops\CorrelatedLogger;
use OpenEMR\Modules\ClinicalCopilot\Ops\LangfuseTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\NullTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Score;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\PrewarmReceipts;
use OpenEMR\Modules\ClinicalCopilot\RatedBriefing;
use OpenEMR\Modules\ClinicalCopilot\RatingSubmission;
use OpenEMR\Modules\ClinicalCopilot\Pricing;
use OpenEMR\Modules\ClinicalCopilot\Prompt;
use OpenEMR\Modules\ClinicalCopilot\VerificationResult;
use OpenEMR\Modules\ClinicalCopilot\WarmOutcome;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP handler behind public/chat.php. One instance per request. It does the
 * web-layer work — session, CSRF, ACL, parsing, JSON response — and then
 * delegates to FactAssembler and NarrationPipeline for the real logic.
 * Every exit path (success, refusal, crash) produces three things: a JSON
 * body with a correlation id, a structured log line, and a trace.
 *
 * Constructor arguments are all optional so production can `new` it with
 * no arguments while tests inject a fake request, logger and tracer. The
 * last two let the eval harness replay recorded model and sidecar replies
 * (tests/evals/phi.php); left out, the pipeline factory and the sidecar
 * client are built from the per-request Config exactly where they are used.
 */
final class ChatController
{
    private readonly string $correlationId;
    private readonly LoggerInterface $logger;
    private readonly Request $request;
    private readonly Config $config;
    private readonly Tracer $tracer;
    private readonly StepRecorder $steps;
    // Mutable per-request bookkeeping, filled in by brief()/ask() and read
    // when the trace and audit entry are built at the end of handle().
    private int $llmMs = 0;
    private bool $llmCalled = false;
    private int $llmAttempts = 0;
    /**
     * Week 2: the sidecar's routing decisions for this question (empty for a briefing).
     *
     * @var list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>
     */
    private array $handoffs = [];

    /** Guideline passages retrieval returned for this request; null when retrieval did not run. */
    private ?int $retrievedChunks = null;
    /**
     * Week 2: the sidecar's model calls for this question (embedding, rerank), priced, for the trace.
     *
     * @var list<array{model: string, kind: string, input: int, output: int, cost_usd: ?float}>
     */
    private array $sidecarUsage = [];
    private readonly PrewarmReceipts $receipts;
    private readonly BriefingRatings $ratings;
    private ?WarmOutcome $warm = null;

    public function __construct(
        ?LoggerInterface $logger = null,
        ?Request $request = null,
        ?Config $config = null,
        ?Tracer $tracer = null,
        ?PrewarmReceipts $receipts = null,
        private readonly ?BriefingPipelineFactory $pipelines = null,
        private readonly ?SidecarClient $sidecar = null,
        ?BriefingRatings $ratings = null,
    ) {
        $this->correlationId = CorrelationId::generate();
        $this->logger = new CorrelatedLogger($logger ?? ServiceContainer::getLogger(), $this->correlationId);
        $this->request = $request ?? HttpRestRequest::createFromGlobals();
        $this->config = $config ?? Config::fromEnvironment();
        $this->receipts = $receipts ?? new DbPrewarmReceipts($this->config->openAiModel);
        $this->ratings = $ratings ?? new DbBriefingRatings();
        $this->steps = new StepRecorder();
        $this->tracer = $tracer ?? ($this->config->hasLangfuse()
            ? new LangfuseTracer(new Client(), $this->config->langfuseHost, $this->config->langfusePublicKey, $this->config->langfuseSecretKey)
            : new NullTracer());
    }

    /** Public entry point: wraps handle() in a last-resort catch so a crash still yields JSON + a trace. */
    public function handleRequest(): void
    {
        $started = hrtime(true);
        $startedAtMs = (int) round(microtime(true) * 1000);
        try {
            $this->handle($started, $startedAtMs);
        } catch (\Throwable $e) {
            // Anything unexpected still gets a correlation id, a log line with
            // the exception, a trace with the failed step, and a JSON body;
            // then it propagates so the failure is never swallowed.
            $this->logger->error('copilot request failed', ['exception_class' => $e::class, 'exception_code' => $e->getCode(), 'steps' => $this->stepSummary()]);
            $this->tracer->record(new RequestTrace(
                $this->correlationId,
                'copilot.' . $this->request->request->getString('action'),
                '',
                $startedAtMs,
                (int) round((hrtime(true) - $started) / 1e6),
                ['http_status' => 500],
                null,
                0,
                0,
                0,
                StepRecorder::describe($e),
                $this->steps->all(),
            ));
            $this->respond(['error' => 'The Co-Pilot hit an internal error', 'correlation_id' => $this->correlationId], 500);
            throw $e;
        }
    }

    /**
     * The request pipeline, in order: parse body -> CSRF -> logged in? ->
     * patient in session? -> assemble facts (ACL) -> brief or ask ->
     * log + audit + trace -> respond. Each check returns early with the
     * right status; nothing later runs on a failed check.
     */
    private function handle(int|float $started, int $startedAtMs): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $user = $session->get('authUser');
        $user = is_string($user) ? $user : '';

        // Parse the body against contracts/chat.request.schema.json before
        // anything else; a body that violates it never reaches the chart.
        try {
            $chat = ChatRequest::fromBag($this->request->request);
        } catch (InvalidRequest $e) {
            $this->respond(['error' => $e->getMessage(), 'correlation_id' => $this->correlationId], $e->httpStatus);
            return;
        }
        if (!CsrfUtils::verifyCsrfToken($chat->csrfToken, session: $session)) {
            $this->respond(['error' => 'CSRF verification failed', 'correlation_id' => $this->correlationId], 403);
            return;
        }
        if ($user === '') {
            $this->respond(['error' => 'Not authenticated', 'correlation_id' => $this->correlationId], 401);
            return;
        }
        // The patient comes from the server-side session (the chart the user
        // has open), never from the request body — the client cannot pick a pid.
        $pidValue = PatientSessionUtil::getPid();
        if ($pidValue <= 0) {
            $this->respond(['error' => 'No patient selected', 'correlation_id' => $this->correlationId], 400);
            return;
        }
        $pid = new PatientId($pidValue);
        $encounter = EncounterSessionUtil::getEncounter();
        $action = $chat->action->value;

        $this->logger->notice('copilot request', [
            'action' => $action,
            'pid' => $pid->value,
            'encounter' => $encounter,
            'user' => $user,
        ]);
        $authProvider = is_string($session->get('authProvider')) ? $session->get('authProvider') : '';

        // A rating needs the chart ACL but no facts and no model: its own short path.
        if ($chat->action === ChatAction::Rate) {
            $userId = $session->get('authUserID');
            $this->rate($chat->rating, $pid, $encounter > 0 ? $encounter : null, $user, is_numeric($userId) ? (int) $userId : 0, $authProvider, $started, $startedAtMs);
            return;
        }

        try {
            $assembled = $this->steps->measure(
                'authorize_and_assemble_facts',
                fn() => (new FactAssembler(new OpenEmrChartSource(), new AclAuthorization($user), ServiceContainer::getClock()))->assemble($pid, $encounter > 0 ? $encounter : null),
                static fn(AssembledFacts $a) => ['facts' => count($a->facts()->all()), 'has_prior_visit' => $a->priorEncounter() !== null],
            );
        } catch (AccessDeniedException) {
            $this->refuseAccess($action, $pid, $user, $authProvider, $started, $startedAtMs);
            return;
        }

        // Dispatch on the enum; `match` with no default means PHPStan flags a
        // new ChatAction case that is not handled here. Rate returned above.
        $config = $this->config;
        $payload = match ($chat->action) {
            ChatAction::Brief => $this->brief($assembled, $config, $pid, $user),
            ChatAction::Ask => $this->ask($chat, $assembled, $config, $pid),
        };
        // The route this briefing or answer took (the graph's hops, then the answer stage),
        // for the panel's "Why this result" drawer. Fixed codes and timings only.
        if (isset($payload['narration']) || isset($payload['answer'])) {
            $payload['handoffs'] = $this->handoffs;
        }

        // From here down is bookkeeping: pull the numbers out of the payload
        // (narrowing each with is_*), compute cost, then emit log/audit/trace.
        $outcome = $payload['narration'] ?? $payload['answer'] ?? null;
        $outcome = is_array($outcome) ? $outcome : [];
        $httpStatus = isset($payload['error']) ? 400 : 200;
        $totalMs = (int) round((hrtime(true) - $started) / 1e6);
        $tokens = is_array($outcome['tokens'] ?? null) ? $outcome['tokens'] : [];
        $promptTokens = is_int($tokens['prompt'] ?? null) ? $tokens['prompt'] : 0;
        $completionTokens = is_int($tokens['completion'] ?? null) ? $tokens['completion'] : 0;
        $costUsd = Pricing::totalCost($this->sidecarUsage, $this->llmCalled ? Pricing::fromConfig($config)->costUsd($config->openAiModel, $promptTokens, $completionTokens) : 0.0);
        $status = is_string($outcome['status'] ?? null) ? $outcome['status'] : null;
        $metadata = [
            'action' => $action,
            // The encounter the chart was open on (0 when none): KEY_METRICS.md metric 7
            // counts chat use per encounter from the audit row below, and the log line
            // and trace carry it too so all three records agree.
            'encounter' => $encounter,
            'http_status' => $httpStatus,
            'facts' => count($assembled->facts()->all()),
            'stripped' => is_int($outcome['stripped'] ?? null) ? $outcome['stripped'] : null,
            'omitted' => is_array($outcome['omitted_fact_ids'] ?? null) ? count($outcome['omitted_fact_ids']) : null,
            'from_cache' => is_bool($outcome['from_cache'] ?? null) ? $outcome['from_cache'] : null,
            'total_failure' => is_bool($outcome['total_failure'] ?? null) ? $outcome['total_failure'] : null,
            'answer_type' => is_string($outcome['type'] ?? null) ? $outcome['type'] : null,
            'chart_changed' => is_bool($payload['chart_changed'] ?? null) ? $payload['chart_changed'] : null,
            'verification_pass' => $status === null && ($outcome['total_failure'] ?? false) !== true,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => $costUsd,
            'llm_attempts' => $this->llmAttempts,
            'llm_retried' => $this->llmAttempts > 1,
            // Retrieval hits: how many guideline passages retrieval returned for this
            // encounter, and how many of them the final response cites.
            'retrieved_chunks' => $this->retrievedChunks,
            'guideline_chunks' => is_array($outcome['guidelines'] ?? null) ? count($outcome['guidelines']) : self::citedGuidelineChunks($outcome),
            'handoffs' => $this->handoffs,
            // The encounter's eval outcome: the verifier's final verdict on this briefing or answer.
            'eval_outcome' => AnswerRoute::outcomeOf($this->handoffs),
            'reranked' => array_filter($this->sidecarUsage, static fn(array $u): bool => $u['kind'] === 'rerank') !== [],
        ] + ($this->warm?->toLogContext() ?? []);
        // One line per failed tool with the real reason (the user-facing
        // status label above is deliberately vague), then one line per request
        // with the ordered steps and their timings.
        foreach ($this->steps->failed() as $failed) {
            $this->logger->warning('copilot tool failed', $failed->toLogContext());
        }
        $this->logger->notice('copilot response', $metadata + ['ms' => $totalMs, 'llm_ms' => $this->llmMs, 'status' => $status, 'steps' => $this->stepSummary()]);
        // OpenEMR's audit log is the always-on, HIPAA-facing record of who ran
        // the agent on which patient. Comment holds no PHI: ids and counts only.
        EventAuditLogger::getInstance()->newEvent(
            'clinical-copilot',
            $user,
            $authProvider,
            $httpStatus === 200 ? 1 : 0,
            sprintf('action=%s correlation_id=%s encounter_id=%d facts=%d stripped=%s from_cache=%s tokens=%d cost_usd=%s llm_attempts=%d status=%s', $action, $this->correlationId, $encounter, $metadata['facts'], var_export($metadata['stripped'], true), var_export($metadata['from_cache'], true), $promptTokens + $completionTokens, $costUsd === null ? 'unknown' : number_format($costUsd, 6, '.', ''), $this->llmAttempts, $status ?? 'ok'),
            $pid->value
        );
        $this->tracer->record(new RequestTrace(
            $this->correlationId,
            "copilot.$action",
            $user,
            $startedAtMs,
            $totalMs,
            $metadata,
            $this->llmCalled ? $config->openAiModel : null,
            $promptTokens,
            $completionTokens,
            $this->llmMs,
            $status,
            $this->steps->all(),
            $costUsd,
            $this->sidecarUsage,
        ));
        $this->respond($payload, $httpStatus);
    }

    /**
     * The same refusal for every action when the chart ACL says no: a warning,
     * an audit row marked denied, a trace, and a 403.
     */
    private function refuseAccess(string $action, PatientId $pid, string $user, string $authProvider, int|float $started, int $startedAtMs): void
    {
        $this->logger->warning('copilot access denied', ['user' => $user, 'steps' => $this->stepSummary()]);
        EventAuditLogger::getInstance()->newEvent('clinical-copilot', $user, $authProvider, 0, 'action=' . $action . ' correlation_id=' . $this->correlationId . ' denied=acl', $pid->value);
        $this->tracer->record(new RequestTrace($this->correlationId, "copilot.$action", $user, $startedAtMs, (int) round((hrtime(true) - $started) / 1e6), ['http_status' => 403, 'denied' => true], null, 0, 0, 0, 'access denied', $this->steps->all()));
        $this->respond(['error' => 'You are not authorized to view this chart', 'correlation_id' => $this->correlationId], 403);
    }

    /**
     * action=rate: the physician's thumbs up or down (and optional comment)
     * on the AI summary a briefing showed (KEY_METRICS.md metric 6). Same
     * CSRF, session patient and chart ACL as a briefing; no facts are
     * assembled and no model runs. The rating is stored against the cached
     * narration of this patient that the panel names, then logged and
     * audited with the comment's length only, and sent to the briefing's
     * trace as the physician_rating score. The comment itself stays in
     * copilot_briefing_rating: it is free text and may name the patient.
     */
    private function rate(?RatingSubmission $rating, PatientId $pid, ?int $encounterId, string $user, int $userId, string $authProvider, int|float $started, int $startedAtMs): void
    {
        if ($rating === null) {
            throw new \LogicException('action=rate parsed without a rating');
        }
        try {
            FactAssembler::authorizeChart(new AclAuthorization($user));
        } catch (AccessDeniedException) {
            $this->refuseAccess('rate', $pid, $user, $authProvider, $started, $startedAtMs);
            return;
        }
        $context = [
            'action' => 'rate',
            'rating' => $rating->rating->value,
            'comment_chars' => $rating->commentChars(),
            'cache_key' => $rating->cacheKey,
            'briefing_correlation_id' => $rating->briefingCorrelationId,
        ];
        $briefing = $this->steps->measure('find_briefing', fn() => $this->ratings->briefing($pid, $rating->cacheKey), static fn(?RatedBriefing $b) => ['hit' => $b !== null]);
        if ($briefing === null) {
            // Not one of this patient's cached summaries: a stale panel, or a key
            // from another chart. Nothing is stored.
            $context += ['http_status' => 409, 'reason' => 'unknown_briefing'];
            $this->logger->warning('copilot rating refused', $context);
            $this->tracer->record(new RequestTrace($this->correlationId, 'copilot.rate', $user, $startedAtMs, (int) round((hrtime(true) - $started) / 1e6), $context, null, 0, 0, 0, null, $this->steps->all()));
            $this->respond(['error' => 'This summary can no longer be rated. Reload the panel and rate the current one.', 'correlation_id' => $this->correlationId], 409);
            return;
        }
        $this->steps->measure('store_rating', fn() => $this->ratings->record($pid, $encounterId, $userId, $rating, $briefing));
        // The score's id carries the user, so rating again replaces it, as the row is replaced.
        $this->tracer->score(new Score(
            $rating->briefingCorrelationId . '-score-physician_rating-u' . $userId,
            $rating->briefingCorrelationId,
            'physician_rating',
            $rating->rating === BriefingRating::Up,
            $rating->comment === null ? null : sprintf('Physician comment stored in the EHR (%d characters).', $rating->commentChars()),
        ));
        $context += ['http_status' => 200, 'prompt_version' => $briefing->promptVersion, 'model' => $briefing->model];
        $totalMs = (int) round((hrtime(true) - $started) / 1e6);
        $this->logger->notice('copilot rating', $context + ['ms' => $totalMs, 'steps' => $this->stepSummary()]);
        // Ids, the rating and a length only: the comment never goes in the audit log.
        EventAuditLogger::getInstance()->newEvent(
            'clinical-copilot',
            $user,
            $authProvider,
            1,
            sprintf('action=rate correlation_id=%s briefing_correlation_id=%s cache_key=%s rating=%s comment_chars=%d prompt_version=%s', $this->correlationId, $rating->briefingCorrelationId, $rating->cacheKey, $rating->rating->value, $rating->commentChars(), $briefing->promptVersion),
            $pid->value
        );
        $this->tracer->record(new RequestTrace($this->correlationId, 'copilot.rate', $user, $startedAtMs, $totalMs, $context, null, 0, 0, 0, null, $this->steps->all()));
        $this->respond(PanelPayload::rated($rating, $this->correlationId), 200);
    }

    /**
     * action=brief. Records the pre-warm hit/miss outcome, then runs the
     * pipeline (which serves from cache when it can).
     *
     * @return array<string, mixed>
     */
    private function brief(AssembledFacts $assembled, Config $config, PatientId $pid, string $user): array
    {
        // The guideline section needs no chat model: retrieval runs in the sidecar
        // and the critic is the sidecar's own call. It is built before the
        // narration so an unconfigured or failed model still leaves it on the page.
        $guidelines = $this->steps->measure(
            'retrieve_chart_evidence',
            fn() => $this->guidelineSection($assembled, $config, $pid),
            static fn(GuidelineSection $g) => ['guideline_status' => $g->status, 'guideline_cards' => count($g->cards), 'guideline_dropped' => $g->dropped],
        );
        // The passages on the guideline cards (fresh from the sidecar or from the cache);
        // null when no guideline rule fired or the sidecar was unavailable.
        $this->retrievedChunks = $guidelines->status === 'ok' ? count($guidelines->chunks()) : null;
        if (!$config->hasOpenAi()) {
            return PanelPayload::briefing($assembled, $this->unconfigured($assembled), $this->correlationId, $guidelines);
        }
        $this->warm = $this->steps->measure(
            'warm_lookup',
            fn() => $this->warmOutcome($assembled, $config, $pid, $user),
            static fn(?WarmOutcome $w) => $w?->toLogContext() ?? ['warm_result' => null],
        );
        // The surviving cards' passages are offered to the narration under the same
        // contract as an answer's evidence: cited by chunk id, numbers verified.
        $chunks = [];
        foreach ($guidelines->cards as $card) {
            foreach ($card->chunks as $chunk) {
                $chunks[] = $chunk;
            }
        }
        $t = hrtime(true);
        $pipeline = $this->pipeline($config, $assembled, $pid);
        $result = $pipeline->brief($assembled, new EvidenceSet($chunks));
        $this->llmMs = (int) round((hrtime(true) - $t) / 1e6);
        $this->llmCalled = !$result->fromCache;
        $this->llmAttempts = $pipeline->llmAttempts();
        // The answer stage joins the graph's hops, so the log shows when the briefing was ready.
        $this->handoffs = [...$this->handoffs, ...AnswerRoute::forBriefing($result, $this->llmMs)];
        return PanelPayload::briefing($assembled, $result, $this->correlationId, $guidelines);
    }

    /**
     * What the guidelines say about this chart: fire the trigger rules,
     * serve a cached section when the facts and rules are unchanged, else
     * ask the sidecar (retrieval plus critic) and cache the result. A sidecar
     * that cannot be reached yields an "unavailable" section, never an error.
     */
    private function guidelineSection(AssembledFacts $assembled, Config $config, PatientId $pid): GuidelineSection
    {
        $now = ServiceContainer::getClock()->now();
        $who = (new OpenEmrChartSource())->demographics($pid);
        $fired = (new GuidelineTriggers())->fire($assembled, $who, $now);
        if ($fired === []) {
            return GuidelineSection::none('no_triggers');
        }
        $factsHash = $assembled->facts()->hash();
        $age = $who->ageOn($now);
        $key = GuidelineTriggers::cacheKey($factsHash, $fired, $age, $who->sex, $config->openAiModel, GuidelineTriggers::indexVersion());
        $cache = new DbBriefingCache($pid, $factsHash, $config->openAiModel);
        $hit = $cache->get($key);
        if ($hit !== null) {
            try {
                return GuidelineSection::fromArray($hit->data);
            } catch (\RuntimeException $e) {
                // A stale or malformed cached section (fromArray throws RuntimeException): rebuild it.
                $this->logger->warning('copilot guideline cache entry unreadable; rebuilding', ['exception_class' => $e::class]);
            }
        }
        // Every fired trigger carries its own fact lines plus the problem list for the
        // critic; the run-level list is the union, for an older sidecar.
        $lines = [];
        foreach ($fired as $trigger) {
            foreach ($trigger->contextLines as $line) {
                $lines[] = $line;
            }
        }
        try {
            $run = ($this->sidecar ?? SidecarClient::fromConfig($config))->brief($this->correlationId, $factsHash, $fired, array_values(array_unique($lines)), $age, $who->sex);
        } catch (SidecarException $e) {
            $this->logger->warning('copilot guideline evidence unavailable; briefing without it', ['code' => $e->errorCode]);
            return GuidelineSection::none('unavailable');
        }
        $this->handoffs = array_map(static fn($h) => $h->toArray(), $run->handoffs);
        $this->sidecarUsage = Pricing::fromConfig($config)->priceUsage($run->usage, $config->openAiModel);
        $section = GuidelineSection::fromRun($fired, $run, new GuidelineManifest());
        // A run that did not finish (a worker failed, a verdict unknown) is shown but
        // not cached, so the next open tries again instead of keeping the gap.
        if ($section->cacheable) {
            $cache->put($key, $section->toArray());
        }
        return $section;
    }

    private function warmOutcome(AssembledFacts $assembled, Config $config, PatientId $pid, string $user): ?WarmOutcome
    {
        $today = ServiceContainer::getClock()->now()->format('Y-m-d');
        try {
            $receipt = $this->receipts->latestFor($today, $pid, $user);
        } catch (SqlQueryException $e) {
            // The receipts table is optional bookkeeping (it arrived in 0.1.1;
            // a site enabled at 0.1.0 will not have it until Module Manager >
            // Upgrade runs). A missing or broken table must not take the
            // briefing down: log it, score nothing, and carry on.
            $this->logger->warning('copilot warm lookup failed; pre-warm receipts unavailable', ['exception_class' => $e::class]);
            return null;
        }
        if ($receipt === null && !$config->prewarmEnabled) {
            return null;
        }
        $outcome = WarmOutcome::evaluate($receipt, $assembled, $user, Prompt::VERSION, $config->openAiModel);
        $this->logger->notice('copilot warm', $outcome->toLogContext() + ['pid' => $pid->value, 'user' => $user]);
        return $outcome;
    }

    /**
     * action=ask.
     *
     * @return array<string, mixed>
     */
    private function ask(ChatRequest $chat, AssembledFacts $assembled, Config $config, PatientId $pid): array
    {
        // Stale-conversation guard: the panel sends the facts_hash it was
        // briefed with. If the chart changed since (new lab filed, etc.), the
        // answer would be based on facts the clinician has not seen — refuse
        // and tell the panel to re-brief.
        if ($chat->factsHash !== $assembled->facts()->hash()) {
            return PanelPayload::chartChanged($assembled, $this->correlationId);
        }
        if (!$config->hasOpenAi()) {
            return ['error' => 'AI is not configured on this server', 'correlation_id' => $this->correlationId];
        }
        // Week 2: ask the sidecar's graph for guideline evidence first. The
        // supervisor routes the question to the evidence_retriever; the chunks
        // come back with citations and the handoff log. A sidecar outage
        // degrades to a facts-only answer rather than failing the question.
        $evidence = EvidenceSet::none();
        $handoffs = [];
        try {
            $run = $this->steps->measure(
                'retrieve_evidence',
                fn() => ($this->sidecar ?? SidecarClient::fromConfig($config))->answer($this->correlationId, $assembled->facts()->hash(), (string) $chat->question),
                static fn($r) => ['chunks' => count($r->chunks), 'handoffs' => count($r->handoffs)],
            );
            $evidence = EvidenceSet::fromRun($run->chunks, new GuidelineManifest());
            $this->retrievedChunks = count($run->chunks);
            $handoffs = array_map(static fn($h) => $h->toArray(), $run->handoffs);
            $this->sidecarUsage = Pricing::fromConfig($config)->priceUsage($run->usage, $config->openAiModel);
        } catch (SidecarException $e) {
            $this->logger->warning('copilot evidence retrieval unavailable; answering from facts only', ['code' => $e->errorCode]);
        }
        $t = hrtime(true);
        $pipeline = $this->pipeline($config, $assembled, $pid);
        $answer = $pipeline->answer($assembled, (string) $chat->question, $chat->transcript, $pid, $evidence);
        $this->llmMs = (int) round((hrtime(true) - $t) / 1e6);
        $this->llmCalled = true;
        $this->llmAttempts = $pipeline->llmAttempts();
        // The answer stage joins the graph's hops, so the log shows when the answer was ready.
        $this->handoffs = [...$handoffs, ...AnswerRoute::forAnswer($answer, $this->llmMs, $this->llmAttempts)];
        return PanelPayload::answer($assembled, $answer, $this->correlationId);
    }

    /**
     * Distinct guideline passages the briefing's kept sentences cite, read from the
     * sentences' citations; null when the outcome has no sentences (an error).
     *
     * @param array<mixed> $outcome
     */
    private static function citedGuidelineChunks(array $outcome): ?int
    {
        if (!is_array($outcome['sentences'] ?? null)) {
            return null;
        }
        $ids = [];
        foreach ($outcome['sentences'] as $sentence) {
            $citations = is_array($sentence) && is_array($sentence['citations'] ?? null) ? $sentence['citations'] : [];
            foreach ($citations as $c) {
                if (is_array($c) && ($c['source_type'] ?? null) === 'guideline' && is_string($c['field_or_chunk_id'] ?? null)) {
                    $ids[$c['field_or_chunk_id']] = true;
                }
            }
        }
        return count($ids);
    }

    private function pipeline(Config $config, AssembledFacts $assembled, PatientId $pid): NarrationPipeline
    {
        return ($this->pipelines ?? new BriefingPipelineFactory())->create($config, $assembled, $pid, $this->correlationId, $this->steps);
    }

    /** @return list<array<string, scalar|null>> */
    private function stepSummary(): array
    {
        return array_map(static fn($s) => $s->toLogContext(), $this->steps->all());
    }

    /** BriefingResult for a server with no API key: empty narration, but the omission guard still lists must-surface facts. */
    private function unconfigured(AssembledFacts $assembled): BriefingResult
    {
        $omitted = (new OmissionGuard())->omitted(new VerificationResult([], []), $assembled->facts());
        return new BriefingResult([], 0, $omitted, 'AI summary unavailable: not configured on this server', false, false, 0, 0);
    }

    /**
     * Writes the JSON response. Every response carries X-Correlation-Id so a user report can be matched to logs.
     *
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Correlation-Id: ' . $this->correlationId);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
