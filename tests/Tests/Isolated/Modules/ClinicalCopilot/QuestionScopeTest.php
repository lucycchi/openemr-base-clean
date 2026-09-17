<?php

/**
 * QuestionScope refuses follow-ups that name a different patient by number.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\QuestionScope;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuestionScopeTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /**
     * @return array<string, array{string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function questions(): array
    {
        return [
            'another patient by number' => ['What medications is patient 4 taking?', true],
            'pid form' => ['show me pid 4 allergies', true],
            'chart number with hash' => ['open chart #4 please', true],
            'record id form' => ['record id 4: labs?', true],
            'the open patient by number' => ['What is patient 15 taking?', false],
            'no patient reference' => ['Which labs were abnormal?', false],
            'a dose, not a patient' => ['Is 10 mg the right dose?', false],
            'a year, not a patient' => ['What happened at the 2019 visit?', false],
        ];
    }

    #[DataProvider('questions')]
    public function testRefersToAnotherPatient(string $question, bool $expected): void
    {
        self::assertSame($expected, QuestionScope::refersToAnotherPatient($question, new PatientId(15)));
    }
}
