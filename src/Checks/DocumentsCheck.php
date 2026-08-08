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
 * Cross-checks glpi_documents against the actual files on disk, in both
 * directions:
 *
 *  - "missing_file": a glpi_documents row whose `filepath` does not
 *    exist on disk anymore (moved/deleted outside GLPI, failed
 *    migration, backup restore without the files, ...). Cleanup = delete
 *    the DB row (there is nothing left to attach it to).
 *
 *  - "orphan_file": a file physically present under GLPI_DOC_DIR that no
 *    glpi_documents row references. Cleanup = delete the file. OFF by
 *    default (see below) and disabled entirely on setups where the
 *    documents directory cannot be safely isolated.
 *
 * IMPORTANT SAFETY NOTE: on a standard install, GLPI_DOC_DIR is the very
 * same root as GLPI_VAR_DIR (the whole `files/` tree), which also holds
 * `_cache`, `_sessions`, `_log`, `_pictures`, `_plugins`, etc. A naive
 * recursive diff of that whole tree would flag GLPI's own internal
 * files as "orphan documents". To stay safe, the orphan-file walk:
 *   - excludes every path that matches another defined GLPI_*_DIR
 *     constant (so it adapts automatically to whatever this specific
 *     install defines, instead of a hardcoded folder list),
 *   - never follows symlinks,
 *   - is capped per run (config batch_size) so a huge documents tree
 *     doesn't turn one scan into a multi-hour filesystem walk,
 *   - is opt-in (`scan_orphan_files`, default OFF) - the missing-file
 *     direction is safe and ON by default, this one deletes real files
 *     so an admin has to consciously enable it after reviewing a first
 *     report.
 */
class DocumentsCheck implements CheckInterface
{
    public function getKey(): string
    {
        return 'documents';
    }

    public function getLabel(): string
    {
        return __('Documents: file vs database record', 'sentinel');
    }

    public function getCategory(): string
    {
        return 'filesystem';
    }

    public function isEnabled(array $config): bool
    {
        if (!defined('GLPI_DOC_DIR') || !is_dir(GLPI_DOC_DIR)) {
            return false;
        }
        return !empty($config['scan_missing_files']) || !empty($config['scan_orphan_files']);
    }

    public function run(string $scan_start, array $config, array &$stats): void
    {
        if (!empty($config['scan_missing_files'])) {
            $this->scanMissingFiles($scan_start, $config, $stats);
        }
        if (!empty($config['scan_orphan_files'])) {
            $this->scanOrphanFiles($scan_start, $config, $stats);
        }
    }

    /**
     * DB -> disk: documents whose file is gone.
     */
    private function scanMissingFiles(string $scan_start, array $config, array &$stats): void
    {
        global $DB;

        $batch_size = max(50, (int) $config['batch_size']);
        $last_id    = 0;

        do {
            $rows = $DB->request([
                'SELECT' => ['id', 'filepath'],
                'FROM'   => 'glpi_documents',
                'WHERE'  => [
                    'id'       => ['>', $last_id],
                    'filepath' => ['<>', ''],
                ],
                'ORDER'  => 'id ASC',
                'LIMIT'  => $batch_size,
            ]);

            $count = 0;
            foreach ($rows as $row) {
                $count++;
                $last_id = (int) $row['id'];

                $relative = $row['filepath'];
                $full     = GLPI_DOC_DIR . '/' . $relative;

                if (!is_file($full)) {
                    Issue::upsert([
                        'check_key'    => $this->getKey(),
                        'category'     => $this->getCategory(),
                        'source_table' => 'glpi_documents',
                        'source_id'    => $row['id'],
                        'field'        => 'filepath',
                        'path'         => $relative,
                        'reason'       => sprintf(
                            __('File missing on disk: %s', 'sentinel'),
                            $relative
                        ),
                    ], $scan_start, $stats);
                }
            }
        } while ($count === $batch_size);
    }

    /**
     * Disk -> DB: files under GLPI_DOC_DIR that no glpi_documents row
     * references.
     */
    private function scanOrphanFiles(string $scan_start, array $config, array &$stats): void
    {
        global $DB;

        $base = realpath(GLPI_DOC_DIR);
        if ($base === false) {
            return;
        }

        // Build the set of every glpi_documents.filepath once, so the
        // filesystem walk below is a plain in-memory lookup instead of
        // one query per file.
        $known = [];
        $iterator = $DB->request([
            'SELECT' => 'filepath',
            'FROM'   => 'glpi_documents',
            'WHERE'  => ['filepath' => ['<>', '']],
        ]);
        foreach ($iterator as $row) {
            $known[$row['filepath']] = true;
        }

        $excluded_real_paths = $this->getExcludedRealPaths($base);

        $limit    = max(50, (int) $config['batch_size']) * 10; // one walk covers more ground than a DB batch
        $scanned  = 0;

        // A plain foreach+continue does NOT stop RecursiveIteratorIterator
        // from descending into an excluded directory - only a filter on
        // the RecursiveDirectoryIterator itself (via accept()) prevents
        // recursion into it. This is what actually keeps the walk out of
        // _sessions, _cache, _log, etc.
        $filtered = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $current) use ($excluded_real_paths) {
                if ($current->isLink()) {
                    return false;
                }
                $real = $current->getRealPath();
                if ($real === false) {
                    return false;
                }
                return !in_array($real, $excluded_real_paths, true);
            }
        );

        $iterator_fs = new \RecursiveIteratorIterator($filtered, \RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator_fs as $fileinfo) {
            if ($scanned >= $limit) {
                break;
            }

            if (!$fileinfo->isFile() || $fileinfo->isLink()) {
                continue;
            }

            $real = $fileinfo->getRealPath();
            if ($real === false) {
                continue;
            }

            foreach ($excluded_real_paths as $excluded) {
                if (strncmp($real, $excluded . DIRECTORY_SEPARATOR, strlen($excluded) + 1) === 0) {
                    continue 2;
                }
            }

            $scanned++;
            $relative = ltrim(substr($real, strlen($base)), DIRECTORY_SEPARATOR);

            if (!isset($known[$relative])) {
                Issue::upsert([
                    'check_key'    => $this->getKey(),
                    'category'     => $this->getCategory(),
                    'path'         => $relative,
                    'reason'       => __('File exists on disk but no document record references it', 'sentinel'),
                ], $scan_start, $stats);
            }
        }
    }

    /**
     * Every other GLPI_*_DIR constant that resolves inside GLPI_DOC_DIR
     * (which, on a default install, IS the shared `files/` root) must be
     * excluded from the walk - it holds sessions, cache, logs, plugin
     * data, etc, not documents.
     */
    private function getExcludedRealPaths(string $doc_dir_real): array
    {
        $excluded = [];
        foreach (get_defined_constants() as $name => $value) {
            if ($name === 'GLPI_DOC_DIR' || !str_starts_with($name, 'GLPI_') || !str_ends_with($name, '_DIR')) {
                continue;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $real = realpath($value);
            if ($real !== false && $real !== $doc_dir_real) {
                $excluded[] = $real;
            }
        }
        return array_unique($excluded);
    }
}
