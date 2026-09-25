<?php

/**
 * The raw counts behind KEY_METRICS.md metric 7 (chat adoption per encounter).
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
 * Days are Y-m-d in the site's time zone; $from is inclusive, $to exclusive.
 * Physicians are OpenEMR usernames (the audit log's `user`). Encounters are
 * encounter numbers (form_encounter.encounter, the id the session and the
 * audit row carry). DbAdoptionCounts reads form_encounter and the audit log.
 */
interface AdoptionCounts
{
    /** @return array<string, array<string, list<int>>> physician => day => encounters they are the provider on that day */
    public function encounters(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** @return array<string, array<int, int>> physician => encounter => ask turns the physician made on it */
    public function asks(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
