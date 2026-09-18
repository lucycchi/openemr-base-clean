<?php

/**
 * A structured-output language model; OpenAiClient at runtime, a fake in tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

/**
 * The only thing the pipeline knows about "an LLM": give it a system prompt,
 * a user prompt and a JSON schema, get back parsed JSON plus token counts.
 * OpenAiClient is the production implementation; tests use FakeLanguageModel.
 */
interface LanguageModel
{
    /** Model name, e.g. "gpt-4o-mini". Part of the cache key. */
    public function model(): string;

    /**
     * One structured-output call. Must either return a completion whose data
     * matches $schema, or throw a subclass of LlmException.
     *
     * @param array<string, mixed> $schema JSON schema for the strict response
     */
    public function complete(string $system, string $user, string $schemaName, array $schema): LlmCompletion;
}
