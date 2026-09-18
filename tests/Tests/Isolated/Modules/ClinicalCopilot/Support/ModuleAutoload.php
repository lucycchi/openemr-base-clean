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

/**
 * Registers the module's PSR-4 namespace with Composer's autoloader.
 *
 * Why this exists: the module lives under interface/modules/custom_modules/,
 * which is NOT in the root composer.json autoload map — OpenEMR normally
 * loads modules at runtime via ModulesClassLoader after bootstrapping the
 * whole app. The isolated test suite skips that bootstrap (no DB, no
 * globals), so each test class calls register() in setUpBeforeClass() to
 * make `OpenEMR\Modules\ClinicalCopilot\*` resolvable.
 */
final class ModuleAutoload
{
    public static function register(): void
    {
        // Grab the already-active Composer loader and add one more prefix to it.
        // dirname(__DIR__, 6) walks up from Support/ to the repository root.
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
