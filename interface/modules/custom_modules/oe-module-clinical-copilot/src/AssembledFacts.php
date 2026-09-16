<?php

/**
 * Output of FactAssembler: the fact set plus the visit boundary it was diffed against.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class AssembledFacts
{
    public function __construct(
        private FactSet $facts,
        private ?EncounterRecord $priorEncounter,
    ) {
    }

    public function facts(): FactSet
    {
        return $this->facts;
    }

    public function priorEncounter(): ?EncounterRecord
    {
        return $this->priorEncounter;
    }
}
