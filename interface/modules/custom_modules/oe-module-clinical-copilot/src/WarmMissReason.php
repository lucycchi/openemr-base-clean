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

enum WarmMissReason: string
{
    case NoRow = 'no_row';
    case PromptVersion = 'prompt_version';
    case ModelChanged = 'model_changed';
    case ViewerDiffers = 'viewer_differs';
    case HashDrift = 'hash_drift';
}
