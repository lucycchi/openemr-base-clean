<?php

/**
 * Authenticates and parses an alert webhook call. Pure: no logging, no
 * database; alerts.php does the side effects.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

final readonly class AlertReceiver
{
    private const MAX_BODY_BYTES = 65536;

    public function __construct(private string $secret)
    {
    }

    /** @throws AlertRejected */
    public function receive(string $token, string $body): AlertEvent
    {
        if ($this->secret === '') {
            throw new AlertRejected('Alert webhook is not configured', 503);
        }
        if (!hash_equals($this->secret, $token)) {
            throw new AlertRejected('Invalid token', 401);
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new AlertRejected('Body too large', 413);
        }
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AlertRejected('Body is not a JSON object', 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new AlertRejected('Body is not a JSON object', 400);
        }
        /** @var array<string, mixed> $decoded */
        return AlertEvent::fromPayload($decoded);
    }
}
