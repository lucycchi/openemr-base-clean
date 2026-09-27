<?php

/**
 * The outside medication list (PRD extension X3): parsing and fact wording.
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
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocType;
use OpenEMR\Modules\ClinicalCopilot\Documents\ExtractionResult;
use OpenEMR\Modules\ClinicalCopilot\Documents\IntakeExtraction;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarException;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\IntakeRecord;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Pins how a medication list reaches the chart: each row becomes a
 * "medication" item with its strength and directions as the detail, the
 * printed date becomes the /list_date item, the printed patient name is
 * kept for the chart comparison only, and the facts name the list and its
 * printed date, never "the intake form".
 */
final class MedicationListTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @return array<string, mixed> */
    private static function cite(string $path, string $value): array
    {
        return ['source_type' => 'document', 'source_id' => '9', 'page_or_section' => '1', 'field_or_chunk_id' => $path, 'quote_or_value' => $value, 'anchored' => false];
    }

    /** @return array<string, mixed> */
    private static function list(): array
    {
        return [
            'doc_type' => 'medication_list',
            'patient_name_on_list' => 'Test Zeta',
            'list_date' => '2026-09-10',
            'list_date_citation' => self::cite('/list_date', '09/10/2026'),
            'medications' => [
                ['name' => 'atorvastatin', 'dose' => '40 mg', 'frequency' => '1 tablet at bedtime', 'citation' => self::cite('/medications/0/name', 'atorvastatin')],
                ['name' => 'amlodipine', 'dose' => null, 'frequency' => null, 'citation' => self::cite('/medications/1/name', 'amlodipine')],
            ],
        ];
    }

    public function testAListBecomesMedicationItemsAndADateItem(): void
    {
        $ext = IntakeExtraction::fromArray(self::list());

        self::assertSame(DocType::MedicationList, $ext->docType);
        self::assertSame('Test Zeta', $ext->patientNameOnList);
        self::assertSame([], $ext->demographics, 'a list has no demographics block');
        self::assertSame(['form_date', 'medication', 'medication'], array_map(static fn($i): string => $i->kind, $ext->items));
        self::assertSame('/list_date', $ext->items[0]->fieldPath);
        self::assertSame('40 mg 1 tablet at bedtime', $ext->items[1]->detail);
        self::assertNull($ext->items[2]->detail, 'no strength or directions leaves no detail');
    }

    public function testTheResultDispatchesAListToTheIntakeParser(): void
    {
        $result = ExtractionResult::fromArray(['document_id' => 9, 'status' => 'extracted', 'failure_reason' => null, 'extraction' => self::list(), 'confidence' => 1.0]);

        self::assertInstanceOf(IntakeExtraction::class, $result->extraction);
        self::assertSame(DocType::MedicationList, $result->extraction->docType);
    }

    public function testAListWithoutItsMedicationsIsRefused(): void
    {
        $bad = self::list();
        unset($bad['medications']);

        $this->expectException(SidecarException::class);
        IntakeExtraction::fromArray($bad);
    }

    public function testFactsNameTheListAndItsPrintedDate(): void
    {
        $c = new Citation('document', '9', '1', '/medications/0/name', 'atorvastatin', false);
        $uploaded = new DateTimeImmutable('2026-09-27');
        $dated = new IntakeRecord(1, 9, 'medication', 'atorvastatin', '40 mg 1 tablet at bedtime', $uploaded, $c, DocType::MedicationList, '2026-09-10');
        $undated = new IntakeRecord(2, 9, 'medication', 'amlodipine', null, $uploaded, $c, DocType::MedicationList, null);
        $wrongPatient = new IntakeRecord(3, 9, 'patient_mismatch', 'patient name on the list does not match the chart', null, $uploaded, $c, DocType::MedicationList);
        $intake = new IntakeRecord(4, 8, 'medication', 'metformin', '500 mg twice daily', $uploaded, $c);

        self::assertSame('Outside medication list (dated 2026-09-10) shows atorvastatin 40 mg 1 tablet at bedtime', $dated->describe());
        self::assertSame('Outside medication list (undated, uploaded 2026-09-27) shows amlodipine', $undated->describe());
        self::assertSame('Medication list uploaded 2026-09-27: patient name on the list does not match the chart', $wrongPatient->describe());
        self::assertSame(FactCategory::DocumentMismatch, $wrongPatient->category());
        self::assertStringContainsString('on the intake form', $intake->describe(), 'intake form wording is unchanged');
    }
}
