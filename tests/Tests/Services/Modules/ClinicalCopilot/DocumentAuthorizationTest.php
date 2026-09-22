<?php

/**
 * Negative authorization for week 2 document handling (DB-backed):
 * a document id from another patient is invisible through the
 * patient-scoped store; a user without patients/docs cannot pass the
 * controller's ACL check while a physician can; a document of another
 * patient cannot be re-pointed by a dedup collision.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocType;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStore;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Row;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

class DocumentAuthorizationTest extends TestCase
{
    /** @var list<int> */
    private array $documentIds = [];
    private int $pidA;
    private int $pidB;

    protected function setUp(): void
    {
        ModuleAutoload::register();
        $rows = QueryUtils::fetchRecords("SELECT pid FROM patient_data ORDER BY pid LIMIT 2");
        if (count($rows) < 2) {
            self::markTestSkipped('needs two seeded patients');
        }
        $this->pidA = Row::int($rows[0], 'pid');
        $this->pidB = Row::int($rows[1], 'pid');
    }

    protected function tearDown(): void
    {
        foreach ($this->documentIds as $id) {
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
        return "%PDF-1.4\n%" . $marker . "\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public function testAnotherPatientsDocumentIsInvisibleThroughTheScopedStore(): void
    {
        $store = new DocumentStore();
        $stored = $store->store(new PatientId($this->pidA), DocType::LabPdf, 'a.pdf', $this->pdf('auth-' . bin2hex(random_bytes(4))), 'admin', 1);
        $this->documentIds[] = $stored['document_id'];
        // The controller looks a document up scoped to the session patient; a forged id from another chart finds nothing.
        self::assertNull($store->find(new PatientId($this->pidB), $stored['document_id']));
        self::assertNotNull($store->find(new PatientId($this->pidA), $stored['document_id']));
        self::assertSame([], array_filter($store->list(new PatientId($this->pidB)), static fn(array $d) => $d['document_id'] === $stored['document_id']));
    }

    public function testSameBytesForTwoPatientsAreTwoDocuments(): void
    {
        $store = new DocumentStore();
        $bytes = $this->pdf('two-' . bin2hex(random_bytes(4)));
        $a = $store->store(new PatientId($this->pidA), DocType::LabPdf, 'a.pdf', $bytes, 'admin', 1);
        $b = $store->store(new PatientId($this->pidB), DocType::LabPdf, 'b.pdf', $bytes, 'admin', 1);
        $this->documentIds[] = $a['document_id'];
        $this->documentIds[] = $b['document_id'];
        self::assertFalse($b['existing'], 'dedup is patient-scoped; the same file for another patient is a new document');
        self::assertNotSame($a['document_id'], $b['document_id']);
    }

    public function testDocumentsAclSeparatesFrontDeskFromClinicians(): void
    {
        // The controller's exact checks: view for list, write|addonly for upload and extract.
        self::assertTrue(AclMain::aclCheckCore('patients', 'docs', 'physician', ['write', 'addonly']));
        self::assertTrue(AclMain::aclCheckCore('patients', 'docs', 'physician'));
        // The seed receptionist has no document rights: upload, extract and list are all refused (403).
        self::assertFalse(AclMain::aclCheckCore('patients', 'docs', 'receptionist', ['write', 'addonly']));
        self::assertFalse(AclMain::aclCheckCore('patients', 'docs', 'receptionist'));
        // A user that does not exist has nothing.
        self::assertFalse(AclMain::aclCheckCore('patients', 'docs', 'no-such-user-' . bin2hex(random_bytes(3)), ['write', 'addonly']));
    }
}
