<?php

/**
 * The log-field allowlist for every Co-Pilot request log line, enforced at
 * runtime (week 2): the PHP counterpart of the sidecar's logging_setup.ALLOWED.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

/**
 * Only these context keys reach a log line. Each carries an id, a count, a
 * code, a timing or a cost, never text read from a chart or a document, so a
 * value the code did not plan to log cannot ride out under a new key.
 *
 * A key not on the list has its value dropped and its name recorded under
 * `dropped_fields`: production never writes the value, and the eval gate's
 * PHI cases (tests/evals/phi.php) fail on any dropped field, so a developer
 * who adds a log field has to add it here on purpose. Only top-level keys are
 * filtered; the nested values of `steps` and `handoffs` are built from fixed
 * keys (Step::toLogContext, the handoff contract).
 */
final class LogFields
{
    public const ALLOWED = [
        // request and identity (ids only)
        'action', 'pid', 'user', 'encounter', 'document_id', 'doc_type', 'correlation_id', 'http_status', 'denied',
        // outcomes and codes
        'status', 'failure_reason', 'code', 'reason', 'exception_class', 'exception_code', 'answer_type', 'chart_changed',
        'verification_pass', 'total_failure', 'eval_outcome', 'error',
        // extraction counts
        'confidence', 'results_persisted', 'unverified', 'unextracted', 'existing', 'sidecar_retries',
        // briefing and answer counts
        'facts', 'stripped', 'omitted', 'kept', 'appended', 'from_cache', 'has_prior_visit', 'hit', 'cache_key',
        // retrieval and routing
        'guideline_chunks', 'retrieved_chunks', 'guideline_status', 'guideline_cards', 'guideline_dropped', 'chunks',
        'handoffs', 'reranked',
        // model calls, timing and cost
        'model', 'model_calls', 'calls', 'attempts', 'llm_attempts', 'llm_retried', 'prompt_tokens', 'completion_tokens',
        'cost_usd', 'ms', 'llm_ms', 'steps', 'step', 'tool',
        // physician rating of the summary (the comment itself is never logged, only its length)
        'rating', 'comment_chars', 'briefing_correlation_id', 'prompt_version',
        // pre-warm (fact ids are hashes; the provider is the clinician's username, like `user`)
        'warm', 'warm_result', 'warm_reason', 'warm_miss_reason', 'warm_receipt_age_s', 'warm_run_id', 'warm_provider',
        'warm_generated_at', 'warm_new_fact_ids', 'warm_gone_fact_ids',
        // this filter's own report
        'dropped_fields',
    ];

    /**
     * The context with every key off the list removed; their names, sorted, under
     * `dropped_fields` when there were any.
     *
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function filter(array $context): array
    {
        $kept = [];
        $dropped = [];
        foreach ($context as $key => $value) {
            if (in_array($key, self::ALLOWED, true)) {
                $kept[$key] = $value;
            } else {
                $dropped[] = (string) $key;
            }
        }
        if ($dropped !== []) {
            sort($dropped);
            $kept['dropped_fields'] = $dropped;
        }
        return $kept;
    }
}
