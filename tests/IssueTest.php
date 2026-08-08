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
 * @link      https://github.com/pluginsGLPI/cleanorphans
 * -------------------------------------------------------------------------
 */

namespace GlpiPlugin\Sentinel\Tests;

use DbTestCase;
use GlpiPlugin\Sentinel\Issue;

/**
 * Covers Issue::upsert() - the one primitive every Check (present and
 * future) relies on to report what it found. If this dedup/identity
 * logic is wrong, every check built on top of it silently misbehaves
 * (duplicate rows, or a fixed issue never getting reconfirmed), so it's
 * the highest-value thing to pin down first.
 */
class IssueTest extends DbTestCase
{
    /** A check_key that will never collide with a real check. */
    private const TEST_KEY = 'phpunit_issue_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->purgeTestIssues();
    }

    protected function tearDown(): void
    {
        $this->purgeTestIssues();
        parent::tearDown();
    }

    private function purgeTestIssues(): void
    {
        global $DB;
        $DB->delete(Issue::getTable(), ['check_key' => self::TEST_KEY]);
    }

    private function sampleData(int $ref_id = 999999): array
    {
        return [
            'check_key'    => self::TEST_KEY,
            'category'     => 'database',
            'source_table' => 'glpi_computers',
            'source_id'    => 123456,
            'field'        => 'entities_id',
            'ref_itemtype' => null,
            'ref_table'    => 'glpi_entities',
            'ref_id'       => $ref_id,
            'reason'       => 'unit test fixture',
        ];
    }

    public function testFirstDetectionCreatesOneIssue(): void
    {
        global $DB;

        $stats = ['new' => 0, 'confirmed' => 0];
        Issue::upsert($this->sampleData(), date('Y-m-d H:i:s'), $stats);

        $this->assertSame(1, $stats['new']);
        $this->assertSame(0, $stats['confirmed']);

        $rows = iterator_to_array($DB->request([
            'FROM'  => Issue::getTable(),
            'WHERE' => ['check_key' => self::TEST_KEY],
        ]));
        $this->assertCount(1, $rows);
        $this->assertSame('new', reset($rows)['status']);
    }

    public function testSameIdentityOnSecondScanConfirmsInsteadOfDuplicating(): void
    {
        global $DB;

        $stats = ['new' => 0, 'confirmed' => 0];

        Issue::upsert($this->sampleData(), date('Y-m-d H:i:s'), $stats);
        $first_id = (int) iterator_to_array($DB->request([
            'FROM'  => Issue::getTable(),
            'WHERE' => ['check_key' => self::TEST_KEY],
        ]))[0]['id'];

        // Same table/id/field, same as a second run of the same check
        // would report it - this must update the existing row, not add
        // a second one.
        Issue::upsert($this->sampleData(), date('Y-m-d H:i:s'), $stats);

        $this->assertSame(1, $stats['new'], 'a re-detection must not count as new');
        $this->assertSame(1, $stats['confirmed']);

        $rows = iterator_to_array($DB->request([
            'FROM'  => Issue::getTable(),
            'WHERE' => ['check_key' => self::TEST_KEY],
        ]));
        $this->assertCount(1, $rows, 're-detecting the same issue must not duplicate it');
        $this->assertSame($first_id, (int) $rows[0]['id'], 'the existing row must be reused, not replaced');
    }

    public function testIgnoredStatusSurvivesReconfirmation(): void
    {
        global $DB;

        $stats = ['new' => 0, 'confirmed' => 0];
        Issue::upsert($this->sampleData(), date('Y-m-d H:i:s'), $stats);

        // Simulate an admin marking it ignored from the report screen.
        $DB->update(Issue::getTable(), ['status' => Issue::STATUS_IGNORED], ['check_key' => self::TEST_KEY]);

        // A later scan re-detects the same underlying problem.
        Issue::upsert($this->sampleData(), date('Y-m-d H:i:s'), $stats);

        $rows = iterator_to_array($DB->request([
            'FROM'  => Issue::getTable(),
            'WHERE' => ['check_key' => self::TEST_KEY],
        ]));
        $this->assertCount(1, $rows);
        $this->assertSame(
            Issue::STATUS_IGNORED,
            $rows[0]['status'],
            'upsert() must never overwrite an admin\'s ignore decision'
        );
    }

    public function testDifferentRefIdOnSameFieldUpdatesInPlace(): void
    {
        global $DB;

        $stats = ['new' => 0, 'confirmed' => 0];
        Issue::upsert($this->sampleData(111), date('Y-m-d H:i:s'), $stats);

        // Same (table, id, field) identity, but the dangling FK value
        // changed since the last scan (e.g. it now points at a
        // different, still-missing row). This must still be treated as
        // the same Issue, with ref_id refreshed - not a new one.
        Issue::upsert($this->sampleData(222), date('Y-m-d H:i:s'), $stats);

        $rows = iterator_to_array($DB->request([
            'FROM'  => Issue::getTable(),
            'WHERE' => ['check_key' => self::TEST_KEY],
        ]));
        $this->assertCount(1, $rows);
        $this->assertSame(222, (int) $rows[0]['ref_id']);
    }
}
