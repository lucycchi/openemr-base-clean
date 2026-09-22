--
-- Clinical Co-Pilot Module
-- Upgrade 0.1.2 -> 0.1.3: record document-vs-chart mismatches (week 2, intake forms)
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- Idempotent: MODIFY to the same definition is a no-op. The new kinds carry a
-- flag only ("the name on the form does not match the chart"); the values
-- from the form are compared and never stored.
ALTER TABLE `copilot_intake` MODIFY `kind` ENUM('chief_concern','medication','allergy','family_history','form_date','demographics_mismatch') NOT NULL;
ALTER TABLE `copilot_document_fact` MODIFY `kind` ENUM('lab_result','collection_date','reported_date','unextracted_row','intake_field','patient_mismatch') NOT NULL;
