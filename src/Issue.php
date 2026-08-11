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

use CommonDBTM;
use CronTask;
use Plugin;
use Session;
use Toolbox;

/**
 * One row of glpi_plugin_sentinel_issues = one problem detected by ANY
 * registered check (see CheckInterface / CheckRunner) - an orphan DB
 * record, an orphan file on disk, a missing file for a DB record, etc.
 *
 * An Issue never disappears by itself: CheckRunner (re)confirms it on
 * every full scan (date_last_seen is bumped) or, if it's not detected
 * anymore (already fixed by someone/something else), it's purged
 * automatically at the end of that scan.
 *
 * Two different kinds of "target" an Issue can point to:
 *  - a DB row:  source_table + source_id are set, path is null.
 *  - a file:    path is set, source_table/source_id are null.
 * applyCleanup() branches on which one is set.
 */
class Issue extends CommonDBTM
{
    public static $rightname = 'plugin_sentinel';

    public const STATUS_NEW     = 'new';
    public const STATUS_IGNORED = 'ignored';

    public static function getTypeName($nb = 0)
    {
        return _n('Issue', 'Issues', $nb, 'sentinel');
    }

    /**
     * Absolute-from-webroot path to the report page. Deliberately NOT
     * built via self::getSearchURL() - GLPI's auto-derivation of that URL
     * from a plugin-namespaced class has been unreliable in this GLPI
     * version (it dropped the /plugins/sentinel/ prefix entirely in
     * testing, pointing at a nonexistent core front/issue.php). Hardcoded
     * paths matching our actual front/ files are the only thing that's
     * proven reliable throughout this plugin.
     */
    public static function getReportURL(): string
    {
        return Plugin::getWebDir('sentinel') . '/front/issue.php';
    }

    /**
     * Overridden for the same reason as getReportURL(): GLPI's default
     * getFormURL() derives the path from the class's namespace, which
     * hasn't been reliable for this plugin. Without this override, every
     * row link in the Search results table (built by GLPI calling
     * getFormURL()/getFormURLWithID() automatically) was silently
     * broken/unclickable.
     *
     * BUG FIX: originally ignored $full and always returned the absolute
     * form - if any GLPI-internal caller ever passes $full=false expecting
     * a root_doc-relative path (the same convention getMenuContent() had
     * to follow to avoid a doubled /glpi/glpi/ prefix), it would have hit
     * the exact same bug through a different, untested code path.
     */
    public static function getFormURL($full = true): string
    {
        if (!$full) {
            return 'plugins/sentinel/front/issue.form.php';
        }
        return Plugin::getWebDir('sentinel') . '/front/issue.form.php';
    }

    public static function getMenuName($nb = 0)
    {
        return self::getTypeName($nb);
    }

    public static function getMenuContent()
    {
        // Menu 'page' entries are relative to GLPI's own root_doc - GLPI
        // prefixes them with it when rendering the link. getReportURL()
        // is already absolute-from-server-root (Plugin::getWebDir()
        // includes the /glpi part), so using it here doubled the prefix
        // (observed: /glpi/glpi/plugins/sentinel/...). Redirects
        // (Html::redirect) need the absolute form and are unaffected -
        // this relative form is only for the menu.
        $search = 'plugins/sentinel/front/issue.php';

        return [
            'title' => __('Health checks', 'sentinel'),
            'page'  => $search,
            'icon'  => 'ti ti-shield-check',
            'options' => [
                'sentinel' => [
                    'title' => self::getTypeName(2),
                    'page'  => $search,
                    'links' => [
                        'search' => $search,
                    ],
                ],
            ],
        ];
    }

