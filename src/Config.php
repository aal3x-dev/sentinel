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

namespace GlpiPlugin\Sentinel;

use CommonGLPI;
use Config as GlpiConfig;
use Html;
use Plugin;
use Session;

/**
 * Plugin configuration, stored in glpi_configs (context 'plugin:sentinel').
 *
 * Settings are intentionally conservative: automatic actions never delete
 * anything by themselves unless the administrator explicitly turns on
 * auto-clean, and even then only for findings above the confirmation
 * threshold (age in days) is exposed for a future iteration.
 */
class Config extends GlpiConfig
{
    public static $rightname = 'plugin_sentinel';

    /** Context used to store/read values in glpi_configs */
    public const CONTEXT = 'plugin:sentinel';

    /**
     * Default configuration values.
     *
     * - excluded_tables: tables never scanned (technical/log/session tables)
     * - excluded_fields: "table.field" pairs known to be false positives
     *   for the naming-convention FK resolver (comma separated)
     * - scan_polymorphic: scan itemtype/items_id relations
     * - scan_foreignkeys: scan classic *_id foreign keys
     * - batch_size: rows processed per chunk during a scan
     * - auto_clean: if 1, the cron task deletes confirmed findings itself
     *   (OFF by default - cleanup must be validated by a human)
     */
    public static function getDefaults(): array
    {
        return [
            'excluded_tables'    => implode(',', [
                'glpi_configs',
                'glpi_logs',
                'glpi_sessions',
                'glpi_crontasks',
                'glpi_crontasklogs',
                'glpi_queuednotifications',
                'glpi_plugin_sentinel_issues',
            ]),
            'excluded_fields'    => '',
            'scan_polymorphic'   => 1,
            'scan_foreignkeys'   => 1,
            'scan_missing_files' => 1,
            // Deletes real files from disk - reviewed and enabled
            // explicitly by an admin, never on by default.
            'scan_orphan_files'  => 0,
            'batch_size'         => 500,
            'auto_clean'         => 0,
            'retention_days'     => 30,
        ];
    }

    public static function getConfig(): array
    {
        $stored = GlpiConfig::getConfigurationValues(self::CONTEXT);
        return array_merge(self::getDefaults(), $stored);
    }

