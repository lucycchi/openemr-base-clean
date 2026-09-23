<?php

/**
 * DB-backed pins for the chart reads the briefing depends on. Each test
 * inserts what it needs for the first seeded patient and removes it again.
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
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Row;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

class OpenEmrChartSourceTest extends TestCase
{
    private int $pid;
    /** @var list<int> */
    private array $orderIds = [];

    protected function setUp(): void
    {
        ModuleAutoload::register();
        $row = QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid LIMIT 1");
        $this->pid = is_array($row) ? Row::int($row, 'pid') : 0;
        if ($this->pid <= 0) {
            self::markTestSkipped('needs a seeded patient');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->orderIds as $oid) {
            QueryUtils::sqlStatementThrowException("DELETE pr FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id WHERE prp.procedure_order_id = ?", [$oid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM procedure_report WHERE procedure_order_id = ?", [$oid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order_code WHERE procedure_order_id = ?", [$oid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order WHERE procedure_order_id = ?", [$oid]);
        }
    }

    /** One order, one report, one result row with the given columns; returns the result id. */
    private function insertResult(string $code, string $text, string $value, string $units, string $range, string $abnormal, string $dataType): int
    {
        $orderId = (int) QueryUtils::sqlInsert("INSERT INTO procedure_order (patient_id, encounter_id, date_ordered, order_status, provider_id) VALUES (?, 0, '2026-09-10', 'complete', 0)", [$this->pid]);
        $this->orderIds[] = $orderId;
        QueryUtils::sqlInsert("INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code, procedure_name) VALUES (?, 1, 'TEST-CHART', 'Chart source test')", [$orderId]);
        $reportId = (int) QueryUtils::sqlInsert("INSERT INTO procedure_report (procedure_order_id, procedure_order_seq, date_collected, date_report, source, report_status, review_status) VALUES (?, 1, '2026-09-10', '2026-09-10', 0, 'final', 'received')", [$orderId]);
        return (int) QueryUtils::sqlInsert(
            "INSERT INTO procedure_result (procedure_report_id, result_data_type, result_code, result_text, date, units, result, `range`, abnormal, result_status) VALUES (?, ?, ?, ?, '2026-09-10', ?, ?, ?, ?, 'final')",
            [$reportId, $dataType, $code, $text, $units, $value, $range, $abnormal]
        );
    }

    public function testLabsIncludeQualitativeResultsAndPrintedRanges(): void
    {
        $qualitative = $this->insertResult('', 'Urine culture', 'positive', '', '', 'yes', 'S');
        $numeric = $this->insertResult('2823-3', 'Potassium', '5.4', 'mmol/L', '3.5-5.1', 'high', 'N');

        $labs = [];
        foreach ((new OpenEmrChartSource())->labs(new PatientId($this->pid)) as $l) {
            $labs[$l->id] = $l;
        }

        self::assertArrayHasKey($qualitative, $labs, 'a text result must be read, not filtered out');
        self::assertNull($labs[$qualitative]->value);
        self::assertSame('positive', $labs[$qualitative]->text);
        self::assertSame('yes', $labs[$qualitative]->labFlag);
        self::assertNull($labs[$qualitative]->printedRange);

        self::assertArrayHasKey($numeric, $labs);
        self::assertSame(5.4, $labs[$numeric]->value);
        self::assertNull($labs[$numeric]->text);
        self::assertSame('3.5-5.1', $labs[$numeric]->printedRange);
        self::assertSame('high', $labs[$numeric]->labFlag);
    }

    public function testDemographicsReadSexAndDateOfBirth(): void
    {
        $row = QueryUtils::querySingleRow("SELECT sex, DOB FROM patient_data WHERE pid = ?", [$this->pid]);
        self::assertIsArray($row);
        $d = (new OpenEmrChartSource())->demographics(new PatientId($this->pid));
        $expectedSex = match (strtolower(trim(Row::str($row, 'sex')))) { 'm', 'male' => 'M', 'f', 'female' => 'F', default => null };
        self::assertSame($expectedSex, $d->sex);
        $dob = Row::str($row, 'DOB');
        if ($dob === '' || str_starts_with($dob, '0000')) {
            self::assertNull($d->dob);
        } else {
            self::assertSame(substr($dob, 0, 10), $d->dob?->format('Y-m-d'));
        }
    }
}
