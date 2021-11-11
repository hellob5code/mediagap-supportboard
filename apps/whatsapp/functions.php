<?php

/*
 * ==========================================================
 * WHATSAPP APP
 * ==========================================================
 *
 * WhatsApp app main file. © 2021 board.support. All rights reserved.
 *
 */

define('SB_WHATSAPP', '1.0.2');

/*
 * -----------------------------------------------------------
 * SEND WHATSAPP MESSAGE
 * -----------------------------------------------------------
 *
 * Send a WhatsApp message to the user
 *
 */

function sb_whatsapp_send_message($to, $message = '', $attachments = []) {
    if (empty($message) && empty($attachments)) return false;
    $settings = sb_get_setting('whatsapp-twilio');
    $to = trim(str_replace('+', '', $to));
    $user = sb_get_user_by('phone', $to);
    $supported_mime_types = ['jpg', 'jpeg', 'png', 'pdf', 'mp3', 'ogg', 'amr', 'mp4'];

    // Security
    if (!sb_is_agent() && !sb_is_agent($user) && sb_get_active_user_ID() != sb_isset($user, 'id') && empty($GLOBALS['SB_FORCE_ADMIN'])) {
        return new SBError('security-error', 'sb_whatsapp_send_message');
    }

    // Send the message
    $from = $settings['whatsapp-twilio-sender'];
    $header = ['Authorization: Basic ' . base64_encode($settings['whatsapp-twilio-user'] . ':' . $settings['whatsapp-twilio-token'])];
    $message = sb_whatsapp_rich_messages($message, ['user_id' => $user['id']]);
    if ($message[1]) $attachments = $message[1];
    $message = $message[0];
    $query = ['Body' => $message, 'From' => trim(strpos($from, 'whatsapp') === false ? ('whatsapp:' . $from) : $from), 'To' => 'whatsapp:' . $to];
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['whatsapp-twilio-user'] . '/Messages.json';
    $attachments_count = count($attachments);
    if ($attachments_count) {
        if (in_array(strtolower(sb_isset(pathinfo($attachments[0][1]), 'extension')), $supported_mime_types)) $query['MediaUrl'] = $attachments[0][1];
        else $query['Body'] .= PHP_EOL . PHP_EOL . $attachments[0][1];
    }
    $response = sb_curl($url, $query, $header);
    if ($attachments_count > 1) {
        $query['Body'] = '';
        for ($i = 1; $i < $attachments_count; $i++) {
            if (in_array(strtolower(sb_isset(pathinfo($attachments[$i][1]), 'extension')), $supported_mime_types)) $query['MediaUrl'] = $attachments[$i][1];
            else $query['Body'] = $attachments[$i][1];
            $response = sb_curl($url, $query, $header);
        }
    }
    return $response;
}

/*
 * -----------------------------------------------------------
 * WHATSAPP RICH MESSAGES
 * -----------------------------------------------------------
 *
 * Convert Support Board rich messages to WhatsApp rich messages
 *
 */

function sb_whatsapp_rich_messages($message, $extra = false) {
    $shortcode = sb_get_shortcode($message);
    $attachments = false;
    if ($shortcode) {
        $shortcode_id = sb_isset($shortcode, 'id', '');
        $shortcode_name = $shortcode['shortcode_name'];
        $message = trim(str_replace($shortcode['shortcode'], '', $message) . (isset($shortcode['title']) ? ' *' . sb_($shortcode['title']) . '*' : '') . PHP_EOL . sb_(sb_isset($shortcode, 'message', '')));
        switch ($shortcode_name) {
            case 'slider-images':
                $attachments = explode(',', $shortcode['images']);
                for ($i = 0; $i < count($attachments); $i++) {
                    $attachments[$i] = [$attachments[$i], $attachments[$i]];
                }
                $message = '';
                break;
            case 'slider':
            case 'card':
                $suffix = $shortcode_name == 'slider' ? '-1' : '';
                $message = '*' . sb_($shortcode['header' . $suffix]) . '*' . (isset($shortcode['description' . $suffix]) ? (PHP_EOL . $shortcode['description' . $suffix]) : '') . (isset($shortcode['extra' . $suffix]) ? (PHP_EOL . '```' . $shortcode['extra' . $suffix] . '```') : ''). (isset($shortcode['link' . $suffix]) ? (PHP_EOL . PHP_EOL . $shortcode['link' . $suffix]) : '');
                $attachments = [[$shortcode['image' . $suffix], $shortcode['image' . $suffix]]];
                break;
            case 'list-image':
            case 'list':
                $index = 0;
                if ($shortcode_name == 'list-image') {
                    $shortcode['values'] = str_replace('://', '', $shortcode['values']);
                    $index = 1;
                }
                $values = explode(',', $shortcode['values']);
                if (strpos($values[0], ':')) {
                    for ($i = 0; $i < count($values); $i++) {
                        $value = explode(':', $values[$i]);
                        $message .= PHP_EOL . '• *' . trim($value[$index]) . '* ' . trim($value[$index + 1]);
                    }
                } else {
                    for ($i = 0; $i < count($values); $i++) {
                        $message .= PHP_EOL . '• ' . trim($values[$i]);
                    }
                }
                break;
            case 'select':
            case 'buttons':
            case 'chips':
                if ($shortcode_id != 'sb-human-takeover') {
                    $message .= PHP_EOL;
                    $values = explode(',', $shortcode['options']);
                    for ($i = 0; $i < count($values); $i++) {
                        $message .= PHP_EOL . '• ' . trim($values[$i]);
                    }
                } else if (defined('SB_DIALOGFLOW')) sb_dialogflow_set_active_context('human-takeover', [], 2, false, sb_isset($extra, 'user_id'));
                break;
            case 'button':
                $message = $shortcode['link'];
                break;
            case 'video':
                $message = ($shortcode['type'] == 'youtube' ? 'https://www.youtube.com/embed/' : 'https://player.vimeo.com/video/') . $shortcode['id'];
                break;
            case 'image':
                $attachments = [[$shortcode['url'], $shortcode['url']]];
                break;
            case 'rating':
                if (defined('SB_DIALOGFLOW')) sb_dialogflow_set_active_context('rating', [], 2, false, sb_isset($extra, 'user_id'));
                break;
        }
    }
    return [$message, $attachments];
}

?>