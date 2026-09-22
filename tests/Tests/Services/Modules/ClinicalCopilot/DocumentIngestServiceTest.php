<?php

/**
 * DB-backed tests for week 2 document persistence: the complete lab
 * structure is written inside one transaction, a repeat run is a no-op,
 * a failed extraction leaves no lab rows, and the store deduplicates a
 * re-upload per patient. Runs in the services suite (openemr-cmd st).
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
use OpenEMR\Modules\ClinicalCopilot\Documents\BBox;
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocType;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentIngestService;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStatus;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStore;
use OpenEMR\Modules\ClinicalCopilot\Documents\ExtractionResult;
use OpenEMR\Modules\ClinicalCopilot\Documents\LabReportExtraction;
use OpenEMR\Modules\ClinicalCopilot\Documents\LabResultExtraction;
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

class DocumentIngestServiceTest extends TestCase
{
    private int $pid;
    /** @var list<int> */
    private array $documentIds = [];

    protected function setUp(): void
    {
        ModuleAutoload::register();
        $row = QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid LIMIT 1");
        $this->pid = is_array($row) ? (int) $row['pid'] : 0;
        if ($this->pid <= 0) {
            self::markTestSkipped('needs a seeded patient');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->documentIds as $id) {
            $orders = QueryUtils::fetchRecords("SELECT DISTINCT po.procedure_order_id FROM procedure_order po JOIN procedure_report prp ON prp.procedure_order_id = po.procedure_order_id JOIN procedure_result pr ON pr.procedure_report_id = prp.procedure_report_id WHERE pr.document_id = ?", [$id]);
            foreach ($orders as $o) {
                $oid = (int) $o['procedure_order_id'];
                QueryUtils::sqlStatementThrowException("DELETE pr FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id WHERE prp.procedure_order_id = ?", [$oid]);
                QueryUtils::sqlStatementThrowException("DELETE FROM procedure_report WHERE procedure_order_id = ?", [$oid]);
                QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order_code WHERE procedure_order_id = ?", [$oid]);
                QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order WHERE procedure_order_id = ?", [$oid]);
            }
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_document_fact WHERE document_id = ?", [$id]);
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_intake WHERE document_id = ?", [$id]);
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_document WHERE document_id = ?", [$id]);
            $doc = new \Document($id);
            $url = $doc->get_url_filepath();
            if (is_string($url) && $url !== '' && file_exists($url)) {
                @unlink($url);
            }
            QueryUtils::sqlStatementThrowException("DELETE FROM documents WHERE id = ?", [$id]);
        }
    }

    private function pdf(string $marker): string
    {
        // A tiny but real PDF; the marker makes the bytes unique per test.
        return "%PDF-1.4\n%" . $marker . "\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    private function citation(int $documentId, string $path, string $value, bool $anchored): Citation
    {
        $box = $anchored ? new BBox(1, 230.0, 100.0, 260.0, 112.0, 612.0, 792.0) : null;
        return new Citation('document', (string) $documentId, '1', $path, $value, $anchored, $box, $box);
    }

    private function labExtraction(int $documentId, bool $secondAnchored = true): ExtractionResult
    {
        $lab = new LabReportExtraction(
            'Test Zeta',
            new \DateTimeImmutable('2026-09-15'),
            $this->citation($documentId, '/collection_date', '2026-09-15', true),
            null,
            null,
            'Synthetic Labs',
            [
                new LabResultExtraction('Glucose', '2345-7', '105.6', 'mg/dL', '70-99', 'H', false, $this->citation($documentId, '/results/0/value', '105.6', true)),
                new LabResultExtraction('Potassium', '2823-3', '4.4', 'mmol/L', '3.5-5.1', null, false, $this->citation($documentId, '/results/1/value', '4.4', $secondAnchored)),
            ],
            [],
        );
        return new ExtractionResult($documentId, DocumentStatus::Extracted, null, $lab, $secondAnchored ? 1.0 : 0.667);
    }

    private function store(string $marker): int
    {
        $stored = (new DocumentStore())->store(new PatientId($this->pid), DocType::LabPdf, "test-$marker.pdf", $this->pdf($marker), 'admin', 1);
        $this->documentIds[] = $stored['document_id'];
        return $stored['document_id'];
    }

    public function testPersistWritesTheFullLabStructureAndIsIdempotent(): void
    {
        $documentId = $this->store('idem-' . bin2hex(random_bytes(4)));
        $service = new DocumentIngestService();
        $first = $service->persist(new PatientId($this->pid), $this->labExtraction($documentId), 'test-corr');
        self::assertSame(DocumentStatus::Extracted, $first['status']);
        self::assertSame(2, $first['results_persisted']);

        $results = QueryUtils::fetchRecords("SELECT pr.result_code, pr.result, pr.units, pr.abnormal, pr.document_id, pr.uuid, prp.report_status, po.order_status, poc.procedure_code FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id JOIN procedure_order_code poc ON poc.procedure_order_id = po.procedure_order_id WHERE pr.document_id = ? ORDER BY pr.procedure_result_id", [$documentId]);
        self::assertCount(2, $results);
        self::assertSame('2345-7', $results[0]['result_code']);
        self::assertSame('high', $results[0]['abnormal']);
        self::assertSame('final', $results[0]['report_status']);
        self::assertSame('complete', $results[0]['order_status']);
        self::assertSame(DocumentIngestService::PANEL_CODE, $results[0]['procedure_code']);
        self::assertNotEmpty($results[0]['uuid'], 'FHIR needs a uuid on the result');

        $facts = QueryUtils::fetchRecords("SELECT field_path, anchored, procedure_result_id FROM copilot_document_fact WHERE document_id = ? ORDER BY field_path", [$documentId]);
        // 'Test Zeta' on the report is not the seed patient: a patient_mismatch flag is recorded beside the results.
        self::assertSame(['/collection_date', '/patient_name_on_report', '/results/0/value', '/results/1/value'], array_column($facts, 'field_path'));

        // The Week 1 chart source now sees the results with their document citations.
        $labs = array_values(array_filter((new OpenEmrChartSource())->labs(new PatientId($this->pid)), static fn($l) => $l->citation !== null && $l->citation->sourceId === (string) $documentId));
        self::assertCount(2, $labs);
        self::assertSame(1, $labs[0]->citation->bbox?->page);

        $again = $service->persist(new PatientId($this->pid), $this->labExtraction($documentId), 'test-corr-2');
        self::assertSame(0, $again['results_persisted'], 'a repeat run must not create a second set of rows');
        self::assertSame(2, (int) QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM procedure_result WHERE document_id = ?", 'n', [$documentId]));
    }

    public function testUnanchoredValueIsRecordedButNotPersistedAsALabRow(): void
    {
        $documentId = $this->store('unv-' . bin2hex(random_bytes(4)));
        $out = (new DocumentIngestService())->persist(new PatientId($this->pid), $this->labExtraction($documentId, false), 'test-corr');
        self::assertSame(1, $out['results_persisted']);
        self::assertSame(1, $out['unverified']);
        self::assertSame(1, (int) QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM procedure_result WHERE document_id = ?", 'n', [$documentId]));
        $unverified = (new OpenEmrChartSource())->unverifiedExtractions(new PatientId($this->pid));
        self::assertNotEmpty(array_filter($unverified, static fn($u) => $u->documentId === $documentId && $u->analyte === 'Potassium'));
    }

    public function testFailedExtractionLeavesNoLabRows(): void
    {
        $documentId = $this->store('fail-' . bin2hex(random_bytes(4)));
        $failed = new ExtractionResult($documentId, DocumentStatus::Failed, 'unreadable', null, 0.0);
        $out = (new DocumentIngestService())->persist(new PatientId($this->pid), $failed, 'test-corr');
        self::assertSame(DocumentStatus::Failed, $out['status']);
        self::assertSame(0, (int) QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM procedure_result WHERE document_id = ?", 'n', [$documentId]));
        $row = QueryUtils::querySingleRow("SELECT status, failure_reason FROM copilot_document WHERE document_id = ?", [$documentId]);
        self::assertSame(['status' => 'failed', 'failure_reason' => 'unreadable'], $row);
    }

    public function testReUploadOfTheSameBytesForTheSamePatientIsDeduplicated(): void
    {
        $marker = 'dup-' . bin2hex(random_bytes(4));
        $store = new DocumentStore();
        $first = $store->store(new PatientId($this->pid), DocType::LabPdf, 'a.pdf', $this->pdf($marker), 'admin', 1);
        $this->documentIds[] = $first['document_id'];
        $second = $store->store(new PatientId($this->pid), DocType::LabPdf, 'b.pdf', $this->pdf($marker), 'admin', 1);
        self::assertTrue($second['existing']);
        self::assertSame($first['document_id'], $second['document_id']);
        self::assertSame(1, (int) QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_document WHERE document_id = ?", 'n', [$first['document_id']]));
    }

    public function testIntakePersistsCitedItemsAndFlagsDemographicMismatchesWithoutStoringThem(): void
    {
        $documentId = $this->store('intake-' . bin2hex(random_bytes(4)));
        $c = fn(string $path, string $v) => $this->citation($documentId, $path, $v, true);
        $intake = new \OpenEMR\Modules\ClinicalCopilot\Documents\IntakeExtraction(
            [
                new \OpenEMR\Modules\ClinicalCopilot\Documents\IntakeItem('chief_concern', '/chief_concern', 'chest tightness on stairs', null, $c('/chief_concern', 'chest tightness on stairs')),
                new \OpenEMR\Modules\ClinicalCopilot\Documents\IntakeItem('medication', '/medications/0/name', 'lisinopril', '10 mg STOPPED in August', $c('/medications/0/name', 'lisinopril')),
                new \OpenEMR\Modules\ClinicalCopilot\Documents\IntakeItem('allergy', '/allergies/0/substance', 'penicillin', 'rash', $c('/allergies/0/substance', 'penicillin')),
            ],
            // A name that cannot match any seed patient, and a DOB that will not either.
            ['name' => 'Zzyzx Nobody', 'dob' => '01/01/1900'],
        );
        $result = new ExtractionResult($documentId, DocumentStatus::Extracted, null, $intake, 1.0);
        $out = (new DocumentIngestService())->persist(new PatientId($this->pid), $result, 'test-corr');
        self::assertSame(DocumentStatus::Extracted, $out['status']);

        $rows = QueryUtils::fetchRecords("SELECT kind, value FROM copilot_intake WHERE document_id = ? ORDER BY id", [$documentId]);
        $kinds = array_column($rows, 'kind');
        self::assertSame(['demographics_mismatch', 'demographics_mismatch', 'chief_concern', 'medication', 'allergy'], $kinds);
        // The form's name and DOB are never stored: only the fixed mismatch phrases are.
        $joined = implode(' ', array_column($rows, 'value'));
        self::assertStringNotContainsString('Zzyzx', $joined);
        self::assertStringNotContainsString('1900', $joined);
        self::assertStringContainsString('name on the form does not match the chart', $joined);

        $records = array_values(array_filter((new OpenEmrChartSource())->intakeRecords(new PatientId($this->pid)), static fn($r) => $r->documentId === $documentId));
        self::assertCount(5, $records);
        $categories = array_map(static fn($r) => $r->category()?->value, $records);
        self::assertContains('intake_med', $categories);
        self::assertContains('document_mismatch', $categories);
        $med = array_values(array_filter($records, static fn($r) => $r->kind === 'medication'))[0];
        self::assertTrue($med->citation->anchored);
        self::assertSame(1, $med->citation->bbox?->page);
        self::assertStringContainsString('STOPPED in August', $med->describe());
    }

    /**
     * Regression (Phase 7, 2026-09-22): the Week 1 rule "only what changed since
     * the prior visit" hid everything for a patient with no prior encounter,
     * including a document uploaded a minute ago. Document-derived records are
     * new information regardless of visit history.
     */
    public function testDocumentDerivedFactsSurfaceForAPatientWithNoPriorVisit(): void
    {
        $maxPid = (int) QueryUtils::fetchSingleValue("SELECT MAX(pid) AS m FROM patient_data", 'm');
        $tempPid = $maxPid + 1;
        QueryUtils::sqlInsert("INSERT INTO patient_data (pid, fname, lname, DOB, sex) VALUES (?, 'Temp', 'NoVisit', '1980-05-05', 'Male')", [$tempPid]);
        try {
            $stored = (new DocumentStore())->store(new PatientId($tempPid), DocType::LabPdf, 'novisit.pdf', $this->pdf('novisit-' . bin2hex(random_bytes(4))), 'admin', 1);
            $this->documentIds[] = $stored['document_id'];
            $documentId = $stored['document_id'];
            // One anchored abnormal glucose, one unanchored potassium; the report names "Test Zeta", not Temp NoVisit.
            (new DocumentIngestService())->persist(new PatientId($tempPid), $this->labExtraction($documentId, false), 'test-corr');

            $assembled = (new \OpenEMR\Modules\ClinicalCopilot\FactAssembler(new OpenEmrChartSource(), new \OpenEMR\Modules\ClinicalCopilot\AclAuthorization('admin'), \OpenEMR\BC\ServiceContainer::getClock()))
                ->assemble(new PatientId($tempPid), null);
            self::assertNull($assembled->priorEncounter(), 'the temp patient has no encounters');
            $byCategory = [];
            foreach ($assembled->facts()->all() as $f) {
                $byCategory[$f->category->value][] = $f;
            }
            self::assertArrayHasKey('lab_abnormal', $byCategory, 'the abnormal glucose from the document must surface with no prior visit');
            self::assertSame((string) $documentId, $byCategory['lab_abnormal'][0]->citation?->sourceId);
            self::assertTrue($byCategory['lab_abnormal'][0]->citation?->anchored);
            self::assertArrayHasKey('extraction_unverified', $byCategory, 'the unanchored potassium must surface as unverified');
            self::assertArrayHasKey('document_mismatch', $byCategory, 'the report name does not match the chart');
            self::assertTrue($byCategory['extraction_unverified'][0]->category->mustSurface());
        } finally {
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$tempPid]);
            QueryUtils::sqlStatementThrowException("DELETE FROM patient_data WHERE pid = ?", [$tempPid]);
        }
    }

    public function testUploadRejectsNonPdf(): void
    {
        $this->expectException(\OpenEMR\Modules\ClinicalCopilot\Documents\UploadRejected::class);
        (new DocumentStore())->store(new PatientId($this->pid), DocType::LabPdf, 'x.png', "\x89PNG not a pdf", 'admin', 1);
    }
}
