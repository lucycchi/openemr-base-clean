<?php

/**
 * One alert firing as received from the observability backend's webhook.
 * The well-known fields are lifted out; the whole payload is kept for the
 * handler, but only a bounded summary ever reaches the log or audit row.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

final readonly class AlertEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $name,
        public string $severity,
        public string $type,
        public string $id,
        public ?float $value,
        public ?float $threshold,
        public array $payload,
        /** Langfuse's rule id (payload.monitorId); the title is generic, this is what identifies the rule. */
        public ?string $monitorId = null,
    ) {
    }

    /**
     * Langfuse's alert webhook ("monitor-alert") nests everything under
     * payload: {payload: {severity, message: {title, body}}}, with the
     * measured value and threshold only in the body text. Other senders
     * are read from the flatter alert/metric/top-level locations.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
        $message = is_array($inner['message'] ?? null) ? $inner['message'] : [];
        $alert = is_array($payload['alert'] ?? null) ? $payload['alert'] : [];
        $metric = is_array($payload['metric'] ?? null) ? $payload['metric'] : [];
        $body = is_string($message['body'] ?? null) ? $message['body'] : '';
        return new self(
            self::str($message['title'] ?? $alert['name'] ?? $payload['name'] ?? $payload['title'] ?? $payload['alertName'] ?? null),
            self::str($inner['severity'] ?? $alert['severity'] ?? $payload['severity'] ?? $payload['status'] ?? null),
            self::str($payload['type'] ?? $payload['event'] ?? null),
            self::str($payload['id'] ?? $payload['eventId'] ?? null),
            self::num($metric['value'] ?? $payload['value'] ?? self::fromBody($body, '/\bis\s+(-?[0-9.]+)/')),
            self::num($metric['threshold'] ?? $payload['threshold'] ?? self::fromBody($body, '/threshold:\s*(-?[0-9.]+)/')),
            $payload,
            is_string($inner['monitorId'] ?? null) ? mb_substr($inner['monitorId'], 0, 120) : null,
        );
    }

    private static function fromBody(string $body, string $pattern): ?string
    {
        return preg_match($pattern, $body, $m) ? $m[1] : null;
    }

    /** @return array<string, scalar|list<string>|null> */
    public function toLogContext(): array
    {
        return [
            'alert' => $this->name,
            'severity' => $this->severity,
            'type' => $this->type,
            'id' => $this->id,
            'value' => $this->value,
            'threshold' => $this->threshold,
            'monitor_id' => $this->monitorId,
            'payload_keys' => array_map(strval(...), array_keys($this->payload)),
        ];
    }

    public function auditComment(): string
    {
        return sprintf(
            'alert=%s severity=%s value=%s threshold=%s type=%s',
            $this->name,
            $this->severity,
            $this->value === null ? 'n/a' : self::fmt($this->value),
            $this->threshold === null ? 'n/a' : self::fmt($this->threshold),
            $this->type,
        ) . ($this->monitorId === null ? '' : ' monitor=' . $this->monitorId);
    }

    private static function str(mixed $v): string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 120) : 'unknown';
    }

    private static function num(mixed $v): ?float
    {
        return is_int($v) || is_float($v) ? (float) $v : (is_string($v) && is_numeric($v) ? (float) $v : null);
    }

    private static function fmt(float $v): string
    {
        return $v === floor($v) ? (string) (int) $v : rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}
