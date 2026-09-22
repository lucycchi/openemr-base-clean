<?php

/**
 * Subscribes the Co-Pilot panel to the patient dashboard render event.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Command\CommandRunnerFilterEvent;
use OpenEMR\Events\PatientDemographics\RenderEvent;
use OpenEMR\Modules\ClinicalCopilot\Command\AttachCommand;
use OpenEMR\Modules\ClinicalCopilot\Command\PrewarmCommand;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentIngestService;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStore;
use OpenEMR\Modules\ClinicalCopilot\Documents\ExtractionRunner;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The module's integration with OpenEMR core. openemr.bootstrap.php creates
 * one of these at startup and calls subscribeToEvents(); from then on the
 * module reacts to two core events:
 *   - the patient summary page rendering (inject the panel HTML), and
 *   - the CLI command runner collecting commands (register copilot:prewarm).
 * This is the only class that touches core globals, sessions or `new` on
 * DB-backed classes; everything below it is injected.
 */
final class Bootstrap
{
    private const MODULE_PATH = '/interface/modules/custom_modules/oe-module-clinical-copilot';

    private readonly LoggerInterface $logger;

    public function __construct(private readonly EventDispatcherInterface $dispatcher, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? ServiceContainer::getLogger();
    }

    /** `$this->method(...)` is PHP's first-class-callable syntax — it passes the method as a callback. */
    public function subscribeToEvents(): void
    {
        $this->dispatcher->addListener(RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE, $this->renderPanel(...));
        $this->dispatcher->addListener(CommandRunnerFilterEvent::EVENT_NAME, $this->registerCommands(...));
    }

    /**
     * bin/console has loaded globals by the time this fires, so the site's
     * PHP zone (set from gbl_time_zone when configured) is the zone the
     * panel's own clock uses: pinning the pre-warm to it keeps the hashes equal.
     */
    public function registerCommands(CommandRunnerFilterEvent $event): void
    {
        // Composition root for the CLI: wire real DB/ACL/OpenAI implementations
        // into the Prewarmer. Falls back to UnconfiguredNarrator when no API
        // key is set so `--dry-run` still works.
        $config = Config::fromEnvironment();
        $tz = new \DateTimeZone(date_default_timezone_get());
        $prewarmer = new Prewarmer(
            new DbScheduleSource(),
            new OpenEmrChartSource(),
            static fn(string $username): Authorization => new AclAuthorization($username),
            $config->hasOpenAi() ? new PipelineNarrator($config) : new UnconfiguredNarrator(),
            $tz,
            new DbPrewarmReceipts($config->openAiModel),
        );
        $lock = new FileRunLock(OEGlobalsBag::getInstance()->getString('OE_SITE_DIR') . '/documents/copilot/prewarm.lock');
        $event->setCommand(PrewarmCommand::class, new PrewarmCommand($config, $prewarmer, ServiceContainer::getClock(), $tz, $lock));
        // Week 2: attach_and_extract(pid, file, doc_type) from the CLI, on the same path as the panel.
        $store = new DocumentStore();
        $event->setCommand(AttachCommand::class, new AttachCommand($store, new ExtractionRunner($store, SidecarClient::fromConfig($config), new DocumentIngestService())));
    }

    /**
     * Fires while the patient summary page is being built. Echoes the panel
     * markup (server-rendered card + a <script> that fetches the briefing).
     * The CSRF token is embedded so panel.js can POST to chat.php.
     */
    public function renderPanel(RenderEvent $event): void
    {
        $pid = $event->getPid();
        if (!is_numeric($pid) || (int) $pid <= 0) {
            return;
        }
        try {
            $webroot = OEGlobalsBag::getInstance()->getString('webroot');
            $session = SessionWrapperFactory::getInstance()->getActiveSession();
            $csrf = CsrfUtils::collectCsrfToken(session: $session);
            $base = $webroot . self::MODULE_PATH;
            echo $this->panelHtml($base, $csrf, $webroot, (int) $pid);
        } catch (\RuntimeException $e) {
            // Never let the panel break the chart page.
            $this->logger->error('copilot panel render failed', ['exception_class' => $e::class]);
        }
    }

    /**
     * The panel's static skeleton. Every dynamic value is HTML-escaped before
     * interpolation. panel.js reads data-endpoint and data-csrf from the root
     * div and fills the empty containers.
     */
    private function panelHtml(string $base, string $csrf, string $webroot, int $pid): string
    {
        $endpoint = htmlspecialchars($base . '/public/chat.php', ENT_QUOTES);
        $documents = htmlspecialchars($base . '/public/documents.php', ENT_QUOTES);
        // OpenEMR's own ACL-checked document retrieval; the viewer fetches bytes from here.
        $docUrl = htmlspecialchars($webroot . '/controller.php?document&retrieve&patient_id={pid}&document_id={id}&as_file=false', ENT_QUOTES);
        $css = htmlspecialchars($base . '/public/assets/panel.css', ENT_QUOTES);
        $js = htmlspecialchars($base . '/public/assets/panel.js', ENT_QUOTES);
        $viewer = htmlspecialchars($base . '/public/assets/source-viewer.js', ENT_QUOTES);
        $token = htmlspecialchars($csrf, ENT_QUOTES);
        $pidAttr = htmlspecialchars((string) $pid, ENT_QUOTES);
        return <<<HTML
<link rel="stylesheet" href="{$css}">
<div id="copilot-panel" class="card mb-3" data-endpoint="{$endpoint}" data-documents-endpoint="{$documents}" data-doc-url="{$docUrl}" data-pid="{$pidAttr}" data-csrf="{$token}">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><strong>Clinical Co-Pilot</strong> <small class="text-muted">what changed since last visit</small></span>
    <span id="copilot-status" class="small text-muted">loading chart facts…</span>
  </div>
  <div class="card-body">
    <div id="copilot-narration" class="copilot-narration"></div>
    <div id="copilot-facts" class="copilot-facts"></div>
    <div id="copilot-documents" class="copilot-documents">
      <h6>Uploaded documents</h6>
      <ul id="copilot-document-list"></ul>
      <form id="copilot-upload" class="copilot-upload" autocomplete="off">
        <select id="copilot-doc-type" class="form-control form-control-sm">
          <option value="lab_pdf">Lab report (PDF)</option>
          <option value="intake_form">Intake form (PDF)</option>
        </select>
        <input type="file" id="copilot-file" class="form-control-file" accept="application/pdf">
        <button type="submit" class="btn btn-outline-primary btn-sm">Upload and extract</button>
      </form>
      <div id="copilot-upload-status" class="copilot-muted"></div>
    </div>
    <form id="copilot-ask" class="copilot-ask" autocomplete="off">
      <input type="text" id="copilot-question" class="form-control" maxlength="500" placeholder="Ask about this chart (answers cite facts above)…" disabled>
      <button type="submit" class="btn btn-primary btn-sm" disabled>Ask</button>
    </form>
    <div id="copilot-thread" class="copilot-thread"></div>
  </div>
</div>
<script src="{$js}" defer></script>
<script type="module" src="{$viewer}"></script>
HTML;
    }
}
