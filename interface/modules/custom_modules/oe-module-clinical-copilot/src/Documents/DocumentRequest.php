<?php

/**
 * Typed request to public/documents.php (contracts/documents.request.schema.json): upload, extract or list, parsed at the boundary.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

use OpenEMR\Modules\ClinicalCopilot\InvalidRequest;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;

final readonly class DocumentRequest
{
    private const KNOWN_KEYS = ['csrf_token_form', 'action', 'document_id', 'doc_type'];

    public function __construct(
        public string $csrfToken,
        public DocumentAction $action,
        public ?int $documentId,
        public ?DocType $docType,
        public ?UploadedFile $file,
    ) {
    }

    /**
     * @param InputBag<string|int|float|bool|null> $bag
     * @throws InvalidRequest
     */
    public static function fromBag(InputBag $bag, ?UploadedFile $file): self
    {
        foreach ($bag->keys() as $key) {
            if (!in_array($key, self::KNOWN_KEYS, true)) {
                throw new InvalidRequest('Unexpected field in request');
            }
        }
        $csrf = $bag->getString('csrf_token_form');
        if ($csrf === '') {
            throw new InvalidRequest('CSRF verification failed', 403);
        }
        $action = DocumentAction::tryFrom($bag->getString('action'));
        if ($action === null) {
            throw new InvalidRequest('Unknown action');
        }
        return match ($action) {
            DocumentAction::List => new self($csrf, $action, null, null, null),
            DocumentAction::Upload => (static function () use ($csrf, $action, $bag, $file): self {
                $type = DocType::tryFrom($bag->getString('doc_type'));
                if ($type === null) {
                    throw new InvalidRequest('doc_type must be lab_pdf or intake_form');
                }
                if ($file === null || !$file->isValid()) {
                    throw new InvalidRequest('A PDF file is required');
                }
                return new self($csrf, $action, null, $type, $file);
            })(),
            DocumentAction::Extract => (static function () use ($csrf, $action, $bag): self {
                $id = $bag->getString('document_id');
                if (!preg_match('/^[1-9]\d{0,18}$/', $id)) {
                    throw new InvalidRequest('document_id is required');
                }
                return new self($csrf, $action, (int) $id, null, null);
            })(),
        };
    }
}
