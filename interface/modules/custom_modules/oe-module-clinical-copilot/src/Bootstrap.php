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
use OpenEMR\Modules\ClinicalCopilot\Command\PrewarmCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class Bootstrap
{
    private const MODULE_PATH = '/interface/modules/custom_modules/oe-module-clinical-copilot';

    private readonly LoggerInterface $logger;

    public function __construct(private readonly EventDispatcherInterface $dispatcher, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? ServiceContainer::getLogger();
    }

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
    }

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
            echo $this->panelHtml($base, $csrf);
        } catch (\RuntimeException $e) {
            // Never let the panel break the chart page.
            $this->logger->error('copilot panel render failed', ['exception' => $e]);
        }
    }

    private function panelHtml(string $base, string $csrf): string
    {
        $endpoint = htmlspecialchars($base . '/public/chat.php', ENT_QUOTES);
        $css = htmlspecialchars($base . '/public/assets/panel.css', ENT_QUOTES);
        $js = htmlspecialchars($base . '/public/assets/panel.js', ENT_QUOTES);
        $token = htmlspecialchars($csrf, ENT_QUOTES);
        return <<<HTML
<link rel="stylesheet" href="{$css}">
<div id="copilot-panel" class="card mb-3" data-endpoint="{$endpoint}" data-csrf="{$token}">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><strong>Clinical Co-Pilot</strong> <small class="text-muted">what changed since last visit</small></span>
    <span id="copilot-status" class="small text-muted">loading chart facts…</span>
  </div>
  <div class="card-body">
    <div id="copilot-narration" class="copilot-narration"></div>
    <div id="copilot-facts" class="copilot-facts"></div>
    <form id="copilot-ask" class="copilot-ask" autocomplete="off">
      <input type="text" id="copilot-question" class="form-control" maxlength="500" placeholder="Ask about this chart (answers cite facts above)…" disabled>
      <button type="submit" class="btn btn-primary btn-sm" disabled>Ask</button>
    </form>
    <div id="copilot-thread" class="copilot-thread"></div>
  </div>
</div>
<script src="{$js}" defer></script>
HTML;
    }
}
