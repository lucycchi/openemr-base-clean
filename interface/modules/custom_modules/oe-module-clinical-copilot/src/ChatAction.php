<?php

/**
 * The three things chat.php can be asked to do (contracts/chat.request.schema.json).
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
 * The three things chat.php can be asked to do: brief, answer a follow-up
 * question, or record the physician's rating of the summary shown. A
 * string-backed enum: the value is what arrives in the request body
 * ("action": "brief"), and ChatAction::from() rejects anything else at the
 * boundary.
 */
enum ChatAction: string
{
    case Brief = 'brief';
    case Ask = 'ask';
    case Rate = 'rate';
}
