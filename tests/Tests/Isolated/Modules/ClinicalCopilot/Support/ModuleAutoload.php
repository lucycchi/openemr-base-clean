<?php

/**
 * Registers the module PSR-4 prefix for isolated tests (no DB, so no module manager).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use Composer\Autoload\ClassLoader;

final class ModuleAutoload
{
    public static function register(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (!$loader instanceof ClassLoader) {
            throw new \RuntimeException('Composer ClassLoader not available');
        }
        $loader->addPsr4(
            'OpenEMR\\Modules\\ClinicalCopilot\\',
            dirname(__DIR__, 6) . '/interface/modules/custom_modules/oe-module-clinical-copilot/src/'
        );
    }
}
