<?php

/**
 * The Co-Pilot's rows in OpenEMR's audit log, with their comments decoded.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;

/**
 * ChatController writes one `clinical-copilot` audit row per request, with a
 * comment such as "action=ask correlation_id=... encounter_id=12 ...". The
 * usage metrics (5, 6 and 7 in KEY_METRICS.md) are counted from these rows.
 * Audit comments are stored base64-encoded, or encrypted when the site turns
 * audit encryption on (log_comment_encrypt.encrypt); $decrypt is OpenEMR's
 * CryptoGen::decryptFromDatabase in production.
 */
final readonly class CopilotAuditLog
{
    /** @param \Closure(string): string $decrypt */
    public function __construct(private \Closure $decrypt)
    {
    }

    /**
     * Successful requests in [$from, $to), oldest first, comments decoded.
     *
     * @return list<array{day: string, user: string, comment: string}>
     */
    public function successful(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT DATE(l.date) AS day, l.user, l.comments, lce.encrypt, lce.version
               FROM log l LEFT JOIN log_comment_encrypt lce ON lce.log_id = l.id
              WHERE l.event = 'clinical-copilot' AND l.success = 1 AND l.date >= ? AND l.date < ?
              ORDER BY l.id",
            [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]
        );
        return array_map(fn(array $row): array => [
            'day' => Row::str($row, 'day'),
            'user' => Row::str($row, 'user'),
            'comment' => $this->comment($row),
        ], $rows);
    }

    /**
     * The audit comment as written, following OpenEMR's log viewer: encrypted
     * rows are decrypted, rows at log version 4 or later are base64.
     *
     * @param array<mixed> $row
     */
    private function comment(array $row): string
    {
        $comments = is_string($row['comments'] ?? null) ? $row['comments'] : '';
        if (($row['encrypt'] ?? null) === 'Yes') {
            return ($this->decrypt)($comments);
        }
        $version = is_numeric($row['version'] ?? null) ? (int) $row['version'] : 0;
        return $version >= 4 ? (string) base64_decode($comments, true) : $comments;
    }
}
