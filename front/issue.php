<?php

use GlpiPlugin\Sentinel\Issue;
use GlpiPlugin\Sentinel\CheckRunner;
use GlpiPlugin\Sentinel\Config;

include('../../../inc/includes.php');

Session::checkRight('plugin_sentinel', READ);

Html::header(
    Issue::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'tools',
    Issue::class
);

// "Modo Analizador": trigger a full, synchronous run of every enabled check.
if (isset($_POST['scan_now'])) {
    Session::checkRight('plugin_sentinel', UPDATE);
    $stats = CheckRunner::run();
    Session::addMessageAfterRedirect(sprintf(
        __('Scan complete: %1$d checks run, %2$d new issues, %3$d confirmed, %4$d resolved.', 'sentinel'),
        $stats['checks_run'],
        $stats['new'],
        $stats['confirmed'],
        $stats['resolved']
    ));
    Html::redirect(Issue::getSearchURL());
}

// Maintenance: drop the bookkeeping for issues the admin already ignored,
// once they've stayed ignored past the configured retention. Never
// touches the underlying data/files those issues pointed to.
if (isset($_POST['purge_ignored'])) {
    Session::checkRight('plugin_sentinel', UPDATE);
    $purged = Issue::purgeOldIgnored();
    Session::addMessageAfterRedirect(sprintf(
        __('%d old ignored issues purged.', 'sentinel'),
        $purged
    ));
    Html::redirect(Issue::getSearchURL());
}

if (Session::haveRight('plugin_sentinel', UPDATE)) {
    $retention_days = (int) Config::getConfig()['retention_days'];

    echo "<div class='center d-flex justify-content-center gap-2' style='margin: 1em 0;'>";

    echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
    echo Html::submit(__('Run all health checks now', 'sentinel'), [
        'name'  => 'scan_now',
        'class' => 'btn btn-primary',
    ]);
    echo "</form>";

    if ($retention_days > 0) {
        echo "<form method='post' action='" . $_SERVER['PHP_SELF'] . "'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::submit(sprintf(
            __('Purge ignored issues older than %d days', 'sentinel'),
            $retention_days
        ), [
            'name'  => 'purge_ignored',
            'class' => 'btn btn-outline-secondary',
        ]);
        echo "</form>";
    }

    echo "</div>";
}

Search::show(Issue::class);

Html::footer();
