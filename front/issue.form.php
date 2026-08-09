<?php

use GlpiPlugin\Sentinel\Issue;

include('../../../inc/includes.php');

Session::checkRight('plugin_sentinel', READ);

$issue = new Issue();

if (isset($_POST['cleanup'])) {
    // "Limpieza": actually deletes the orphan row or file, then the Issue itself.
    Session::checkRight('plugin_sentinel', PURGE);
    $issue->getFromDB((int) $_POST['id']);
    $issue->applyCleanup();
    Html::back();
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

        echo "<div class='center'>";
        echo "<table class='tab_cadre_fixe'>";
        $title = $is_file_issue
            ? htmlspecialchars($issue->fields['path'])
            : htmlspecialchars(sprintf(
                __('%1$s #%2$d', 'sentinel'),
                $issue->fields['source_table'],
                $issue->fields['source_id']
            ));
        echo "<tr><th colspan='2'>" . $title . "</th></tr>";

        $rows = [
            __('Check', 'sentinel')           => $issue->fields['check_key'],
            __('Category', 'sentinel')        => $issue->fields['category'],
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

        echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "' style='margin-top:1em;'>";
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
