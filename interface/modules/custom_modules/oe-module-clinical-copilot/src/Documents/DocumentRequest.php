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

/**
 * The typed form of one documents.php request. "Parse, don't validate":
 * the raw form fields are turned into this object once, at the boundary,
 * and everything after works with typed values (an enum for the action, an
 * int for the document id) that cannot be malformed. The uploaded file is
 * carried alongside because it arrives in the multipart body, not as a
 * form field.
 *
 * Which fields are set depends on the action: upload has docType and file,
 * extract has documentId, list has neither.
 */
final readonly class DocumentRequest
{
    /** Every field a request may contain; anything else is refused, so a stray field is noticed. */
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
     * Parses the POST fields (and the file, for an upload) or throws
     * InvalidRequest carrying the HTTP status to answer with. The order of
     * checks matches contracts/documents.request.schema.json so the contract
     * and this parser give the same verdict (DocumentRequestTest proves it).
     *
     * The CSRF token is only checked for presence here; the controller
     * verifies it against the session, because the session is not this
     * class's business.
     *
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
        // Each action needs different extra fields. The `(static function () {...})()` form
        // defines a small function and calls it at once, so each branch can throw or return
        // its own object inside the match expression.
        return match ($action) {
            DocumentAction::List => new self($csrf, $action, null, null, null),
            DocumentAction::Upload => (static function () use ($csrf, $action, $bag, $file): self {
                $type = DocType::tryFrom($bag->getString('doc_type'));
                if ($type === null) {
                    throw new InvalidRequest('doc_type must be lab_pdf or intake_form');
                }
                // isValid() is false when PHP itself rejected the upload (size limit, partial transfer).
                if ($file === null || !$file->isValid()) {
                    throw new InvalidRequest('A PDF file is required');
                }
                return new self($csrf, $action, null, $type, $file);
            })(),
            DocumentAction::Extract => (static function () use ($csrf, $action, $bag): self {
                // A positive integer without a leading zero, at most 19 digits: never "0", "007" or "12abc".
                $id = $bag->getString('document_id');
                if (!preg_match('/^[1-9]\d{0,18}$/', $id)) {
                    throw new InvalidRequest('document_id is required');
                }
                return new self($csrf, $action, (int) $id, null, null);
            })(),
        };
    }
}
