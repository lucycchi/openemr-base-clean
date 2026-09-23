--
-- Clinical Co-Pilot Module
-- Upgrade 0.1.1 -> 0.1.2: document ingestion tables (week 2)
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- Applied by Module Manager > Upgrade, or by hand with plain idempotent SQL
-- (every statement is CREATE TABLE IF NOT EXISTS, so the #If guards are a
-- courtesy for Module Manager and the file can be piped into mariadb as is
-- after stripping the #If/#EndIf lines; docker/vps/deploy.sh does that).
--
-- "Idempotent" means the file can be run any number of times with the same
-- end result: IF NOT EXISTS makes a second run skip a table that is already
-- there instead of failing, so a half-applied upgrade (or a re-deploy) is
-- simply re-run rather than repaired by hand.
--
-- Three tables rather than one, because three different things are stored:
--   copilot_document       one row per uploaded file: what it is, how far
--                          its extraction got (the status), and its hash
--   copilot_document_fact  one row per field read from a lab report: where
--                          on the page it was found and what chart row it
--                          became (the provenance behind every citation)
--   copilot_intake         one row per line the patient wrote on an intake
--                          form; these are the patient's words, not lab
--                          results, so they never touch the lab tables
-- The lab values themselves go to OpenEMR's own procedure_* tables (see
-- DocumentIngestService), so the rest of OpenEMR sees them as ordinary results.

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
    -- UNIQUE means the database itself refuses a second row with the same
    -- (pid, hash) pair, so even two simultaneous uploads of one file cannot
    -- both succeed. The pair, not the hash alone, so the same file uploaded
    -- for two patients is two documents.
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
    `kind` ENUM('lab_result','collection_date','reported_date','unextracted_row','intake_field') NOT NULL,
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
    -- One row per field per document: a repeat extraction cannot write a
    -- second set of provenance rows even if the code-level check is bypassed.
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
    `kind` ENUM('chief_concern','medication','allergy','family_history','form_date') NOT NULL,
    `value` VARCHAR(500) NOT NULL,
    `detail` VARCHAR(255) NULL COMMENT 'dose/frequency, reaction, or relative',
    `anchored` TINYINT(1) NOT NULL DEFAULT 0,
    `page` INT(11) NULL,
    `bbox_json` VARCHAR(512) NULL,
    `row_bbox_json` VARCHAR(512) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Same guarantee as copilot_document_fact: one row per field per document.
    UNIQUE KEY `uq_document_field` (`document_id`, `field_path`),
    KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
#EndIf
