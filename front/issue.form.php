<?php

use GlpiPlugin\Sentinel\Issue;

include('../../../inc/includes.php');

Session::checkRight('plugin_sentinel', READ);

$issue = new Issue();

if (isset($_POST['cleanup'])) {
    // "Limpieza": actually deletes the orphan row or file, then the Issue itself.
    Session::checkRight('plugin_sentinel', PURGE);
    // BUG FIX: didn't check getFromDB()'s return value - on a nonexistent
    // ID (e.g. a stale/reused tab, or a concurrent double-click) this
    // silently called applyCleanup() on an empty Issue, which happened to
    // no-op harmlessly, but gave no indication anything was wrong.
    if ($issue->getFromDB((int) $_POST['id'])) {
        $issue->applyCleanup();
        Session::addMessageAfterRedirect(__('Cleaned up.', 'sentinel'));
    } else {
        Session::addMessageAfterRedirect(__('That issue no longer exists - it may have already been resolved.', 'sentinel'), false, WARNING);
    }
    // Html::back() would return here to this same ?id=X URL, but that
    // Issue (and possibly the row/file it pointed to) is gone now -
    // reloading it would just show "not found". Go to the list instead,
    // same as "purge" below.
    Html::redirect(Issue::getReportURL());
} elseif (isset($_POST['ignore'])) {
    Session::checkRight('plugin_sentinel', UPDATE);
    $issue->update([
        'id'     => (int) $_POST['id'],
        'status' => Issue::STATUS_IGNORED,
    ]);
    Html::back();
} elseif (isset($_POST['purge'])) {
    // Removes only the bookkeeping row, leaves the orphan data untouched.
    Session::checkRight('plugin_sentinel', UPDATE);
    $issue->delete(['id' => (int) $_POST['id']], true);
    Html::redirect(Issue::getReportURL());
} else {
    $id = (int) ($_GET['id'] ?? 0);
    Html::header(
        Issue::getTypeName(),
        $_SERVER['PHP_SELF'],
        'tools',
        Issue::class
    );

    if ($issue->getFromDB($id)) {
        $is_file_issue = empty($issue->fields['source_table']) && !empty($issue->fields['path']);
        $is_fk_issue   = $issue->fields['check_key'] === 'orphan_records'
            && $issue->fields['field'] !== 'itemtype/items_id';

        echo "<div class='center' style='max-width: 800px; margin-left:auto; margin-right:auto;'>";

        // Plain-language summary first - this is what most people need to
        // understand the issue. The technical breakdown below is there
        // for anyone who wants to double-check exactly what was found.
        echo "<div class='alert alert-info' style='text-align:left; font-size:1.1em; margin-bottom:1em;'>";
        echo htmlspecialchars($issue->getHumanSummary());
        echo "</div>";

        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . __('Technical details', 'sentinel') . "</th></tr>";

        $rows = [
            __('Check', 'sentinel')           => Issue::getCheckLabel($issue->fields['check_key']),
            __('Category', 'sentinel')        => Issue::getCategoryLabel($issue->fields['category']),
            __('Table', 'sentinel')           => $issue->fields['source_table'],
            __('Row ID', 'sentinel')          => $issue->fields['source_id'],
            __('Field', 'sentinel')           => $issue->fields['field'],
            __('File path', 'sentinel')       => $issue->fields['path'],
            __('Referenced itemtype', 'sentinel') => $issue->fields['ref_itemtype'],
            __('Expected target table', 'sentinel') => $issue->fields['ref_table'],
            __('Missing ID', 'sentinel')      => $issue->fields['ref_id'],
            __('Reason', 'sentinel')          => $issue->fields['reason'],
            __('Status', 'sentinel')          => $issue->fields['status'],
            __('First detected', 'sentinel')  => $issue->fields['date_discover'],
            __('Last confirmed', 'sentinel')  => $issue->fields['date_last_seen'],
        ];
        foreach ($rows as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            echo "<tr><td class='b'>" . htmlspecialchars($label) . "</td><td>"
                . htmlspecialchars((string) $value) . "</td></tr>";
        }
        echo "</table>";

        echo "<form method='post' action='' style='margin-top:1em;'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        if (Session::haveRight('plugin_sentinel', UPDATE)) {
            echo Html::submit(__('Ignore (keep data, stop flagging)', 'sentinel'), ['name' => 'ignore']) . '&nbsp;';
            echo Html::submit(__('Dismiss report only', 'sentinel'), ['name' => 'purge']) . '&nbsp;';
        }
        if (Session::haveRight('plugin_sentinel', PURGE)) {
            if ($is_file_issue) {
                $cleanup_label   = __('Delete file from disk permanently', 'sentinel');
                $cleanup_confirm = __('This will permanently delete the file from disk. This cannot be undone. Continue?', 'sentinel');
            } elseif ($is_fk_issue) {
                $cleanup_label   = sprintf(__('Reset field "%s" to 0', 'sentinel'), $issue->fields['field']);
                $cleanup_confirm = __('This will reset the dangling reference field back to 0, leaving the rest of the row untouched. Continue?', 'sentinel');
            } else {
                $cleanup_label   = __('Delete orphan record permanently', 'sentinel');
                $cleanup_confirm = __('This will permanently delete the row from its original table. This cannot be undone. Continue?', 'sentinel');
            }
            echo Html::submit($cleanup_label, [
                'name'    => 'cleanup',
                'class'   => 'btn btn-danger',
                'confirm' => $cleanup_confirm,
            ]);
        }
        echo "</form>";
        echo "</div>";
    } else {
        Html::displayNotFoundError();
    }

    Html::footer();
}
