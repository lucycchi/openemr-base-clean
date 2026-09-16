<?php

/**
 * Scripted language model for pipeline tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use OpenEMR\Modules\ClinicalCopilot\Llm\LanguageModel;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmCompletion;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmException;

final class FakeLanguageModel implements LanguageModel
{
    /** @var array<string, mixed> */
    public array $reply = [];
    public ?LlmException $throw = null;
    public int $calls = 0;
    public string $lastSystem = '';
    public string $lastUser = '';
    public string $lastSchemaName = '';

    public function model(): string
    {
        return 'fake-model';
    }

    public function complete(string $system, string $user, string $schemaName, array $schema): LlmCompletion
    {
        $this->calls++;
        $this->lastSystem = $system;
        $this->lastUser = $user;
        $this->lastSchemaName = $schemaName;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return new LlmCompletion($this->reply, 10, 5);
    }
}
