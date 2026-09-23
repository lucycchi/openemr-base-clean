--
-- Clinical Co-Pilot Module
-- Upgrade 0.1.0 -> 0.1.1: adds the pre-warm receipts table
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- Applied by Module Manager > Upgrade (InstallerController::UpgradeModuleSQL)
-- when version.php is 0.1.1 or later and the recorded sql_version is older.
-- Sites that enabled the module at 0.1.0 have copilot_briefing_cache but not
-- copilot_prewarm; install.sql only runs on first enable. Same #IfNotTable
-- guard as install.sql, so it is a no-op where the table already exists.
-- Manual fallback (no Module Manager): pipe this file, minus the #If/#EndIf
-- lines, into mariadb — see docker/vps/README.md.

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
    `status` ENUM('warmed','already_cached','skipped','error') NOT NULL, -- mirrors PrewarmStatus.php
    `duration_ms` INT(11) NOT NULL DEFAULT 0,
    `model_called` TINYINT(1) NOT NULL DEFAULT 0,
    `correlation_id` CHAR(32) NOT NULL,
    `error` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Serves DbPrewarmReceipts::latestFor(): "receipt for this patient today, preferring this provider".
    KEY `idx_day_pid_provider` (`target_date`, `pid`, `provider_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
