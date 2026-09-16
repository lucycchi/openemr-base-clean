<?php

/**
 * Coverage check: must-surface facts the kept narration never cited.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class OmissionGuard
{
    /** @return list<Fact> facts that must be appended, in fact-set order */
    public function omitted(VerificationResult $verified, FactSet $facts): array
    {
        $cited = [];
        foreach ($verified->kept() as $sentence) {
            foreach ($sentence->factIds as $id) {
                $cited[$id] = true;
            }
        }
        $omitted = [];
        foreach ($facts->all() as $fact) {
            if ($fact->category->mustSurface() && !isset($cited[$fact->id])) {
                $omitted[] = $fact;
            }
        }
        return $omitted;
    }
}
