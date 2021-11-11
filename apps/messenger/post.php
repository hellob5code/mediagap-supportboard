<?php

/*
 * ==========================================================
 * POST.PHP
 * ==========================================================
 *
 * Messenger response listener. This file receive the Facebook Messenger messages of the agents forwarded by board.support. This file requires the Messenger App.
 * © 2021 board.support. All rights reserved.
 *
 */

$raw = file_get_contents('php://input');
if ($raw) {
    require('functions.php');
    sb_messenger_listener(json_decode($raw, true));
}

?>