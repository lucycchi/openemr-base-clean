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

/**
 * Output of FactAssembler: the FactSet the model will be shown, plus the most
 * recent prior encounter (kept separately because the prompt frames the
 * briefing as "what changed since this visit").
 */
final readonly class AssembledFacts
{
    /** @param list<string> $activeProblemTitles every active problem on the chart, new or old, for the trigger rules; not facts */
    public function __construct(
        private FactSet $facts,
        private ?EncounterRecord $priorEncounter,
        private array $activeProblemTitles = [],
    ) {
    }

    /** @return list<string> */
    public function activeProblemTitles(): array
    {
        return $this->activeProblemTitles;
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
