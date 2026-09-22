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

-- #IfNotTable / #EndIf are OpenEMR upgrade-script directives (not SQL): the
-- block runs only when the named table is absent, so the script is safe to
-- re-run on every module enable.

#IfNotTable copilot_briefing_cache
-- Verified briefing narrations keyed by facts hash + prompt version + model.
-- Holds fact ids and narration text only; no direct identifiers.
CREATE TABLE IF NOT EXISTS `copilot_briefing_cache` (
    `cache_key` CHAR(64) NOT NULL COMMENT 'sha256(facts_hash|prompt_version|model)',
    `pid` BIGINT(20) NOT NULL,
    `facts_hash` CHAR(64) NOT NULL,
    `prompt_version` VARCHAR(32) NOT NULL,
    `model` VARCHAR(64) NOT NULL,
    `narration_json` MEDIUMTEXT NOT NULL,          -- raw model JSON; re-verified on every read
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

-- 0.1.2: document ingestion (week 2); same statements as sql/0_1_1-to-0_1_2_upgrade.sql
#IfNotTable copilot_document
-- One row per uploaded document the Co-Pilot knows about. The bytes live in
-- OpenEMR's own documents table/storage (documents.id); this row carries the
-- Co-Pilot's view: type, extraction status and the patient-scoped hash used
-- for deduplication. hash is OpenEMR's sha3-512 of the file (documents.hash).
CREATE TABLE IF NOT EXISTS `copilot_document` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `document_id` BIGINT(20) NOT NULL COMMENT 'references documents.id',
    `pid` BIGINT(20) NOT NULL,
    `doc_type` ENUM('lab_pdf','intake_form') NOT NULL,
    `hash` CHAR(128) NOT NULL COMMENT 'sha3-512 hex of the file bytes, same as documents.hash',
    `status` ENUM('stored','extracted','failed') NOT NULL DEFAULT 'stored',
    `failure_reason` VARCHAR(32) NULL COMMENT 'encrypted, unreadable, too_many_pages, model_error, schema_mismatch, timeout',
    `confidence` DECIMAL(4,3) NULL COMMENT 'anchored citations / citations, from the sidecar',
    `pages` INT(11) NULL,
    `uploaded_by` VARCHAR(255) NOT NULL COMMENT 'users.username',
    `correlation_id` CHAR(32) NULL COMMENT 'of the extraction run',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `extracted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    -- Patient-scoped dedup and a guard against concurrent double uploads.
    UNIQUE KEY `uq_pid_hash` (`pid`, `hash`),
    KEY `idx_document` (`document_id`),
    KEY `idx_pid_status` (`pid`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfNotTable copilot_document_fact
-- Provenance for every extracted field: where on the page it was anchored
-- (or that it was not), and which OpenEMR row it became. Values here are
-- clinical strings from synthetic documents; never identifiers.
CREATE TABLE IF NOT EXISTS `copilot_document_fact` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `document_id` BIGINT(20) NOT NULL COMMENT 'references documents.id',
    `field_path` VARCHAR(128) NOT NULL COMMENT 'JSON pointer into the extraction, e.g. /results/3/value',
    `kind` ENUM('lab_result','collection_date','reported_date','unextracted_row','intake_field','patient_mismatch') NOT NULL,
    `analyte` VARCHAR(255) NULL,
    `value` VARCHAR(255) NOT NULL,
    `unit` VARCHAR(64) NULL,
    `loinc` VARCHAR(31) NULL,
    `reference_range` VARCHAR(64) NULL,
    `abnormal_flag` VARCHAR(4) NULL,
    `unit_mismatch` TINYINT(1) NOT NULL DEFAULT 0,
    `anchored` TINYINT(1) NOT NULL DEFAULT 0,
    `page` INT(11) NULL,
    `bbox_json` VARCHAR(512) NULL COMMENT 'citation bbox, PDF points, top-left origin',
    `row_bbox_json` VARCHAR(512) NULL,
    `procedure_result_id` BIGINT(20) NULL COMMENT 'the procedure_result row this became, when anchored',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_document_field` (`document_id`, `field_path`),
    KEY `idx_result` (`procedure_result_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf

#IfNotTable copilot_intake
-- Extracted intake-form fields (chief concern, medications, allergies,
-- family history). Demographics are compared to the chart and never stored.
CREATE TABLE IF NOT EXISTS `copilot_intake` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `document_id` BIGINT(20) NOT NULL COMMENT 'references documents.id',
    `pid` BIGINT(20) NOT NULL,
    `field_path` VARCHAR(128) NOT NULL,
    `kind` ENUM('chief_concern','medication','allergy','family_history','form_date','demographics_mismatch') NOT NULL,
    `value` VARCHAR(500) NOT NULL,
    `detail` VARCHAR(255) NULL COMMENT 'dose/frequency, reaction, or relative',
    `anchored` TINYINT(1) NOT NULL DEFAULT 0,
    `page` INT(11) NULL,
    `bbox_json` VARCHAR(512) NULL,
    `row_bbox_json` VARCHAR(512) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_document_field` (`document_id`, `field_path`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
