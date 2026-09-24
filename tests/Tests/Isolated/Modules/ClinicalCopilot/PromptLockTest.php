<?php

/**
 * Pins the text the Co-Pilot sends to the model.
 *
 * The eval gate's deterministic cases replay recorded model replies, so an
 * edit to a prompt (deleting "cite every sentence", say) changes nothing they
 * see and would pass the push. This test renders every prompt on a fixed
 * input and compares its sha256 with prompts.lock.json beside it: any change
 * to the rendered text fails until the lock is rewritten on purpose.
 *
 * It is a pin, not an eval: it cannot tell a good prompt edit from a bad one.
 * What it forces is the step that decides that: re-record the replies the
 * deterministic cases use, run the live cases, then rewrite the lock with
 *
 *   docker exec -e UPDATE_PROMPT_LOCK=1 <openemr container> sh -c \
 *     'cd /var/www/localhost/htdocs/openemr && vendor/bin/phpunit -c phpunit-isolated.xml --filter PromptLockTest'
 *
 * The sidecar's prompts are pinned the same way by its own
 * tests/test_prompt_lock.py.
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
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Prompt;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptLockTest extends TestCase
{
    private const LOCK = __DIR__ . '/prompts.lock.json';

    #[DataProvider('prompts')]
    public function testRenderedPromptMatchesTheLock(string $name): void
    {
        $rendered = self::render()[$name];
        $hash = hash('sha256', $rendered);
        $lock = self::readLock();

        if (getenv('UPDATE_PROMPT_LOCK') === '1') {
            $lock[$name] = $hash;
            ksort($lock);
            file_put_contents(self::LOCK, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            self::assertSame($hash, self::readLock()[$name]);
            return;
        }

        self::assertArrayHasKey($name, $lock, "PROMPT NOT LOCKED: $name. Run this test with UPDATE_PROMPT_LOCK=1 to add it.");
        self::assertSame(
            $lock[$name],
            $hash,
            "PROMPT CHANGED: $name. The deterministic eval cases replay recorded replies and cannot see a prompt change. "
            . 'Re-record the replies the cases use, run the live cases (COPILOT_GATE_LIVE=1), then rewrite the lock with UPDATE_PROMPT_LOCK=1 (see this file\'s header).'
        );
    }

    public function testTheLockHasNoEntryForAPromptThatNoLongerExists(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::readLock()), array_keys(self::render()))));
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function prompts(): array
    {
        $names = array_keys(self::render());
        return array_combine($names, array_map(static fn(string $n): array => [$n], $names));
    }

    /**
     * Every message the PHP side sends, rendered on one fixed input. Changing
     * the input changes every hash, so leave it as it is.
     *
     * @return array<string, string>
     */
    private static function render(): array
    {
        // Runs from the data provider too, before setUp, so the module's classes are registered here.
        ModuleAutoload::register();
        $prompt = new Prompt();
        $assembled = new AssembledFacts(new FactSet([
            new Fact('rx0001', 'PrescriptionService', 17, 'drug', 'Lisinopril 10 MG Oral Tablet', FactCategory::MedicationNew),
            new Fact('lb0001', 'ProcedureResult', 42, 'result', 'LDL 190 mg/dL (2026-09-01)', FactCategory::LabAbnormal),
            new Fact('en0001', 'EncounterService', 100, 'reason', '2026-09-09: Follow-up', FactCategory::Encounter),
        ]), new EncounterRecord(99, new DateTimeImmutable('2026-09-01 10:00:00'), '', 'Follow-up'));
        $evidence = new EvidenceSet([new EvidenceChunk(
            'a1b2c3d4e5f6',
            'acc-aha-2018-cholesterol',
            '2018 AHA/ACC cholesterol guideline (summary) > Statin therapy',
            'In adults 40 to 75 years of age with LDL-C 70 to 189 mg/dL, moderate-intensity statin therapy is recommended.',
            0.9,
            '2018 AHA/ACC Guideline on the Management of Blood Cholesterol (summary)',
        )]);
        $transcript = [['role' => 'physician', 'text' => 'Any new medications?'], ['role' => 'assistant', 'text' => 'Lisinopril was started.']];

        return [
            'briefing_system' => $prompt->briefingSystem(),
            'briefing_user' => $prompt->briefingUser($assembled),
            'briefing_user_with_evidence' => $prompt->briefingUser($assembled, $evidence),
            'followup_system' => $prompt->followUpSystem(),
            'followup_user' => $prompt->followUpUser($assembled, 'Does the LDL need a statin?', $transcript, $evidence),
        ];
    }

    /** @return array<string, string> */
    private static function readLock(): array
    {
        if (!is_file(self::LOCK)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents(self::LOCK), true, 8, JSON_THROW_ON_ERROR);
        $lock = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $hash) {
            if (is_string($name) && is_string($hash)) {
                $lock[$name] = $hash;
            }
        }
        return $lock;
    }
}
