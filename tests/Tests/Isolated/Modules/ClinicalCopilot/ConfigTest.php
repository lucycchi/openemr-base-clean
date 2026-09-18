<?php

/**
 * Config reads the module's environment; the pre-warm kill switch is off
 * unless explicitly enabled.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The COPILOT_PREWARM_ENABLED kill switch: off when unset, on only for an
 * explicit truthy value ("1", "true", "yes", "on", case- and
 * whitespace-insensitive). Sets $_ENV directly and cleans up in tearDown.
 */
final class ConfigTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    protected function tearDown(): void
    {
        unset($_ENV['COPILOT_PREWARM_ENABLED']);
    }

    public function testPrewarmIsDisabledWhenTheVariableIsUnset(): void
    {
        unset($_ENV['COPILOT_PREWARM_ENABLED']);

        self::assertFalse(Config::fromEnvironment()->prewarmEnabled);
    }

    /**
     * @return array<string, array{string, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function switchValues(): array
    {
        return [
            'one' => ['1', true],
            'true' => ['true', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
            'TRUE with spaces' => [' TRUE ', true],
            'zero' => ['0', false],
            'false' => ['false', false],
            'empty' => ['', false],
            'garbage' => ['enable', false],
        ];
    }

    #[DataProvider('switchValues')]
    public function testPrewarmSwitchAcceptsOnlyExplicitTruthyValues(string $value, bool $expected): void
    {
        $_ENV['COPILOT_PREWARM_ENABLED'] = $value;

        self::assertSame($expected, Config::fromEnvironment()->prewarmEnabled);
    }
}
