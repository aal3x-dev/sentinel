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

use CronTask;
use DBConnection;
use GlpiPlugin\Sentinel\Issue;
use GlpiPlugin\Sentinel\Profile as SentinelProfile;
use Migration;
use ProfileRight;

/**
 * Plugin install process
 */
function plugin_sentinel_install(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $migration = new Migration(PLUGIN_SENTINEL_VERSION);

    $table = Issue::getTable();
    if (!$DB->tableExists($table)) {
        // Note: no UNIQUE KEY across (source_table, source_id, field) here on
        // purpose. VARCHAR(100)+VARCHAR(100) under utf8mb4 (4 bytes/char) can
        // exceed InnoDB's index length limit depending on ROW_FORMAT, which
        // made CREATE TABLE fail silently in an earlier version while
        // install() still reported success. Issue::upsert() already does its
        // own SELECT-then-insert/update, so a DB-level unique constraint is
        // not required; plain (non-unique) indexes with column prefixes are
        // enough for lookup performance and stay well under any length limit.
        $query = "CREATE TABLE `$table` (
                    `id`             int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                    `check_key`      varchar(50) NOT NULL COMMENT 'which Check reported this, e.g. orphan_records, documents',
                    `category`       varchar(30) DEFAULT NULL COMMENT 'database, filesystem, ...',
                    `source_table`   varchar(100) DEFAULT NULL,
                    `source_id`      int {$default_key_sign} DEFAULT NULL,
                    `path`           varchar(500) DEFAULT NULL COMMENT 'relative to GLPI_DOC_DIR, for filesystem issues',
                    `field`          varchar(100) DEFAULT NULL,
                    `ref_itemtype`   varchar(100) DEFAULT NULL,
                    `ref_table`      varchar(100) DEFAULT NULL,
                    `ref_id`         int {$default_key_sign} DEFAULT NULL,
                    `reason`         varchar(255) DEFAULT NULL,
                    `status`         varchar(20) NOT NULL DEFAULT 'new',
                    `date_discover`  timestamp NULL DEFAULT NULL,
                    `date_last_seen` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `check_key` (`check_key`),
                    KEY `lookup` (`source_table`(60),`source_id`,`field`(60)),
                    KEY `path` (`path`(191)),
                    KEY `status` (`status`)
                  ) ENGINE=InnoDB
                  DEFAULT CHARSET={$default_charset}
                  COLLATE={$default_collation}";
        $result = $DB->doQuery($query);
        if (!$result) {
            Toolbox::logError('Sentinel install: failed to create table ' . $table . ' - ' . $DB->error());
            Session::addMessageAfterRedirect(
                __('Sentinel: failed to create its database table, check php-errors.log.', 'sentinel'),
                false,
                ERROR
            );
            return false;
        }
    }

    $migration->executeMigration();

    // Register the automatic action.
    // MODE_INTERNAL: triggered opportunistically by ordinary GLPI page
    // views (GLPI's own "internal cron" mechanism) - no system-level cron
    // job or CLI access required, by design: this plugin has to work
    // entirely from inside GLPI. The configured frequency (once a day)
    // still governs how often it actually runs; MODE_INTERNAL only
    // changes what triggers the check for "has a day passed yet", not
    // the frequency itself. Trade-off: whichever request happens to
    // trigger it will run the scan inline and feel a bit slower that day.
    CronTask::register(
        Issue::class,
        'scan',
        DAY_TIMESTAMP,
        [
            'comment' => __('Runs every enabled health check and updates the report. Never deletes/fixes data by itself.', 'sentinel'),
            'mode'    => CronTask::MODE_INTERNAL,
        ]
    );

    SentinelProfile::ensureRightsRegistered();
    // Rights are cached in session at login time; refresh them now so
    // the new permission is usable immediately, without a re-login.
    Session::reloadCurrentProfile();

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_sentinel_uninstall(): bool
{
    global $DB;

    $tables = [
        Issue::getTable(),
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    foreach (SentinelProfile::getAllRights() as $right) {
        ProfileRight::deleteProfileRights([$right['field']]);
    }

    // CronTask entries and plugin config (glpi_configs) are cleaned up
    // automatically by GLPI core on plugin uninstall.

    return true;
}
