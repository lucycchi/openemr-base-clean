--
-- Clinical Co-Pilot Module
-- Upgrade 0.1.3 -> 0.1.4: physician rating of the AI summary (KEY_METRICS.md metric 6)
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- Idempotent: CREATE TABLE IF NOT EXISTS, the same block as install.sql, so
-- running it on a site that already has the table changes nothing.

#IfNotTable copilot_briefing_rating
-- A physician's thumbs up or down on one AI summary (KEY_METRICS.md metric 6).
-- Keyed to the cached narration shown (briefing_cache_key), so a prompt or
-- model change can be compared before and after. One row per user and
-- briefing: rating again replaces the row. The comment is free text a
-- physician typed and may name the patient: it stays in this table and never
-- reaches a log line, the audit comment or Langfuse.
CREATE TABLE IF NOT EXISTS `copilot_briefing_rating` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `pid` BIGINT(20) NOT NULL,
    `encounter_id` BIGINT(20) NULL,
    `user_id` BIGINT(20) NOT NULL COMMENT 'references users.id',
    `briefing_cache_key` CHAR(64) NOT NULL COMMENT 'references copilot_briefing_cache.cache_key',
    `prompt_version` VARCHAR(32) NOT NULL,
    `model` VARCHAR(64) NOT NULL,
    `correlation_id` CHAR(32) NOT NULL COMMENT 'the brief request that showed the summary; its Langfuse trace id',
    `rating` ENUM('up','down') NOT NULL,
    `comment` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_briefing` (`user_id`, `briefing_cache_key`),
    KEY `idx_created` (`created_at`),
    KEY `idx_prompt_version` (`prompt_version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
