<?php

/**
 * Why a chart open did not hit its pre-warm receipt. Backed: logged and scored.
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
 * Why a chart open did NOT get a pre-warmed briefing even though a pre-warm
 * receipt existed for this patient. Persisted/logged as strings so the
 * dashboard can count them.
 *
 *   NoRow          - no receipt for this patient and day at all
 *   PromptVersion  - warmed under an older Prompt::VERSION
 *   ModelChanged   - warmed with a different model name
 *   ViewerDiffers  - warmed as a user whose ACL-visible facts differ from the opener's
 *   HashDrift      - chart changed since the warm (new lab, med, etc.)
 */
enum WarmMissReason: string
{
    case NoRow = 'no_row';
    case PromptVersion = 'prompt_version';
    case ModelChanged = 'model_changed';
    case ViewerDiffers = 'viewer_differs';
    case HashDrift = 'hash_drift';
}
