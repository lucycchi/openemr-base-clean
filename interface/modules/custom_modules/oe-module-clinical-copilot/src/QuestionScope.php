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

/**
 * Cheap pre-LLM guard for follow-up questions. The facts handed to the model
 * belong to the open chart only; if the clinician types "what about patient
 * 42?", answering from those facts would be wrong and possibly a privacy
 * leak. This detects that pattern with a regex so it can be refused before
 * any model call.
 */
final readonly class QuestionScope
{
    /** True when the question refers to a patient/chart/record number other than the open one. */
    public static function refersToAnotherPatient(string $question, PatientId $open): bool
    {
        // Matches "patient 42", "pid: 42", "MRN #42", "chart no. 42", etc. and captures the number.
        if (preg_match_all('/\b(?:patient|pid|mrn|chart|record)\s*(?:#|id|number|no\.?)?\s*[:#]?\s*(\d+)\b/i', $question, $m) === 0) {
            return false;
        }
        // Mentioning the *open* patient's own number is fine; any other number is not.
        foreach ($m[1] as $number) {
            if ((int) $number !== $open->value) {
                return true;
            }
        }
        return false;
    }
}
