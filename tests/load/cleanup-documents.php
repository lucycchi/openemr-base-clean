<?php

/**
 * Removes every Co-Pilot document the load test attached to one patient,
 * with the lab rows, provenance rows, intake rows, the OpenEMR document and
 * the briefing cache it produced, so the seed data is as seeded again.
 * Uses the same removal the eval harness uses after its PHI cases.
 *
 * Run inside the openemr container as the web user:
 *   php tests/load/cleanup-documents.php <pid>
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Load;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Row;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;

use function OpenEMR\Tests\Evals\removeDocument;

$ignoreAuth = 1;
$_GET['site'] = 'default';
$sessionAllowWrite = true;
require_once dirname(__DIR__, 2) . '/interface/globals.php';
require_once dirname(__DIR__) . '/evals/phi.php';

$input = new ArgvInput(null, new InputDefinition([new InputArgument('pid', InputArgument::REQUIRED, 'The load patient')]));
$pidArg = $input->getArgument('pid');
$pid = is_string($pidArg) && ctype_digit($pidArg) ? (int) $pidArg : 0;
if ($pid <= 0) {
    throw new RuntimeException('usage: cleanup-documents.php <pid>');
}
$docs = QueryUtils::fetchRecords("SELECT document_id FROM copilot_document WHERE pid = ?", [$pid]);
foreach ($docs as $d) {
    removeDocument(Row::int($d, 'document_id'));
}
QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$pid]);
$left = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_document WHERE pid = ?", 'n', [$pid]);
printf("pid %d: removed %d documents, %s left\n", $pid, count($docs), is_numeric($left) ? (string) (int) $left : '?');
