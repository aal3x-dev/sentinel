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
use CommonGLPI;
use Html;
use Plugin;
use Profile as GlpiProfile;
use Session;

/**
 * Rights management tab, added on the core Profile object.
 */
class Profile extends CommonDBTM
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Sentinel', 'sentinel');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('id')) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('id')) {
            self::showForProfile($item->getID());
        }
        return true;
    }

    /**
     * Rights exposed by this plugin.
     * A single right ('plugin_sentinel') controls access to the
     * analyzer/report; the PURGE bit is reused to gate the actual
     * deletion of confirmed orphan rows, so an admin can grant
     * "view reports" without granting "delete data".
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => Issue::class,
                'label'    => __('Orphan records (view / analyze)', 'sentinel'),
                'field'    => 'plugin_sentinel',
            ],
        ];
    }

    /**
     * Deliberately plain HTML/PHP instead of a Twig template here: this tab
     * is loaded every time someone opens Administration > Profiles, so it
     * is not the place to depend on a Twig macro whose exact name/signature
     * could not be confirmed against a live 11.0.8 instance.
     */
    public static function showForProfile($profiles_id = 0): void
    {
        global $DB;

        $can_edit = Session::haveRight('profile', UPDATE);

        $profileRight = new \ProfileRight();
        $rights = 0;
        if ($profileRight->getFromDBByCrit([
            'profiles_id' => $profiles_id,
            'name'        => 'plugin_sentinel',
        ])) {
            $rights = (int) $profileRight->fields['rights'];
        }

        echo "<div class='firstbloc'>";
        echo "<form name='form' action='" . Plugin::getWebDir('sentinel') . "/front/profile.right.php' method='post'>";
        echo Html::hidden('id', ['value' => $profiles_id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        $bits = [
            READ  => __('View the report', 'sentinel'),
            UPDATE => __('Run scans, ignore/dismiss findings', 'sentinel'),
            PURGE => __('Permanently delete orphan records', 'sentinel'),
        ];
        foreach ($bits as $bit => $label) {
            $checked = ($rights & $bit) ? "checked" : "";
            $disabled = $can_edit ? "" : "disabled";
            echo "<tr><td>" . htmlspecialchars($label) . "</td><td>";
            echo "<input type='checkbox' name='right_{$bit}' value='1' $checked $disabled>";
            echo "</td></tr>";
        }
        echo "</table>";

        if ($can_edit) {
            echo "<div class='center' style='margin-top:1em;'>";
            echo Html::submit(_x('button', 'Save'), ['name' => 'update_sentinel_rights']);
            echo "</div>";
        }

        echo "</form>";
        echo "</div>";
    }

    /**
     * Persists the checkboxes rendered by showForProfile(). Called from
     * the core Profile::update() flow via the update_item hook is
     * overkill here - kept simple and called directly from front/profile
     * form handling would require overriding core; instead this plugin
     * exposes it through its own tiny endpoint in front/config.php-like
     * fashion is unnecessary too. Simplest robust option: recompute and
     * save directly when this tab's form is posted, detected by presence
     * of any `right_*` key.
     */
    public static function saveForProfile(int $profiles_id, array $input): void
    {
        if (!Session::haveRight('profile', UPDATE)) {
            return;
        }

        $rights = 0;
        foreach ([READ, UPDATE, PURGE] as $bit) {
            if (!empty($input["right_{$bit}"])) {
                $rights |= $bit;
            }
        }

        $profileRight = new \ProfileRight();
        if ($profileRight->getFromDBByCrit(['profiles_id' => $profiles_id, 'name' => 'plugin_sentinel'])) {
            $profileRight->update(['id' => $profileRight->getID(), 'rights' => $rights]);
        } else {
            $profileRight->add(['profiles_id' => $profiles_id, 'name' => 'plugin_sentinel', 'rights' => $rights]);
        }
    }

    /**
     * Ensures every profile has a row for our right (creating missing
     * ones at 0), then grants full rights to whichever profile the
     * current session is using. Shared by hook.php's install() AND by a
     * self-heal check in setup.php's plugin_init - GLPI's plugin
     * reactivation after a version bump has been observed to skip
     * calling plugin_sentinel_install() again, silently going back to
     * "Enabled" without re-granting rights. Rather than depend on
     * exactly when GLPI decides to re-run install(), this can be called
     * safely any time; it's a no-op once rights already exist for the
     * active profile.
     */
    public static function ensureRightsRegistered(): bool
    {
        global $DB;

        $changed = false;

        foreach (self::getAllRights() as $right) {
            $already_present = count($DB->request([
                'FROM'  => \ProfileRight::getTable(),
                'WHERE' => ['name' => $right['field']],
            ]));

            if ($already_present > 0) {
                // Rows exist for this right somewhere - even a 0 here
                // could be an admin's deliberate restriction on some
                // other profile. Leave it alone; only a truly fresh
                // (never-installed-correctly) state gets auto-repaired.
                continue;
            }

            \ProfileRight::addProfileRights([$right['field']]);
            $changed = true;

            if (empty($_SESSION['glpiactiveprofile']['id'])) {
                continue;
            }

            $profileRight = new \ProfileRight();
            if ($profileRight->getFromDBByCrit([
                'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                'name'        => $right['field'],
            ])) {
                // BUG FIX: was ALLSTANDARDRIGHT (31 = READ|UPDATE|CREATE|
                // DELETE|PURGE), but this plugin never checks CREATE or
                // DELETE anywhere - only READ (view report), UPDATE (run
                // scans, ignore/dismiss) and PURGE (actually delete data).
                // Granting the unused bits made the raw rights value
                // misleading (looks like "everything" when 2 of the 5
                // bits do nothing).
                $profileRight->update([
                    'id'     => $profileRight->getID(),
                    'rights' => READ | UPDATE | PURGE,
                ]);
            }
        }

        return $changed;
    }
}
