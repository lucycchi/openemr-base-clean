--
-- Clinical Co-Pilot Module
-- Upgrade 0.1.4 -> 0.1.5: the pre-warm receipt records the guideline cards
-- and the cache key the narration was actually stored under
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- Idempotent statement by statement, not only by the #If guard:
-- docker/vps/deploy.sh strips the #If lines and applies every upgrade file on
-- every deploy. ADD COLUMN IF NOT EXISTS is MariaDB syntax, which every stack
-- this module runs on uses.
--
-- Receipts written before 0.1.5 stored a cache key recomputed from an old copy
-- of the formula (without the guideline passages), which never matched a real
-- cache row. They are cleared so chart open reports those receipts as "key
-- unknown" rather than as a miss they did not cause. Only a pre-0.1.5 receipt
-- can hold a key with no guideline status: 0.1.5 records the cards before it
-- stores a narration. So the UPDATE never touches a key the new code wrote.

#IfMissingColumn copilot_prewarm guideline_status
ALTER TABLE `copilot_prewarm` ADD COLUMN IF NOT EXISTS `guideline_status` VARCHAR(16) NULL COMMENT 'none_fired, built, partial or unavailable (GuidelineStatus.php), NULL on receipts written before 0.1.5' AFTER `cache_key`;
#EndIf

ALTER TABLE `copilot_prewarm` MODIFY `cache_key` CHAR(64) NULL COMMENT 'the copilot_briefing_cache key the narration was stored under, as the pipeline returned it, NULL when nothing was stored';
UPDATE `copilot_prewarm` SET `cache_key` = NULL WHERE `guideline_status` IS NULL AND `cache_key` IS NOT NULL;
