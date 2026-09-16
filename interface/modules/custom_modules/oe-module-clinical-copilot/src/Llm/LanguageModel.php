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

interface LanguageModel
{
    public function model(): string;

    /** @param array<string, mixed> $schema JSON schema for the strict response */
    public function complete(string $system, string $user, string $schemaName, array $schema): LlmCompletion;
}
