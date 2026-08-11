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
    if (!empty($stats['filesystem_scan_truncated'])) {
        Session::addMessageAfterRedirect(
            __('The orphan-file scan hit its per-run limit before finishing the whole documents folder - some files may not have been checked this time. Consider increasing "Rows/files processed per batch" in settings.', 'sentinel'),
            false,
            WARNING
        );
    }
    Html::redirect(Issue::getReportURL());
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
    Html::redirect(Issue::getReportURL());
}

if (Session::haveRight('plugin_sentinel', READ)) {
    $last = Config::getLastScanResult();

    echo "<div class='center' style='margin: 1em 0;'>";
    if ($last['at'] === null) {
        echo "<span class='text-muted'>" . __('No scan has run yet.', 'sentinel') . "</span>";
    } else {
        $stats = $last['stats'] ?? [];
        echo "<span class='text-muted'>" . sprintf(
            __('Last scan: %1$s — %2$d checks run, %3$d new, %4$d confirmed, %5$d resolved.', 'sentinel'),
            Html::convDateTime($last['at']),
            $stats['checks_run'] ?? 0,
            $stats['new'] ?? 0,
            $stats['confirmed'] ?? 0,
            $stats['resolved'] ?? 0
        ) . "</span>";
    }
    echo "</div>";
}

if (Session::haveRight('plugin_sentinel', UPDATE)) {
    $retention_days = (int) Config::getConfig()['retention_days'];
    // Generated ONCE and reused in both forms below. Calling
    // Session::getNewCSRFToken() separately per form was invalidating
    // the first form's token the moment the second one rendered -
    // GLPI only keeps the latest token valid, so the "Run all health
    // checks now" button was already broken before the page even
    // finished loading (matches what we saw: no debug output at all on
    // the POST, meaning it was rejected before our code even ran).
    $csrf_token = Session::getNewCSRFToken();

    echo "<div class='center d-flex justify-content-center gap-2' style='margin: 1em 0;'>";

    echo "<form method='post' action=''>";
    echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
    echo Html::submit(__('Run all health checks now', 'sentinel'), [
        'name'  => 'scan_now',
        'class' => 'btn btn-primary',
    ]);
    echo "</form>";

    if ($retention_days > 0) {
        echo "<form method='post' action=''>";
        echo Html::hidden('_glpi_csrf_token', ['value' => $csrf_token]);
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