    public static function getTypeName($nb = 0)
    {
        return __('Sentinel', 'sentinel');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiConfig) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiConfig) {
            self::showTabSummary();
        }
        return true;
    }

    /**
     * The core Config page (Setup > General) already wraps its whole
     * body in a single <form>. Embedding a second, independent <form>
     * inside it here would be invalid HTML - browsers implicitly close
     * the outer form as soon as they parse a nested <form> start tag,
     * which silently breaks whatever core Config controls come after
     * our tab on that same page. So this tab only shows a short summary
     * and links out to our own dedicated page (front/config.php), which
     * has the only real settings <form>.
     */
    public static function showTabSummary(): void
    {
        if (!self::canView()) {
            return;
        }

        $config = self::getConfig();
        $url    = Plugin::getWebDir('sentinel') . '/front/config.php';

        echo "<div class='center' style='padding: 1em;'>";
        echo "<p>" . __('Database checks:', 'sentinel') . ' '
            . (($config['scan_polymorphic'] || $config['scan_foreignkeys']) ? __('enabled', 'sentinel') : __('disabled', 'sentinel'))
            . "<br>" . __('Document checks:', 'sentinel') . ' '
            . (($config['scan_missing_files'] || $config['scan_orphan_files']) ? __('enabled', 'sentinel') : __('disabled', 'sentinel'))
            . "</p>";
        echo "<a class='btn btn-primary' href='" . htmlspecialchars($url) . "'>"
            . __('Open Sentinel settings', 'sentinel') . "</a>";
        echo "</div>";
    }

    public static function showForConfig(): void
    {
        if (!self::canView()) {
            return;
        }

        $config   = self::getConfig();
        $can_edit = Session::haveRight(self::$rightname, UPDATE);
        $ro       = $can_edit ? '' : 'disabled';

        $yesno = static function (string $name, $value) use ($ro) {
            $sel0 = ((int) $value === 0) ? 'selected' : '';
            $sel1 = ((int) $value === 1) ? 'selected' : '';
            echo "<select name='{$name}' class='form-select' $ro>";
            echo "<option value='0' $sel0>" . __('No') . "</option>";
            echo "<option value='1' $sel1>" . __('Yes') . "</option>";
            echo "</select>";
        };

        // One field = one labeled row, consistent spacing/width regardless
        // of the control type. $control is a callback so it can render a
        // select, input or textarea while sharing the same row markup.
        $field = static function (string $label, callable $control, ?string $help = null) {
            echo "<div class='row mb-3 align-items-start'>";
            echo "<label class='col-sm-5 col-form-label'>" . $label;
            if ($help !== null) {
                echo "<div class='form-text text-muted mt-0'>" . $help . "</div>";
            }
            echo "</label>";
            echo "<div class='col-sm-7'>";
            $control();
            echo "</div>";
            echo "</div>";
        };

        $section = static function (string $icon, string $title) {
            echo "<div class='card mb-3'>";
            echo "<div class='card-header d-flex align-items-center gap-2'>";
            echo "<i class='ti $icon'></i><strong>" . $title . "</strong>";
            echo "</div>";
            echo "<div class='card-body'>";
        };
        $end_section = static function () {
            echo "</div></div>"; // .card-body .card
        };

        echo "<form name='form' action='' method='post' class='mt-3' style='max-width: 900px;'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        $section('ti-database', __('Database checks', 'sentinel'));

        $field(__('Scan itemtype/items_id relations', 'sentinel'), function () use ($yesno, $config) {
            $yesno('scan_polymorphic', $config['scan_polymorphic']);
        }, __('Polymorphic relations used across GLPI core (documents, notepads, contracts...).', 'sentinel'));

        $field(__('Scan classic foreign keys (*_id)', 'sentinel'), function () use ($yesno, $config) {
            $yesno('scan_foreignkeys', $config['scan_foreignkeys']);
        }, __('Resolved from column names; ambiguous ones are skipped, never guessed.', 'sentinel'));

        $field(__('Excluded tables', 'sentinel'), function () use ($ro, $config) {
            echo "<textarea name='excluded_tables' class='form-control' rows='2' $ro>"
                . htmlspecialchars($config['excluded_tables']) . "</textarea>";
        }, __('Comma separated.', 'sentinel'));

        $field(__('Excluded fields', 'sentinel'), function () use ($ro, $config) {
            echo "<textarea name='excluded_fields' class='form-control' rows='2' $ro>"
                . htmlspecialchars($config['excluded_fields']) . "</textarea>";
        }, __('Format table.field, comma separated.', 'sentinel'));

        $end_section();

        $section('ti-file-search', __('Document checks', 'sentinel'));

        $field(__('Flag documents whose file is missing on disk', 'sentinel'), function () use ($yesno, $config) {
            $yesno('scan_missing_files', $config['scan_missing_files']);
        });

        $field(__('Flag files on disk with no matching document', 'sentinel'), function () use ($yesno, $config) {
            $yesno('scan_orphan_files', $config['scan_orphan_files']);
        }, "<span class='text-danger'>"
            . __('Cleanup for this check deletes real files from disk. Review a first report before enabling it.', 'sentinel')
            . "</span>");

        $end_section();

        $section('ti-adjustments', __('General', 'sentinel'));

        $field(__('Rows/files processed per batch', 'sentinel'), function () use ($ro, $config) {
            echo "<input type='number' name='batch_size' class='form-control' style='max-width:150px;' "
                . "min='50' max='5000' step='50' $ro value=\"" . (int) $config['batch_size'] . "\">";
        });

        $field(__('Keep ignored issues for (days)', 'sentinel'), function () use ($ro, $config) {
            echo "<input type='number' name='retention_days' class='form-control' style='max-width:150px;' "
                . "min='0' step='1' $ro value=\"" . (int) $config['retention_days'] . "\">";
        }, __('Used by the "Purge old ignored issues" button on the report page. 0 disables it.', 'sentinel'));

        $end_section();

        if ($can_edit) {
            echo "<div class='d-flex justify-content-end mb-4'>";
            echo "<button type='submit' name='update' class='btn btn-primary'>";
            echo "<i class='ti ti-device-floppy'></i> <span>" . _x('button', 'Save') . "</span>";
            echo "</button>";
            echo "</div>";
        }

        echo "</form>";
    }

    /**
     * Persist submitted config values (called from front/config.php).
     */
    public static function saveConfig(array $input): void
    {
        $defaults = self::getDefaults();
        $values   = [];
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $input)) {
                // checkboxes are simply absent from $_POST when unchecked
                $values[$key] = is_int($default) ? 0 : '';
                continue;
            }
            $values[$key] = $input[$key];
        }
        GlpiConfig::setConfigurationValues(self::CONTEXT, $values);
    }
}
