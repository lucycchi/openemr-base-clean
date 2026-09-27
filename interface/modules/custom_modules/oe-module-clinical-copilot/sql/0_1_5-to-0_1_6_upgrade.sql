--
-- Clinical Co-Pilot Module
-- Upgrade 0.1.5 -> 0.1.6: a third document type, the outside medication list
--
-- @package   OpenEMR
-- @link      https://www.open-emr.org
-- @author    Lucy Chi <lucychi@berkeley.edu>
-- @copyright Copyright (c) 2026 Lucy Chi
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

-- Idempotent as written: docker/vps/deploy.sh applies every upgrade file on
-- every deploy, and MODIFY to the same definition changes nothing. The full
-- column definition is repeated, NOT NULL included, because a MODIFY that
-- left it out would quietly make the column nullable. The existing values
-- ('lab_pdf', 'intake_form') keep their meaning; only the new one is added.

ALTER TABLE `copilot_document` MODIFY `doc_type` ENUM('lab_pdf','intake_form','medication_list') NOT NULL;
