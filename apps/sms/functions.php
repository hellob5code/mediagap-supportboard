<?php

/*
 * ==========================================================
 * SMS APP
 * ==========================================================
 *
 * SMS app main file. © 2021 board.support. All rights reserved.
 *
 */

define('SB_SMS', '1.0.2');

/*
 * -----------------------------------------------------------
 * SEND SMS MESSAGE
 * -----------------------------------------------------------
 *
 * Send a SMS message to the user
 *
 */

function sb_sms_send_message($to, $message = '', $attachments = []) {
    // twilio sms send message code

    if (empty($message) && empty($attachments)) return false;
    $settings = sb_get_setting('sms-twilio');
    $to = trim($to);
    $user = sb_get_user_by('phone', $to);

    // Security
    if (!sb_is_agent() && !sb_is_agent($user) && sb_get_active_user_ID() != sb_isset($user, 'id') && empty($GLOBALS['SB_FORCE_ADMIN'])) {
        return new SBError('security-error', 'sb_whatsapp_send_message');
    }

    // Send the message
    $from = $settings['sms-twilio-sender'];
    $header = ['Authorization: Basic ' . base64_encode($settings['sms-twilio-user'] . ':' . $settings['sms-twilio-token'])];
    $query = ['Body' => $message, 'MessagingServiceSid' => $from, 'To' => strpos($to, "+") !== false ? $to : ("+" . $to)];
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['sms-twilio-user'] . '/Messages.json';
    $response = sb_curl($url, $query, $header);
    return $response;
}

?>