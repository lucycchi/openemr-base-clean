<?php

/**
 * A chat.php body that does not satisfy contracts/chat.request.schema.json.
 * The message is physician-facing and becomes the error field of the response.
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
 * A request the copilot refuses to process: bad JSON, unknown action, missing
 * patient id, question too long, and so on. Carries the HTTP status the
 * controller should reply with (400 by default; 405 for wrong method, etc.)
 * so the parsing code decides the status, not the controller.
 */
final class InvalidRequest extends \InvalidArgumentException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}
