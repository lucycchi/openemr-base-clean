--
-- Clinical Co-Pilot Module
-- Install SQL Script
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfNotTable copilot_briefing_cache
-- Verified briefing narrations keyed by facts hash + prompt version + model.
-- Holds fact ids and narration text only; no direct identifiers.
CREATE TABLE IF NOT EXISTS `copilot_briefing_cache` (
    `cache_key` CHAR(64) NOT NULL COMMENT 'sha256(facts_hash|prompt_version|model)',
    `pid` BIGINT(20) NOT NULL,
    `facts_hash` CHAR(64) NOT NULL,
    `prompt_version` VARCHAR(32) NOT NULL,
    `model` VARCHAR(64) NOT NULL,
    `narration_json` MEDIUMTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`cache_key`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfNotTable copilot_prewarm
-- One receipt per scheduled patient per pre-warm run: what was warmed, under
-- which prompt/model, and the fact lines (id/service/category/value
-- references, no identifiers) so a chart open can explain a miss.
CREATE TABLE IF NOT EXISTS `copilot_prewarm` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `run_id` CHAR(32) NOT NULL,
    `target_date` DATE NOT NULL,
    `pc_eid` INT(11) NOT NULL,
    `pid` BIGINT(20) NOT NULL,
    `provider_username` VARCHAR(255) NOT NULL,
    `facts_hash` CHAR(64) NULL,
    `cache_key` CHAR(64) NULL COMMENT 'sha256(facts_hash|prompt_version|model), the copilot_briefing_cache row this warmed',
    `prompt_version` VARCHAR(32) NOT NULL,
    `model` VARCHAR(64) NOT NULL,
    `fact_lines_json` MEDIUMTEXT NOT NULL,
    `status` ENUM('warmed','already_cached','skipped','error') NOT NULL,
    `duration_ms` INT(11) NOT NULL DEFAULT 0,
    `model_called` TINYINT(1) NOT NULL DEFAULT 0,
    `correlation_id` CHAR(32) NOT NULL,
    `error` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_day_pid_provider` (`target_date`, `pid`, `provider_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
