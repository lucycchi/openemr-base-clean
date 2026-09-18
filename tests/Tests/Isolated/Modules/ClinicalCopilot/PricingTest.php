<?php

/**
 * Pricing converts token counts to USD from list prices or an operator override.
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
use OpenEMR\Modules\ClinicalCopilot\Pricing;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Pricing: list-price maths for a known model, null (not 0) for an unknown
 * one, and Config overrides taking precedence over the built-in table.
 */
final class PricingTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testListPriceForGpt4oMini(): void
    {
        // 1,000,000 input at $0.15 + 1,000,000 output at $0.60
        self::assertSame(0.75, (new Pricing())->costUsd('gpt-4o-mini', 1_000_000, 1_000_000));
        // A typical briefing: 900 prompt + 350 completion tokens
        self::assertSame(0.000345, (new Pricing())->costUsd('gpt-4o-mini', 900, 350));
    }

    public function testUnknownModelWithoutOverrideIsUnknownCostNotZero(): void
    {
        self::assertNull((new Pricing())->costUsd('some-future-model', 900, 350));
    }

    public function testOverrideFromConfigWinsOverTheListPrice(): void
    {
        $config = new Config('key', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', 1.0, 2.0);

        self::assertSame(3.0, Pricing::fromConfig($config)->costUsd('gpt-4o-mini', 1_000_000, 1_000_000));
        self::assertSame(3.0, Pricing::fromConfig($config)->costUsd('some-future-model', 1_000_000, 1_000_000));
    }
}
