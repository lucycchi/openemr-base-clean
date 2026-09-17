<?php

/**
 * Deterministic scope check on a follow-up question: the fact set is about
 * one patient, so a question that names a different patient by number is
 * refused before the model sees it. The model cannot tell "patient 4" from
 * the open chart (it never receives identifiers), so this cannot be left to
 * the prompt alone.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class QuestionScope
{
    /** True when the question refers to a patient/chart/record number other than the open one. */
    public static function refersToAnotherPatient(string $question, PatientId $open): bool
    {
        if (preg_match_all('/\b(?:patient|pid|mrn|chart|record)\s*(?:#|id|number|no\.?)?\s*[:#]?\s*(\d+)\b/i', $question, $m) === 0) {
            return false;
        }
        foreach ($m[1] as $number) {
            if ((int) $number !== $open->value) {
                return true;
            }
        }
        return false;
    }
}
