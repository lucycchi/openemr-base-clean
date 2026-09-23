<?php

/**
 * documents.php request bodies: the JSON Schema contract and the PHP parser
 * must give the same verdict on the shared examples, so neither can drift.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentAction;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentRequest;
use OpenEMR\Modules\ClinicalCopilot\InvalidRequest;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;

final class DocumentRequestTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/examples/documents.request.examples.json';

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function bodies(): array
    {
        $examples = json_decode((string) file_get_contents(self::EXAMPLES), true, 16, JSON_THROW_ON_ERROR);
        $out = [];
        foreach (['accept' => true, 'reject' => false] as $set => $accepted) {
            $bodies = is_array($examples) && is_array($examples[$set] ?? null) ? $examples[$set] : [];
            foreach (array_values($bodies) as $i => $body) {
                $fields = [];
                foreach (is_array($body) ? $body : [] as $key => $value) {
                    $fields[(string) $key] = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
                }
                $out["$set #$i"] = [$fields, $accepted];
            }
        }
        return $out;
    }

    /**
     * Pins: the contract and DocumentRequest::fromBag accept and refuse the
     * same bodies. A failure means one of them changed without the other,
     * so the documented request format and the real one have drifted.
     *
     * @param array<string, string> $body
     */
    #[DataProvider('bodies')]
    public function testParserAgreesWithTheContract(array $body, bool $contractAccepts): void
    {
        self::assertSame($contractAccepts, Contracts::violations('documents.request', $body) === [], 'contract verdict differs from the example set');
        // The multipart file is outside the schema; give upload bodies one so the
        // parser judges the same fields the contract does.
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        self::assertIsString($tmp);
        file_put_contents($tmp, '%PDF-1.4');
        $file = new UploadedFile($tmp, 'lab.pdf', 'application/pdf', null, true);
        try {
            $parsed = DocumentRequest::fromBag(new InputBag($body), $file);
            self::assertTrue($contractAccepts, 'parser accepted a body the contract rejects');
            self::assertSame($body['action'], $parsed->action->value);
        } catch (InvalidRequest) {
            self::assertFalse($contractAccepts, 'parser rejected a body the contract accepts');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Pins: the file is required even when the form fields are perfect (the
     * contract cannot see the multipart file). A failure would let an upload
     * with no attachment reach the store.
     */
    public function testUploadWithoutAFileIsRefusedEvenThoughTheFieldsConform(): void
    {
        $this->expectException(InvalidRequest::class);
        DocumentRequest::fromBag(new InputBag(['csrf_token_form' => 'tok', 'action' => 'upload', 'doc_type' => 'lab_pdf']), null);
    }

    /**
     * Pins: the document id arrives as text and leaves the parser as an int.
     * A failure means the controller would be comparing a string with the
     * database's integer id.
     */
    public function testExtractExposesTheDocumentIdAsAnInteger(): void
    {
        $r = DocumentRequest::fromBag(new InputBag(['csrf_token_form' => 'tok', 'action' => 'extract', 'document_id' => '42']), null);
        self::assertSame(DocumentAction::Extract, $r->action);
        self::assertSame(42, $r->documentId);
    }
}
