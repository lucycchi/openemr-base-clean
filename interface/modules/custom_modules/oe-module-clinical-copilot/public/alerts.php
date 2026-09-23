<?php

/**
 * Alert webhook receiver. Langfuse alerts (p95 latency, error rate, tool
 * failure rate; see clinical_copilot/ALERTS.md) POST here so every firing
 * lands in the application log and the OpenEMR audit log next to the
 * requests that caused it.
 *
 * Authentication: Langfuse's signed x-langfuse-signature header verified with
 * LANGFUSE_WEBHOOK_SECRET, or an X-Alert-Token header equal to
 * ALERT_WEBHOOK_SECRET. The token is accepted from the header only: a
 * ?token= query string would be recorded in proxy and access logs.
 * No session, no patient data, no chart access.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = true;
require_once __DIR__ . "/../../../../globals.php";

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Ops\AlertReceiver;
use OpenEMR\Modules\ClinicalCopilot\Ops\AlertRejected;
use OpenEMR\Modules\ClinicalCopilot\Ops\CorrelatedLogger;
use Symfony\Component\HttpFoundation\Request;

// Symfony Request wraps $_SERVER/$_POST/php://input in a typed object.
$request = Request::createFromGlobals();
$correlationId = CorrelationId::generate();
$logger = new CorrelatedLogger(ServiceContainer::getLogger(), $correlationId);

header("Content-Type: application/json");
header("Cache-Control: no-store");
header("X-Correlation-Id: " . $correlationId);

if ($request->getMethod() !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "POST only", "correlation_id" => $correlationId], JSON_THROW_ON_ERROR);
    return;
}

// Authentication + parsing are delegated to AlertReceiver (pure, unit-tested);
// this file only maps its exceptions to HTTP status codes.
$token = $request->headers->get('X-Alert-Token') ?? '';
$signature = $request->headers->get('x-langfuse-signature') ?? '';
$config = Config::fromEnvironment();

try {
    $event = (new AlertReceiver($config->alertWebhookSecret, $config->langfuseWebhookSecret))
        ->receive($token, (string) $request->getContent(), $signature);
} catch (AlertRejected $e) {
    $logger->warning('copilot alert rejected', ['reason' => $e->getMessage(), 'ip' => $request->getClientIp()]);
    http_response_code($e->httpStatus);
    echo json_encode(["error" => $e->getMessage(), "correlation_id" => $correlationId], JSON_THROW_ON_ERROR);
    return;
}

// WARNING so it is always written regardless of the NOTICE threshold; an
// alert is by definition something an operator must see.
$logger->warning('copilot alert received', $event->toLogContext());
EventAuditLogger::getInstance()->newEvent('clinical-copilot-alert', 'langfuse', '', 1, $event->auditComment() . ' correlation_id=' . $correlationId);

echo json_encode(["received" => true, "alert" => $event->name, "severity" => $event->severity, "correlation_id" => $correlationId], JSON_THROW_ON_ERROR);
