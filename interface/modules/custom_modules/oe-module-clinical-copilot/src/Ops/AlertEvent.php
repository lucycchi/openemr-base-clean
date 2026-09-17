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
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $alert = is_array($payload['alert'] ?? null) ? $payload['alert'] : [];
        $metric = is_array($payload['metric'] ?? null) ? $payload['metric'] : [];
        return new self(
            self::str($alert['name'] ?? $payload['name'] ?? $payload['title'] ?? $payload['alertName'] ?? null),
            self::str($alert['severity'] ?? $payload['severity'] ?? $payload['status'] ?? null),
            self::str($payload['type'] ?? $payload['event'] ?? null),
            self::str($payload['id'] ?? $payload['eventId'] ?? null),
            self::num($metric['value'] ?? $payload['value'] ?? null),
            self::num($metric['threshold'] ?? $payload['threshold'] ?? null),
            $payload,
        );
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
        );
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
