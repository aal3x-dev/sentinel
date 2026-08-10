<?php

/**
 * -------------------------------------------------------------------------
 * Sentinel plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Sentinel plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/pluginsGLPI/sentinel
 * -------------------------------------------------------------------------
 */

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_SENTINEL_VERSION', '0.3.10');

// Minimal GLPI version, inclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_SENTINEL_MIN_GLPI_VERSION", "11.0.0");

// Maximum GLPI version, exclusive
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define("PLUGIN_SENTINEL_MAX_GLPI_VERSION", "11.0.99");

use Glpi\Plugin\Hooks;
use GlpiPlugin\Sentinel\Config;
use GlpiPlugin\Sentinel\Issue;
use GlpiPlugin\Sentinel\Profile;

/**
 * Init hooks of the plugin.
 * REQUIRED
 */
function plugin_init_sentinel(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Config::class, ['addtabon' => \Config::class]);
    Plugin::registerClass(Profile::class, ['addtabon' => \Profile::class]);

    // Self-heal: GLPI's plugin reactivation after a version bump has been
    // observed to go straight back to "Enabled" without re-running
    // plugin_sentinel_install() - the only place rights normally get
    // granted, which silently left every profile locked out. Rather than
    // depend on exactly when GLPI decides to re-run install(), repair it
    // here whenever the session shows the right missing. The cheap
    // in-memory haveRight() check first means this costs nothing extra
    // once things are in order - the DB queries only run for the
    // profiles actually affected.
    if (!Session::haveRight('plugin_sentinel', READ) && !empty($_SESSION['glpiactiveprofile']['id'])) {
        if (Profile::ensureRightsRegistered()) {
            Session::reloadCurrentProfile();
        }
    }

    if (Session::haveRight('plugin_sentinel', READ)) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['sentinel'] = [
            'tools' => Issue::class,
        ];
    }

    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['sentinel'] = 'front/config.php';
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array{
 *      name: string,
 *      version: string,
 *      author: string,
 *      license: string,
 *      homepage: string,
 *      requirements: array{
 *          glpi: array{
 *              min: string,
 *              max: string,
 *          }
 *      }
 * }
 */
function plugin_version_sentinel(): array
{
    return [
        'name'           => 'Sentinel',
        'version'        => PLUGIN_SENTINEL_VERSION,
        'author'         => '<a href="http://www.teclib.com">Teclib\'</a>',
        'license'        => 'MIT',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_SENTINEL_MIN_GLPI_VERSION,
                'max' => PLUGIN_SENTINEL_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Check pre-requisites before install
 * OPTIONAL
 */
function plugin_sentinel_check_prerequisites(): bool
{
    return true;
}

/**
 * Check configuration process
 * OPTIONAL
 *
 * @param bool $verbose Whether to display message on failure. Defaults to false.
 */
function plugin_sentinel_check_config(bool $verbose = false): bool
{
    // Your configuration check
    return true;

    // Example:
    // if ($verbose) {
    //    echo __('Installed / not configured', 'sentinel');
    // }
    // return false;
}
