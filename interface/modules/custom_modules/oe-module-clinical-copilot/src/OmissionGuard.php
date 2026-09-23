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

/**
 * Second safety net after the Verifier. The Verifier removes sentences that
 * cite nothing real; this catches the opposite problem — important facts the
 * model never mentioned. Any fact whose category is "must surface" (new
 * allergy, abnormal lab, new medication, ...) and that no kept sentence
 * cites is returned so the panel can list it explicitly. Pure function, no
 * model call.
 */
final class OmissionGuard
{
    /** @return list<Fact> facts that must be appended, in fact-set order */
    public function omitted(VerificationResult $verified, FactSet $facts): array
    {
        // Build a set of every fact id that survived verification.
        $cited = [];
        foreach ($verified->kept() as $sentence) {
            foreach ($sentence->factIds as $id) {
                $cited[$id] = true;
            }
        }
        // Walk the full fact set in order; keep the must-surface ones nobody cited.
        $omitted = [];
        foreach ($facts->all() as $fact) {
            if ($fact->category->mustSurface() && !isset($cited[$fact->id])) {
                $omitted[] = $fact;
            }
        }
        return $omitted;
    }
}
