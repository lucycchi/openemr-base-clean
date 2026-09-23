<?php

/**
 * Raised when the current user may not read a chart section.
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
 * Thrown when the logged-in user is not allowed to see the requested chart
 * (or a restricted part of it). The controller turns it into HTTP 403.
 * It has no body of its own; the type name is the whole signal.
 */
final class AccessDeniedException extends \RuntimeException
{
}
