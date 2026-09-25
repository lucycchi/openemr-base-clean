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

/**
 * Why a chart open did NOT get a pre-warmed briefing even though a pre-warm
 * receipt existed for this patient. Persisted/logged as strings so the
 * dashboard can count them.
 *
 *   NoRow                - no receipt for this patient and day at all
 *   WarmFailed           - the day's only receipt is a failed warm (the 06:00 narration was not stored)
 *   PromptVersion        - warmed under an older Prompt::VERSION
 *   ModelChanged         - warmed with a different model name
 *   ReceiptKeyUnknown    - same facts, but the receipt has no cache key (written before 0.1.5)
 *   GuidelineCardsDiffer - same facts, different key: the guideline passages differ from the
 *                          06:00 run's (its cards were unavailable or partial, or the critic
 *                          now judges differently); the receipt's guideline status says which
 *   CacheEntryMissing    - same key, but the narration was not served from the cache (evicted
 *                          or unreadable)
 *   ViewerDiffers        - warmed as a user whose ACL-visible facts differ from the opener's
 *   HashDrift            - chart changed since the warm (new lab, med, etc.)
 */
enum WarmMissReason: string
{
    case NoRow = 'no_row';
    case WarmFailed = 'warm_failed';
    case PromptVersion = 'prompt_version';
    case ModelChanged = 'model_changed';
    case ReceiptKeyUnknown = 'receipt_key_unknown';
    case GuidelineCardsDiffer = 'guideline_cards_differ';
    case CacheEntryMissing = 'cache_entry_missing';
    case ViewerDiffers = 'viewer_differs';
    case HashDrift = 'hash_drift';
}
