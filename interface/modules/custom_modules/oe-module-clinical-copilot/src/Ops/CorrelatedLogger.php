<?php

/**
 * PSR-3 decorator: every entry carries correlation_id so a request can be reconstructed from logs alone.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

final class CorrelatedLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner, private readonly string $correlationId)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context + ['correlation_id' => $this->correlationId]);
    }
}
