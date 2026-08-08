<?php

use GlpiPlugin\Sentinel\Config;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    Config::saveConfig($_POST);
    Html::back();
}

Html::header(
    Config::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'GlpiPlugin\\Sentinel\\Config'
);

Config::showForConfig();

Html::footer();
