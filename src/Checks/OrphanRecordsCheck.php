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

namespace GlpiPlugin\Sentinel\Checks;

use GlpiPlugin\Sentinel\CheckInterface;
use GlpiPlugin\Sentinel\Issue;

/**
 * Database-wide orphan record detector.
 *
 * Never deletes anything from GLPI's own tables: it only writes
 * bookkeeping rows into glpi_plugin_sentinel_issues (via Issue::upsert()).
 * Actual deletion of an orphan row happens later, one confirmed Issue at
 * a time, through Issue::applyCleanup() - triggered explicitly from the
 * report screen.
 *
 * Two independent detectors are run against every scannable table:
 *
 *  - Polymorphic relations: any table exposing both an `itemtype` and an
 *    `items_id` column (the pattern used all over GLPI core - documents,
 *    notepads, infocoms, contracts, alerts, etc). A row is orphan when
 *    the stored itemtype class no longer exists (typically: a plugin was
 *    uninstalled) OR the class exists but items_id does not exist in
 *    that class's table anymore.
 *
 *  - Classic foreign keys: any `xxx_id` (optionally `xxx_id_suffix`)
 *    column. The target table is guessed from GLPI's own naming
 *    convention (`entities_id` -> `glpi_entities`). When the guess
 *    cannot be resolved to an existing table, the field is skipped and
 *    reported in stats rather than guessed at random - false positives
 *    here would be worse than missed detections.
 */
class OrphanRecordsCheck implements CheckInterface
{
    /** @var array<string,bool> cache of class_exists() lookups for this run */
    private array $classExistsCache = [];

    /** @var array<string,bool> cache of $DB->tableExists() lookups for this run */
    private array $tableExistsCache = [];

    public function getKey(): string
    {
        return 'orphan_records';
    }

    public function getLabel(): string
    {
        return __('Orphan database records', 'sentinel');
    }

    public function getCategory(): string
    {
        return 'database';
    }

    public function isEnabled(array $config): bool
    {
        return !empty($config['scan_polymorphic']) || !empty($config['scan_foreignkeys']);
    }

    public function run(string $scan_start, array $config, array &$stats): void
    {
        $this->classExistsCache = [];
        $this->tableExistsCache = [];

        $schema = $this->getSchemaName();
        $tables = $this->listScannableTables($schema, $config);

        foreach ($tables as $table) {
            if (!empty($config['scan_polymorphic'])) {
                $this->scanPolymorphicForTable($table, $schema, $config, $scan_start, $stats);
            }
            if (!empty($config['scan_foreignkeys'])) {
                $this->scanForeignKeysForTable($table, $schema, $config, $scan_start, $stats);
            }
        }
    }

    private function getSchemaName(): string
    {
        global $DB;
        $res = $DB->query('SELECT DATABASE() AS dbname');
        $row = $DB->fetchAssoc($res);
        return $row['dbname'];
    }

    /**
     * @return string[] list of glpi_* tables to scan, excluding config-defined
     *                   exclusions and this plugin's own tables.
     */
    private function listScannableTables(string $schema, array $config): array
    {
        global $DB;

        $excluded = array_filter(array_map('trim', explode(',', $config['excluded_tables'])));

        $iterator = $DB->request([
            'SELECT' => 'TABLE_NAME',
            'FROM'   => 'information_schema.TABLES',
            'WHERE'  => [
                'TABLE_SCHEMA' => $schema,
                'TABLE_NAME'   => ['LIKE', 'glpi\_%'],
                'TABLE_TYPE'   => 'BASE TABLE',
            ],
        ]);

        $tables = [];
        foreach ($iterator as $row) {
            $name = $row['TABLE_NAME'];
            if (!in_array($name, $excluded, true)) {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    /**
     * @return string[] column names of a table
     */
    private function getColumns(string $table, string $schema): array
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT' => 'COLUMN_NAME',
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => [
                'TABLE_SCHEMA' => $schema,
                'TABLE_NAME'   => $table,
            ],
        ]);

        $columns = [];
        foreach ($iterator as $row) {
            $columns[] = $row['COLUMN_NAME'];
        }

        return $columns;
    }

