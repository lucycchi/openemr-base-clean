<?php

/**
 * One narration sentence as emitted by the model: text plus the fact ids it cites.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class Sentence
{
    /** @param list<string> $factIds */
    public function __construct(
        public string $text,
        public array $factIds,
    ) {
    }
}
