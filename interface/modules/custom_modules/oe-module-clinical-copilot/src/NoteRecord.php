<?php

/**
 * One piece of free-text documentation from an encounter: a SOAP assessment
 * or plan, or a clinical note. The text is chart data; the assembler caps it
 * and the prompt flattens it before a model ever sees it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class NoteRecord
{
    public function __construct(
        public int $id,
        public int $encounterId,
        public \DateTimeImmutable $date,
        public NoteKind $kind,
        public string $text,
    ) {
    }
}
