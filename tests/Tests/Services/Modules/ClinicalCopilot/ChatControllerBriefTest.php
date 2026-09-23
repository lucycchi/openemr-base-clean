<?php

/**
 * DB-backed pin for the brief action when the sidecar cannot be reached:
 * the facts still arrive, the guideline section says "unavailable" (or
 * "no_triggers" when nothing fired for the seeded patient), and the HTTP
 * status is 200. A 500 here would blank the whole panel for a physician
 * because one optional dependency was down.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\Ops\NullTracer;
use OpenEMR\Modules\ClinicalCopilot\Row;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ChatControllerBriefTest extends TestCase
{
    private int $pid = 0;

    protected function setUp(): void
    {
        ModuleAutoload::register();
        $row = QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid LIMIT 1");
        $this->pid = is_array($row) ? Row::int($row, 'pid') : 0;
        if ($this->pid <= 0) {
            self::markTestSkipped('needs a seeded patient');
        }
    }

    protected function tearDown(): void
    {
        if ($this->pid > 0) {
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$this->pid]);
        }
    }

    public function testBriefWithoutSidecarStillReturnsFacts(): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $session->set('authUser', 'admin');
        $session->set('authUserID', 1);
        $session->set('authProvider', 'Default');
        PatientSessionUtil::setPid($this->pid);
        CsrfUtils::setupCsrfKey($session);
        $csrf = CsrfUtils::collectCsrfToken($session);

        // No chat model, and a sidecar URL nothing listens on.
        $config = new Config('', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', sidecarUrl: 'http://127.0.0.1:9');
        $logger = new Logger('test', [new TestHandler()]);
        $request = Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'brief']);

        ob_start();
        (new ChatController($logger, $request, $config, new NullTracer()))->handleRequest();
        $body = json_decode((string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertSame(200, http_response_code() ?: 200);
        self::assertArrayHasKey('facts', $body);
        self::assertArrayHasKey('guidelines', $body);
        $guidelines = $body['guidelines'];
        self::assertIsArray($guidelines);
        self::assertContains($guidelines['status'], ['unavailable', 'no_triggers'], 'an unreachable sidecar must degrade, not fail');
        self::assertSame([], $guidelines['cards']);
        $narration = $body['narration'];
        self::assertIsArray($narration);
        self::assertSame('AI summary unavailable: not configured on this server', $narration['status']);
    }
}
