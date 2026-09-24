<?php

/**
 * The metric 6 counts read from the real audit log and rating table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Modules\ClinicalCopilot\DbRatingCounts;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

class DbRatingCountsTest extends TestCase
{
    private const KEY = 'c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0';

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
        $sql = (string) file_get_contents(__DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/sql/0_1_3-to-0_1_4_upgrade.sql');
        $lines = array_filter(explode("\n", $sql), static fn(string $l): bool => !str_starts_with(ltrim($l), '#') && !str_starts_with(ltrim($l), '--'));
        QueryUtils::sqlStatementThrowException(implode("\n", $lines));
    }

    protected function tearDown(): void
    {
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_rating WHERE briefing_cache_key = ?", [self::KEY]);
    }

    public function testABriefAuditRowCountsAsRenderedAndARatingRowCountsOnItsDay(): void
    {
        $counts = new DbRatingCounts(static fn(string $c): string => $c);
        $from = new \DateTimeImmutable('today');
        $to = $from->modify('+1 day');
        $today = $from->format('Y-m-d');
        $renderedBefore = $counts->renderedByDay($from, $to)[$today] ?? 0;
        $ratedBefore = $counts->ratingsByDay($from, $to)[$today] ?? ['up' => 0, 'down' => 0];

        // One briefing rendered, one follow-up (not counted), one rating.
        EventAuditLogger::getInstance()->newEvent('clinical-copilot', 'admin', 'Default', 1, 'action=brief correlation_id=test facts=3', 1);
        EventAuditLogger::getInstance()->newEvent('clinical-copilot', 'admin', 'Default', 1, 'action=ask correlation_id=test facts=3', 1);
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO copilot_briefing_rating (pid, user_id, briefing_cache_key, prompt_version, model, correlation_id, rating) VALUES (1, 1, ?, 'v-test', 'm-test', ?, 'up')",
            [self::KEY, str_repeat('a', 32)]
        );

        self::assertSame($renderedBefore + 1, $counts->renderedByDay($from, $to)[$today] ?? 0, 'the base64 audit comment is decoded; ask is not a rendered briefing');
        self::assertSame(['up' => $ratedBefore['up'] + 1, 'down' => $ratedBefore['down']], $counts->ratingsByDay($from, $to)[$today] ?? null);
        $versions = array_values(array_filter($counts->ratingsByVersion($from, $to), static fn(array $v): bool => $v['prompt_version'] === 'v-test'));
        self::assertSame([['prompt_version' => 'v-test', 'model' => 'm-test', 'up' => 1, 'down' => 0]], $versions);
    }
}
