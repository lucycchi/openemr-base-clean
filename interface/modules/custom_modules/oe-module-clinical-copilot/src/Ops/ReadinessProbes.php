<?php

/**
 * Real dependency probes for ready.php, bounded to 2s each.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Config;

final class ReadinessProbes
{
    public static function readiness(?Config $config = null): Readiness
    {
        $config ??= Config::fromEnvironment();
        $http = new Client(['timeout' => 2.0, 'connect_timeout' => 1.0, 'http_errors' => false]);
        return new Readiness([
            'database' => static function (): ?string {
                try {
                    $row = QueryUtils::querySingleRow('SELECT 1', [], false);
                } catch (\RuntimeException) {
                    return 'database query failed';
                }
                return $row === false ? 'database returned nothing' : null;
            },
            'openai' => static function () use ($http, $config): ?string {
                if (!$config->hasOpenAi()) {
                    return 'openai not configured';
                }
                try {
                    $code = $http->request('GET', 'https://api.openai.com/v1/models', ['headers' => ['Authorization' => 'Bearer ' . $config->openAiApiKey]])->getStatusCode();
                } catch (GuzzleException) {
                    return 'openai unreachable';
                }
                return $code === 200 ? null : 'openai returned HTTP ' . $code;
            },
            'langfuse' => static function () use ($http, $config): ?string {
                if (!$config->hasLangfuse()) {
                    return 'langfuse not configured';
                }
                try {
                    $code = $http->request('GET', rtrim($config->langfuseHost, '/') . '/api/public/health')->getStatusCode();
                } catch (GuzzleException) {
                    return 'langfuse unreachable';
                }
                return $code === 200 ? null : 'langfuse returned HTTP ' . $code;
            },
        ], FileReadinessStore::default(), ServiceContainer::getClock());
    }
}