    private function scanPolymorphicForTable(
        string $table,
        string $schema,
        array $config,
        string $scan_start,
        array &$stats
    ): void {
        global $DB;

        $columns = $this->getColumns($table, $schema);
        if (!in_array('itemtype', $columns, true) || !in_array('items_id', $columns, true)) {
            return;
        }
        if (!in_array('id', $columns, true)) {
            // No primary key to reference back to - skip, too risky to track.
            return;
        }

        $batch_size = max(50, (int) $config['batch_size']);
        $last_id    = 0;

        do {
            $rows = $DB->request([
                'SELECT' => ['id', 'itemtype', 'items_id'],
                'FROM'   => $table,
                'WHERE'  => [
                    'id'       => ['>', $last_id],
                    'itemtype' => ['<>', ''],
                ],
                'ORDER'  => 'id ASC',
                'LIMIT'  => $batch_size,
            ]);

            $count = 0;
            foreach ($rows as $row) {
                $count++;
                $last_id = (int) $row['id'];

                $itemtype = $row['itemtype'];
                $items_id = (int) $row['items_id'];

                if ($itemtype === '' || $itemtype === null || $items_id <= 0) {
                    continue;
                }

                if (!$this->classExists($itemtype)) {
                    Issue::upsert([
                        'check_key'    => $this->getKey(),
                        'category'     => $this->getCategory(),
                        'source_table' => $table,
                        'source_id'    => $row['id'],
                        'field'        => 'itemtype/items_id',
                        'ref_itemtype' => $itemtype,
                        'ref_table'    => null,
                        'ref_id'       => $items_id,
                        'reason'       => __('Referenced class no longer exists (plugin removed?)', 'sentinel'),
                    ], $scan_start, $stats);
                    continue;
                }

                $target_table = $this->classTable($itemtype);
                if ($target_table === null || !$this->tableExists($target_table)) {
                    // Class exists but exposes no usable table: nothing to check.
                    continue;
                }

                if (!$this->rowExists($target_table, $items_id)) {
                    Issue::upsert([
                        'check_key'    => $this->getKey(),
                        'category'     => $this->getCategory(),
                        'source_table' => $table,
                        'source_id'    => $row['id'],
                        'field'        => 'itemtype/items_id',
                        'ref_itemtype' => $itemtype,
                        'ref_table'    => $target_table,
                        'ref_id'       => $items_id,
                        'reason'       => __('Referenced item no longer exists', 'sentinel'),
                    ], $scan_start, $stats);
                }
            }
        } while ($count === $batch_size);
    }

    private function scanForeignKeysForTable(
        string $table,
        string $schema,
        array $config,
        string $scan_start,
        array &$stats
    ): void {
        global $DB;

        $columns = $this->getColumns($table, $schema);
        if (!in_array('id', $columns, true)) {
            return;
        }

        $excluded_fields = array_filter(array_map('trim', explode(',', $config['excluded_fields'])));

        $fk_columns = [];
        foreach ($columns as $col) {
            if ($col === 'id' || $col === 'items_id') {
                continue;
            }
            if (!preg_match('/_id(_[a-z0-9_]+)?$/', $col)) {
                continue;
            }
            if (in_array("$table.$col", $excluded_fields, true)) {
                continue;
            }
            $target = $this->guessTargetTable($col);
            if ($target === null) {
                $stats['skipped_fields']++;
                continue;
            }
            $fk_columns[$col] = $target;
        }

        if (empty($fk_columns)) {
            return;
        }

        $batch_size = max(50, (int) $config['batch_size']);
        $last_id    = 0;
        $select     = array_merge(['id'], array_keys($fk_columns));

        do {
            $rows = $DB->request([
                'SELECT' => $select,
                'FROM'   => $table,
                'WHERE'  => ['id' => ['>', $last_id]],
                'ORDER'  => 'id ASC',
                'LIMIT'  => $batch_size,
            ]);

            $count = 0;
            foreach ($rows as $row) {
                $count++;
                $last_id = (int) $row['id'];

                foreach ($fk_columns as $col => $target_table) {
                    $value = (int) $row[$col];
                    if ($value <= 0) {
                        continue; // 0/NULL conventionally means "no relation"
                    }
                    if ($table === $target_table && $value === (int) $row['id']) {
                        continue; // self-reference on the same row, not an orphan
                    }
                    if (!$this->rowExists($target_table, $value)) {
                        Issue::upsert([
                            'check_key'    => $this->getKey(),
                            'category'     => $this->getCategory(),
                            'source_table' => $table,
                            'source_id'    => $row['id'],
                            'field'        => $col,
                            'ref_itemtype' => null,
                            'ref_table'    => $target_table,
                            'ref_id'       => $value,
                            'reason'       => __('Referenced row no longer exists', 'sentinel'),
                        ], $scan_start, $stats);
                    }
                }
            }
        } while ($count === $batch_size);
    }

    /**
     * Applies GLPI's own naming convention (docs: "Database" page):
     * foreign key `xxx_id` (optionally suffixed, e.g. `users_id_tech`)
     * refers to table `glpi_xxx` where `xxx` is the already-pluralised
     * base name. Returns null when unresolved (table does not exist),
     * so the caller can skip rather than risk a wrong guess.
     */
    private function guessTargetTable(string $column): ?string
    {
        if (!preg_match('/^(.+?)_id(?:_[a-z0-9_]+)?$/', $column, $m)) {
            return null;
        }
        $base = $m[1];
        $candidate = 'glpi_' . $base;

        if ($this->tableExists($candidate)) {
            return $candidate;
        }

        return null;
    }

    private function classExists(string $itemtype): bool
    {
        if (!isset($this->classExistsCache[$itemtype])) {
            $this->classExistsCache[$itemtype] = class_exists($itemtype);
        }
        return $this->classExistsCache[$itemtype];
    }

    private function classTable(string $itemtype): ?string
    {
        if (!$this->classExists($itemtype)) {
            return null;
        }
        if (!method_exists($itemtype, 'getTable')) {
            return null;
        }
        try {
            return $itemtype::getTable();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        global $DB;
        if (!isset($this->tableExistsCache[$table])) {
            $this->tableExistsCache[$table] = $DB->tableExists($table);
        }
        return $this->tableExistsCache[$table];
    }

    private function rowExists(string $table, int $id): bool
    {
        global $DB;
        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => $table,
            'WHERE'  => ['id' => $id],
            'LIMIT'  => 1,
        ]);
        return count($iterator) > 0;
    }
}
