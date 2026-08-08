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

use GlpiPlugin\Sentinel\Checks\DocumentsCheck;
use GlpiPlugin\Sentinel\Checks\OrphanRecordsCheck;

/**
 * Orchestrates every registered CheckInterface implementation.
 *
 * Adding a new check = write the class, add it to REGISTRY. Nothing
 * else in the plugin needs to change (report, cron, cleanup all work
 * generically off Issue rows).
 */
class CheckRunner
{
    private const REGISTRY = [
        OrphanRecordsCheck::class,
        DocumentsCheck::class,
    ];

    public static function run(): array
    {
        global $DB;

        $config     = Config::getConfig();
        $scan_start = date('Y-m-d H:i:s');
        $stats = [
            'checks_run'     => 0,
            'new'            => 0,
            'confirmed'      => 0,
            'resolved'       => 0,
            'skipped_fields' => 0,
        ];

        foreach (self::REGISTRY as $class) {
            /** @var CheckInterface $check */
            $check = new $class();

            if (!$check->isEnabled($config)) {
                continue;
            }

            $stats['checks_run']++;
            $check->run($scan_start, $config, $stats);

            // Anything under this check's key that was NOT re-confirmed
            // during this run is fixed (row/file gone, FK corrected...):
            // drop it. Scoped to this check_key so a disabled check never
            // wipes out issues it didn't actually re-verify.
            $DB->delete(
                Issue::getTable(),
                [
                    'check_key'      => $check->getKey(),
                    'date_last_seen' => ['<', $scan_start],
                ]
            );
            $stats['resolved'] += $DB->affectedRows();
        }

        Config::recordScanResult($stats);
        Issue::logScanResult($stats);

        return $stats;
    }

    /**
     * @return CheckInterface[]
     */
    public static function getChecks(): array
    {
        return array_map(static fn (string $class) => new $class(), self::REGISTRY);
    }
}