    /**
     * Records a detection from a Check: creates the Issue on first
     * detection, or just bumps date_last_seen (and stats) if it was
     * already known - status (new/ignored) is left untouched so an
     * admin's "ignore" choice survives repeated scans.
     *
     * @param array  $data       check_key, category, source_table,
     *                           source_id, path, field, ref_itemtype,
     *                           ref_table, ref_id, reason
     * @param string $scan_start timestamp of the current scan run
     * @param array  &$stats     running totals, updated in place
     */
    public static function upsert(array $data, string $scan_start, array &$stats): void
    {
        global $DB;

        $data += [
            'source_table' => null,
            'source_id'    => null,
            'path'         => null,
            'field'        => null,
            'ref_itemtype' => null,
            'ref_table'    => null,
            'ref_id'       => null,
        ];

        $criteria = ['check_key' => $data['check_key']];
        // Identity of an Issue: if it points at a DB row (source_table is
        // set), that row's identity wins even when we also stored an
        // informational `path` (e.g. "file missing for this document").
        // Only a pure filesystem issue (no DB row at all) is identified
        // by its path.
        if ($data['source_table'] !== null) {
            $criteria['source_table'] = $data['source_table'];
            $criteria['source_id']    = $data['source_id'];
            $criteria['field']        = $data['field'];
        } else {
            $criteria['path'] = $data['path'];
        }

        $existing = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => $criteria,
            'LIMIT' => 1,
        ]);

        if (count($existing) > 0) {
            $row = $existing->current();
            $DB->update(self::getTable(), [
                'date_last_seen' => $scan_start,
                'category'       => $data['category'],
                'ref_id'         => $data['ref_id'],
                'reason'         => $data['reason'],
            ], ['id' => $row['id']]);
            $stats['confirmed']++;
            return;
        }

        $item = new self();
        $item->add([
            'check_key'      => $data['check_key'],
            'category'       => $data['category'],
            'source_table'   => $data['source_table'],
            'source_id'      => $data['source_id'],
            'path'           => $data['path'],
            'field'          => $data['field'],
            'ref_itemtype'   => $data['ref_itemtype'],
            'ref_table'      => $data['ref_table'],
            'ref_id'         => $data['ref_id'],
            'reason'         => $data['reason'],
            'status'         => self::STATUS_NEW,
            'date_discover'  => $scan_start,
            'date_last_seen' => $scan_start,
        ]);
        $stats['new']++;
    }

    /**
     * Maintenance action (manual, never automatic from cron): deletes the
     * bookkeeping row for Issues the admin already marked "ignored", once
     * they've stayed ignored for longer than Config's retention_days.
     * Never touches the underlying data/file the Issue pointed to - only
     * cleans up this plugin's own report table. Age is measured from
     * date_discover, not date_last_seen: an ignored Issue still gets
     * reconfirmed (and date_last_seen bumped) on every scan, so
     * date_last_seen would never actually "age".
     */
    public static function purgeOldIgnored(): int
    {
        global $DB;

        $days = (int) Config::getConfig()['retention_days'];
        if ($days <= 0) {
            return 0;
        }

        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $DB->delete(self::getTable(), [
            'status'        => self::STATUS_IGNORED,
            'date_discover' => ['<', $threshold],
        ]);

        return $DB->affectedRows();
    }

    /**
     * Human label for a check_key, sourced from the Check class itself
     * (CheckInterface::getLabel()) so there's one place that owns the
     * wording, not a copy duplicated here that could drift out of sync.
     */
    public static function getCheckLabel(string $check_key): string
    {
        foreach (CheckRunner::getChecks() as $check) {
            if ($check->getKey() === $check_key) {
                return $check->getLabel();
            }
        }
        return $check_key;
    }

    public static function getCategoryLabel(string $category): string
    {
        return match ($category) {
            'database'   => __('Database', 'sentinel'),
            'filesystem' => __('Filesystem', 'sentinel'),
            default      => $category,
        };
    }

    /**
     * Human name for a GLPI table: resolves it back to its itemtype and
     * asks that class for its own display name (singular), instead of
     * showing raw table names like "glpi_computers" to someone who has
     * no reason to know GLPI's internal schema.
     */
    private static function humanTableName(?string $table): string
    {
        if (empty($table)) {
            return __('unknown', 'sentinel');
        }

        $itemtype = getItemTypeForTable($table);
        if ($itemtype === 'UNKNOWN' || !is_a($itemtype, CommonDBTM::class, true)) {
            // Not a core/plugin itemtype table GLPI recognizes by name -
            // still better than the raw glpi_-prefixed name.
            return str_replace('_', ' ', preg_replace('/^glpi_/', '', $table));
        }

        return $itemtype::getTypeName(1);
    }

    /**
     * One-sentence, plain-language description of this Issue - no table
     * names, no field internals - for people who don't know (or care)
     * how GLPI's database is put together. Used both on the detail page
     * and as the report list's "Description" column.
     */
    public function getHumanSummary(): string
    {
        $check_key = $this->fields['check_key'] ?? '';
        $field     = $this->fields['field'] ?? '';

        if ($check_key === 'orphan_records' && $field === 'itemtype/items_id') {
            $source_label = self::humanTableName($this->fields['source_table'] ?? null);

            if (str_contains((string) ($this->fields['reason'] ?? ''), 'class no longer exists')) {
                return sprintf(
                    __('A "%1$s" record refers to a kind of item ("%2$s") that no longer exists in this system - most likely a plugin that was uninstalled.', 'sentinel'),
                    $source_label,
                    $this->fields['ref_itemtype']
                );
            }

            $ref_label = is_string($this->fields['ref_itemtype'] ?? null) && is_a($this->fields['ref_itemtype'], CommonDBTM::class, true)
                ? $this->fields['ref_itemtype']::getTypeName(1)
                : $this->fields['ref_itemtype'];

            return sprintf(
                __('A "%1$s" record refers to a %2$s that has been deleted.', 'sentinel'),
                $source_label,
                $ref_label
            );
        }

        if ($check_key === 'orphan_records') {
            // Classic dangling foreign key - the row itself still exists,
            // only one field on it is stale, so try to show its real name.
            $item_label   = $this->describeExistingRow();
            $target_label = self::humanTableName($this->fields['ref_table'] ?? null);

            return sprintf(
                __('%1$s references a %2$s that has been deleted.', 'sentinel'),
                $item_label,
                $target_label
            );
        }

        if ($check_key === 'documents' && $field === 'filepath') {
            return sprintf(
                __('A document record exists, but its file is missing from the server (expected at "%s").', 'sentinel'),
                $this->fields['path']
            );
        }

        if ($check_key === 'documents') {
            return sprintf(
                __('A file was found on the server ("%s") that no document record refers to.', 'sentinel'),
                $this->fields['path']
            );
        }

        return $this->fields['reason'] ?? '';
    }

    /**
     * For a classic-FK issue the source row still exists (only the
     * dangling field is the problem) - fetch its own name so the
     * summary can say "Computer 'PC Descartable'" instead of a raw
     * table+id pair.
     */
    private function describeExistingRow(): string
    {
        $table = $this->fields['source_table'] ?? '';
        $id    = (int) ($this->fields['source_id'] ?? 0);
        $label = self::humanTableName($table);

        $itemtype = getItemTypeForTable($table);
        if ($itemtype !== 'UNKNOWN' && is_a($itemtype, CommonDBTM::class, true)) {
            $item = new $itemtype();
            if ($item->getFromDB($id)) {
                $name = method_exists($item, 'getName') ? $item->getName() : ($item->fields['name'] ?? null);
                if (!empty($name)) {
                    return sprintf('%1$s "%2$s"', $label, $name);
                }
            }
        }

        return sprintf('%1$s #%2$d', $label, $id);
    }

    public function rawSearchOptions()
    {
        $options   = [];
        $options[] = ['id' => 'common', 'name' => __('Characteristics')];

        $options[] = [
            'id' => 1, 'table' => self::getTable(), 'field' => 'check_key',
            'name' => __('Check', 'sentinel'), 'datatype' => 'itemlink',
        ];
        $options[] = [
            'id'    => 14,
            'table' => self::getTable(),
            'field' => 'id', // no real "description" column - reuses 'id' as the anchor; getSpecificValueToDisplay() computes the actual text below
            'name'  => __('Description', 'sentinel'),
            'datatype'        => 'specific',
            'massiveaction'   => false,
            'nosort'          => true,
            'additionalfields' => [
                'check_key', 'source_table', 'source_id', 'path', 'field',
                'ref_itemtype', 'ref_table', 'reason',
            ],
        ];
        $options[] = [
            'id' => 2, 'table' => self::getTable(), 'field' => 'category',
            'name' => __('Category', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 3, 'table' => self::getTable(), 'field' => 'source_table',
            'name' => __('Table', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 4, 'table' => self::getTable(), 'field' => 'source_id',
            'name' => __('Row ID', 'sentinel'), 'datatype' => 'integer',
        ];
        $options[] = [
            'id' => 5, 'table' => self::getTable(), 'field' => 'path',
            'name' => __('File path', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 6, 'table' => self::getTable(), 'field' => 'field',
            'name' => __('Field', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 7, 'table' => self::getTable(), 'field' => 'ref_itemtype',
            'name' => __('Referenced itemtype', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 8, 'table' => self::getTable(), 'field' => 'ref_table',
            'name' => __('Expected target table', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 9, 'table' => self::getTable(), 'field' => 'ref_id',
            'name' => __('Missing ID', 'sentinel'), 'datatype' => 'integer',
        ];
        $options[] = [
            'id' => 10, 'table' => self::getTable(), 'field' => 'reason',
            'name' => __('Reason', 'sentinel'), 'datatype' => 'string',
        ];
        $options[] = [
            'id' => 11, 'table' => self::getTable(), 'field' => 'status',
            'name' => __('Status', 'sentinel'), 'datatype' => 'specific', 'searchtype' => ['equals'],
        ];
        $options[] = [
            'id' => 12, 'table' => self::getTable(), 'field' => 'date_discover',
            'name' => __('First detected', 'sentinel'), 'datatype' => 'datetime',
        ];
        $options[] = [
            'id' => 13, 'table' => self::getTable(), 'field' => 'date_last_seen',
            'name' => __('Last confirmed', 'sentinel'), 'datatype' => 'datetime',
        ];

        return $options;
    }

    /**
     * Renders the "Description" search column (see rawSearchOptions()).
     * $values contains the row's raw columns (thanks to
     * 'additionalfields') even though the option's own 'field' is just
     * 'id' - reused here to build a throwaway Issue instance so
     * getHumanSummary() can work off real ->fields data.
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        // BUG FIX: this used to check array_key_exists('check_key', $values)
        // as a proxy for "is this our synthetic Description column" - it
        // happened to work only because no OTHER 'specific' option (like
        // 'status') requests check_key via additionalfields, which is
        // fragile (a future option could break it silently). $field is the
        // option's own 'field' value ('id' for ours, 'status' for that
        // one) - check that directly instead.
        if ($field === 'id' && is_array($values) && array_key_exists('check_key', $values)) {
            $item = new self();
            $item->fields = $values;
            return htmlspecialchars($item->getHumanSummary());
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Fixes the issue: deletes the orphan DB row (if source_table/id are
     * set) or the orphan file on disk (if path is set), then removes the
     * bookkeeping Issue. This is the one and only place that deletes
     * arbitrary GLPI data/files, and it requires the PURGE bit of the
     * plugin_sentinel right.
     */
    public function applyCleanup(): bool
    {
        global $DB;

        if (!Session::haveRight(self::$rightname, PURGE)) {
            Session::addMessageAfterRedirect(
                __('You are not allowed to delete this data.', 'sentinel'),
                false,
                ERROR
            );
            return false;
        }

        if (!empty($this->fields['source_table'])) {
            return $this->isClassicForeignKeyIssue()
                ? $this->applyFieldReset()
                : $this->applyRowCleanup();
        }

        if (!empty($this->fields['path'])) {
            return $this->applyFileCleanup();
        }

        // Neither a row nor a path is set - nothing to clean up besides
        // the bookkeeping row itself (should not normally happen).
        return $this->delete(['id' => $this->getID()], true);
    }

    /**
     * A classic-FK Issue (e.g. Computer.locations_id pointing at a
     * deleted location) is one column gone stale on an otherwise valid
     * row - the row itself is not the problem. Deleting the whole row
     * (a Computer!) because one reference field is dangling would be
     * far more destructive than the actual problem calls for. Only
     * OrphanRecordsCheck's polymorphic detections (field is literally
     * 'itemtype/items_id', the row's entire purpose) and DocumentsCheck
     * rows still get the whole-row delete via applyRowCleanup().
     */
    private function isClassicForeignKeyIssue(): bool
    {
        return $this->fields['check_key'] === 'orphan_records'
            && $this->fields['field'] !== 'itemtype/items_id';
    }

    /**
     * Resets just the dangling FK column back to 0 (GLPI's own
     * convention for "no relation"), leaving the rest of the row intact.
     */
    private function applyFieldReset(): bool
    {
        global $DB;

        $table = $this->fields['source_table'];
        $id    = (int) $this->fields['source_id'];
        $field = $this->fields['field'];

        if (!$this->isKnownGlpiTable($table) || !preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
            Toolbox::logError("Sentinel: refused field reset on suspicious table/field '$table'.'$field'");
            return false;
        }

        if (!$DB->tableExists($table)) {
            return $this->delete(['id' => $this->getID()], true);
        }

        $DB->update($table, [$field => 0], ['id' => $id]);

        return $this->delete(['id' => $this->getID()], true);
    }

    private function applyRowCleanup(): bool
    {
        global $DB;

        $table = $this->fields['source_table'];
        $id    = (int) $this->fields['source_id'];

        if (!$this->isKnownGlpiTable($table)) {
            // Refuse to touch anything that does not look like a GLPI table,
            // e.g. if the stored value was somehow corrupted.
            Toolbox::logError("Sentinel: refused cleanup on suspicious table name '$table'");
            return false;
        }

        if (!$DB->tableExists($table)) {
            // Table is already gone (plugin fully uninstalled since) - just drop bookkeeping.
            return $this->delete(['id' => $this->getID()], true);
        }

        $DB->delete($table, ['id' => $id]);

        return $this->delete(['id' => $this->getID()], true);
    }

    /**
     * Deletes an orphan FILE from disk. Confined to GLPI_DOC_DIR and its
     * realpath, so a corrupted/tampered `path` value can never be used to
     * delete something outside the documents storage tree.
     */
    private function applyFileCleanup(): bool
    {
        if (!defined('GLPI_DOC_DIR')) {
            Toolbox::logError('Sentinel: GLPI_DOC_DIR is not defined, refusing file cleanup.');
            return false;
        }

        $base = realpath(GLPI_DOC_DIR);
        $full = realpath(GLPI_DOC_DIR . '/' . $this->fields['path']);

        if (
            $base === false
            || $full === false
            || strncmp($full, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
        ) {
            Toolbox::logError("Sentinel: refused to delete file outside GLPI_DOC_DIR: '{$this->fields['path']}'");
            return false;
        }

        if (is_file($full)) {
            @unlink($full);
        }

        return $this->delete(['id' => $this->getID()], true);
    }

    private function isKnownGlpiTable(string $table): bool
    {
        return (bool) preg_match('/^glpi_[a-z0-9_]+$/', $table)
            && $table !== self::getTable();
    }

    /**
     * Writes to the SAME glpi_crontasklogs rows GLPI already shows on the
     * "scan" automatic action's own Logs/Historical tabs - so a manual
     * "Run all health checks now" click shows up there exactly like a
     * cron-triggered run does, instead of needing a separate log. Called
     * from CheckRunner::run() itself so every trigger path (manual
     * button, MODE_INTERNAL) is covered in one place.
     */
    public static function logScanResult(array $stats): void
    {
        $task = new CronTask();
        if (!$task->getFromDBByCrit(['itemtype' => self::class, 'name' => 'scan'])) {
            return; // task not registered yet (e.g. mid-install) - nothing to log to
        }

        $task->log(sprintf(
            'Sentinel: %d new, %d confirmed, %d resolved (%d checks run).',
            $stats['new'],
            $stats['confirmed'],
            $stats['resolved'],
            $stats['checks_run']
        ));
        $task->addVolume($stats['new'] + $stats['confirmed']);
    }

    /**
     * Declares the automatic action to GLPI.
     */
    public static function cronInfo($name)
    {
        switch ($name) {
            case 'scan':
                return [
                    'description' => __('Run all enabled health checks and update the report', 'sentinel'),
                ];
        }
        return [];
    }

    /**
     * Automatic action callback. Runs all enabled checks only - never
     * deletes/fixes anything, regardless of the auto_clean setting (kept
     * for a future, explicitly-opt-in iteration). Logging to $task
     * happens inside CheckRunner::run() -> logScanResult(), same as a
     * manually triggered scan, so there's a single source of truth for
     * what happens after any scan completes.
     */
    public static function cronScan(CronTask $task): int
    {
        CheckRunner::run();

        return 1;
    }
}
