<?php

/**
 * The deployed commit /health reports: taken from the deploy's environment
 * variable only when it is a commit hash.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Ops\DeployedCommit;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeployedCommitTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testAHashIsReportedLowerCased(): void
    {
        self::assertSame('1c822d2', DeployedCommit::parse('1c822d2'));
        self::assertSame('1c822d2e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c', DeployedCommit::parse(" 1C822D2E4F5A6B7C8D9E0F1A2B3C4D5E6F7A8B9C\n"));
    }

    /**
     * Anything that is not a commit hash is reported as unknown, never echoed:
     * /health is public and must not reflect arbitrary environment text.
     */
    #[DataProvider('notAHashProvider')]
    public function testAnythingElseIsUnknown(?string $value): void
    {
        self::assertNull(DeployedCommit::parse($value));
    }

    /**
     * @return array<string, array{?string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function notAHashProvider(): array
    {
        return [
            'unset' => [null],
            'empty' => [''],
            'too short' => ['1c822d'],
            'too long' => [str_repeat('a', 41)],
            'not hex' => ['1c822dz'],
            'a branch name' => ['dashboard-migration'],
            'markup' => ['<script>'],
        ];
    }

    public function testTheHealthContractAcceptsACommitOrNullAndStillAllowsItsAbsence(): void
    {
        self::assertTrue(self::valid(['status' => 'ok', 'service' => 'clinical-copilot', 'time' => '2026-09-27T12:00:00+00:00', 'commit' => '1c822d2']));
        self::assertTrue(self::valid(['status' => 'ok', 'service' => 'clinical-copilot', 'time' => '2026-09-27T12:00:00+00:00', 'commit' => null]));
        self::assertTrue(self::valid(['status' => 'ok', 'service' => 'clinical-copilot', 'time' => '2026-09-27T12:00:00+00:00']));
        self::assertFalse(self::valid(['status' => 'ok', 'service' => 'clinical-copilot', 'time' => '2026-09-27T12:00:00+00:00', 'commit' => 'main']));
    }

    /** @param array<string, mixed> $document */
    private static function valid(array $document): bool
    {
        $validator = new Validator();
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $validator->validate($data, Contracts::schema('health.response'), Constraint::CHECK_MODE_NORMAL);
        return $validator->isValid();
    }
}
