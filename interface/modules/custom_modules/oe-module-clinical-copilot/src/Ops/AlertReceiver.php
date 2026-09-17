<?php

/**
 * Authenticates and parses an alert webhook call. Pure: no logging, no
 * database; alerts.php does the side effects.
 *
 * Two credentials are understood, either is sufficient:
 *  - Langfuse's signed webhook: header x-langfuse-signature "t=<unix>,v1=<hex>"
 *    where v1 = HMAC-SHA256(signingSecret, "<t>.<raw body>"). Proves the body
 *    is untampered and, via the timestamp, not a replay.
 *  - A shared token (X-Alert-Token or ?token=) equal to a configured secret,
 *    for senders that cannot sign.
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
    private const MAX_SKEW_SECONDS = 300;

    /** @var \Closure(): int */
    private \Closure $now;

    /** @param ?\Closure(): int $now unix time source, injectable for tests */
    public function __construct(
        private string $tokenSecret,
        private string $signingSecret = '',
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn(): int => time();
    }

    /** @throws AlertRejected */
    public function receive(string $token, string $body, string $signature = ''): AlertEvent
    {
        if ($this->tokenSecret === '' && $this->signingSecret === '') {
            throw new AlertRejected('Alert webhook is not configured', 503);
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new AlertRejected('Body too large', 413);
        }
        if (!$this->tokenAccepted($token)) {
            $this->requireValidSignature($signature, $body);
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

    private function tokenAccepted(string $token): bool
    {
        return $this->tokenSecret !== '' && $token !== '' && hash_equals($this->tokenSecret, $token);
    }

    /** @throws AlertRejected */
    private function requireValidSignature(string $signature, string $body): void
    {
        if ($this->signingSecret === '' || $signature === '') {
            throw new AlertRejected($this->signingSecret === '' || $this->tokenSecret !== '' ? 'Invalid token' : 'Missing signature', 401);
        }
        if (!preg_match('/(?:^|,)t=(\d{1,12})(?:,|$)/', $signature, $t) || !preg_match('/(?:^|,)v1=([0-9a-f]{64})(?:,|$)/', $signature, $v)) {
            throw new AlertRejected('Invalid signature', 401);
        }
        $timestamp = (int) $t[1];
        if (abs(($this->now)() - $timestamp) > self::MAX_SKEW_SECONDS) {
            throw new AlertRejected('Signature expired', 401);
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->signingSecret);
        if (!hash_equals($expected, $v[1])) {
            throw new AlertRejected('Invalid signature', 401);
        }
    }
}
