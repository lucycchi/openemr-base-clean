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
