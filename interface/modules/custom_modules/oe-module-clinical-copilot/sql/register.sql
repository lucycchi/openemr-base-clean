-- Registers and enables the module without the Module Manager UI (same rows it writes).
-- Run after install.sql:  mariadb ... openemr < register.sql
INSERT INTO `modules` (`mod_name`, `mod_directory`, `mod_parent`, `mod_type`, `mod_active`, `mod_ui_name`, `mod_relative_link`, `mod_ui_order`, `mod_ui_active`, `mod_description`, `mod_nick_name`, `mod_enc_menu`, `permissions_item_table`, `directory`, `date`, `sql_run`, `type`, `sql_version`, `acl_version`)
SELECT 'oe-module-clinical-copilot', 'oe-module-clinical-copilot', '', '', 1, 'Clinical Co-Pilot', 'public/', 0, 1, 'Verified pre-room briefing and chart Q&A', '', '', NULL, '', NOW(), 1, 0, '0', ''
WHERE NOT EXISTS (SELECT 1 FROM `modules` WHERE `mod_directory` = 'oe-module-clinical-copilot');
UPDATE `modules` SET `mod_active` = 1, `sql_run` = 1 WHERE `mod_directory` = 'oe-module-clinical-copilot';
