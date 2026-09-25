<?php

/**
 * DB-backed pin for KEY_METRICS.md metric 7's two queries: the physician's
 * encounters from form_encounter, and the questions asked on each from the
 * Co-Pilot's audit rows. Only a successful action=ask row that names an
 * encounter counts; the auto-rendered briefing, a question with no
 * encounter and a failed request do not.
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
use OpenEMR\Modules\ClinicalCopilot\CopilotAuditLog;
use OpenEMR\Modules\ClinicalCopilot\DbAdoptionCounts;
use OpenEMR\Modules\ClinicalCopilot\Row;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

class DbAdoptionCountsTest extends TestCase
{
    private ?int $encounterRowId = null;

    protected function setUp(): void
    {
        ModuleAutoload::register();
    }

    protected function tearDown(): void
    {
        if ($this->encounterRowId !== null) {
            QueryUtils::sqlStatementThrowException("DELETE FROM form_encounter WHERE id = ?", [$this->encounterRowId]);
        }
    }

    public function testQuestionsCountPerEncounterForTheEncountersProvider(): void
    {
        $admin = QueryUtils::querySingleRow("SELECT id, username FROM users WHERE username = 'admin'");
        $patient = QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid LIMIT 1");
        if (!is_array($admin) || !is_array($patient)) {
            self::markTestSkipped('needs the admin user and a seeded patient');
        }
        // A fresh encounter number, so no earlier audit row can name it.
        $encounter = random_int(900000000, 999999999);
        $this->encounterRowId = (int) QueryUtils::sqlInsert(
            "INSERT INTO form_encounter (date, reason, pid, encounter, provider_id, facility_id, sensitivity) VALUES (NOW(), 'Follow-up', ?, ?, ?, 3, '')",
            [Row::int($patient, 'pid'), $encounter, Row::int($admin, 'id')]
        );
        $pid = Row::int($patient, 'pid');
        $audit = EventAuditLogger::getInstance();
        $audit->newEvent('clinical-copilot', 'admin', 'Default', 1, "action=ask correlation_id=t1 encounter_id=$encounter facts=3", $pid);
        $audit->newEvent('clinical-copilot', 'admin', 'Default', 1, "action=ask correlation_id=t2 encounter_id=$encounter facts=3", $pid);
        // None of these count: the auto-rendered briefing, a failed question, a question with no encounter.
        $audit->newEvent('clinical-copilot', 'admin', 'Default', 1, "action=brief correlation_id=t3 encounter_id=$encounter facts=3", $pid);
        $audit->newEvent('clinical-copilot', 'admin', 'Default', 0, "action=ask correlation_id=t4 encounter_id=$encounter facts=3", $pid);
        $audit->newEvent('clinical-copilot', 'admin', 'Default', 1, 'action=ask correlation_id=t5 encounter_id=0 facts=3', $pid);

        $counts = new DbAdoptionCounts(new CopilotAuditLog(static fn(string $c): string => $c));
        $from = new \DateTimeImmutable('today');
        $to = $from->modify('+1 day');

        self::assertContains($encounter, $counts->encounters($from, $to)['admin'][$from->format('Y-m-d')] ?? [], 'the encounter counts for its provider on its day');
        self::assertSame(2, $counts->asks($from, $to)['admin'][$encounter] ?? 0, 'two successful questions naming the encounter; the base64 audit comment is decoded');
    }
}
