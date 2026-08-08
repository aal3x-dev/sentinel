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

/**
 * Contract every Sentinel check must implement.
 *
 * A check is a self-contained, read-only analyzer for one specific kind
 * of problem. It never deletes/fixes anything itself - it only reports
 * findings through Issue::upsert(). Actual remediation always happens
 * later, one confirmed Issue at a time, from the report screen.
 */
interface CheckInterface
{
    /**
     * Stable, unique slug for this check (used as Issue.check_key and in
     * config toggle names). Never change once released - it's the join
     * key between old Issue rows and this class.
     */
    public function getKey(): string;

    public function getLabel(): string;

    /**
     * Grouping used in the report UI/filters: 'database', 'filesystem',
     * 'cron', 'config'...
     */
    public function getCategory(): string;

    /**
     * Whether this check should run, given the current plugin config
     * (e.g. its own on/off toggle).
     */
    public function isEnabled(array $config): bool;

    /**
     * Runs the analysis and reports findings via Issue::upsert().
     * Implementations must NOT delete/modify anything outside the
     * glpi_plugin_sentinel_issues table.
     *
     * @param string $scan_start timestamp of the current run, forwarded
     *                           to Issue::upsert() so CheckRunner can
     *                           later tell "still present" from "fixed".
     * @param array  $config     current plugin configuration
     * @param array  &$stats     running totals (new/confirmed/skipped),
     *                           updated in place
     */
    public function run(string $scan_start, array $config, array &$stats): void;
}
