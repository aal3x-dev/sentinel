<?php

use GlpiPlugin\Sentinel\Profile;

include('../../../inc/includes.php');

Session::checkRight('profile', UPDATE);

if (isset($_POST['update_sentinel_rights'], $_POST['id'])) {
    Profile::saveForProfile((int) $_POST['id'], $_POST);
}

Html::back();
