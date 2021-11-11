<?php

/*
 * ==========================================================
 * WHATSAPP APP POST FILE
 * ==========================================================
 *
 * WhatsApp app post file to receive messages sent by Twilio. © 2021 board.support. All rights reserved.
 *
 */

$raw = file_get_contents('php://input');

if ($raw) {
    require_once('../../include/functions.php');
    $raw = explode('&', urldecode($raw));
    $response = [];
    for ($i = 0; $i < count($raw); $i++) {
        $value = explode('=', $raw[$i]);
        $response[$value[0]] = str_replace('\/', '/', $value[1]);
    }
    $error = isset($response['ErrorCode']);
    if (isset($response['From']) && !$error) {
        if (!isset($response['Body']) && !isset($response['MediaContentType0'])) return;
        $GLOBALS['SB_FORCE_ADMIN'] = true;
        $user_id = false;
        $conversation_id = false;
        $phone = str_replace('whatsapp:', '', $response['From']);
        $user = sb_get_user_by('phone', $phone);
        $department = sb_get_setting('whatsapp-department');
        $message = $response['Body'];

        if (!$user) {
            $name = $response['ProfileName'];
            $space_in_name = strpos($name, ' ');
            $first_name = $space_in_name ? trim(substr($name, 0, $space_in_name)) : $name . $space_in_name;
            $last_name = $space_in_name ? trim(substr($name, $space_in_name)) : '';
            $extra = ['phone' => [$phone, 'Phone']];
            if (sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')) {
                $detected_language = sb_google_language_detection($message);
                if (!empty($detected_language)) $extra['language'] = [$detected_language, 'Language'];
            }
            $user_id = sb_add_user(['first_name' => $first_name, 'last_name' => $last_name, 'user_type' => 'user'], $extra);
            $user = sb_get_user($user_id);
        } else {
            $user_id = $user['id'];
            $conversation_id = sb_isset(sb_db_get('SELECT id FROM sb_conversations WHERE source = "wa" AND user_id = ' . $user_id . ' LIMIT 1'), 'id');
        }
        if (!$conversation_id) $conversation_id = sb_isset(sb_new_conversation($user_id, 2, '', $department, -1, 'wa'), 'details', [])['id'];

        // Set active user
        $GLOBALS['SB_LOGIN'] = $user;

        // Attachments
        $attachments = [];
        $extension = sb_isset($response, 'MediaContentType0');
        if ($extension) {
            switch ($response['MediaContentType0']) {
                case 'video/mp4':
                    $extension = '.mp4';
                    break;
                case 'image/gif':
                    $extension = '.gif';
                    break;
                case 'image/png':
                    $extension = '.png';
                    break;
                case 'image/jpg':
                case 'image/jpeg':
                    $extension = '.jpg';
                    break;
                case 'image/webp':
                    $extension = '.webp';
                    break;
                case 'audio/ogg':
                    $extension = '.ogg';
                    break;
                case 'audio/mpeg':
                    $extension = '.mp3';
                    break;
                case 'audio/amr':
                    $extension = '.amr';
                    break;
                case 'application/pdf':
                    $extension = '.pdf';
                    break;
            }
            if ($extension) {
                $file_name = basename($response['MediaUrl0']) . $extension;
                array_push($attachments, [$file_name, sb_download_file($response['MediaUrl0'], $file_name)]);
            }
        }

        // Send message
        $response = sb_send_message($user_id, $conversation_id, $message, $attachments, 2);

        // Dialogflow, Notifications, Bot messages
        $response_extarnal = sb_messaging_platforms_functions($conversation_id, $message, $attachments, $user, ['source' => 'wa', 'phone' => $phone]);

        $GLOBALS['SB_FORCE_ADMIN'] = false;
    } else if ($error === 470) {
        $phone = str_replace('whatsapp:', '', $response['To']);
        $user = sb_get_user_by('phone', $phone);
        if (!isset($response['ErrorMessage']) && isset($response['MessageStatus'])) $response['ErrorMessage'] = $response['MessageStatus'];
        if ($user) {
            $agents_ids = sb_get_agents_ids();
            $message = sb_db_get('SELECT id, message, conversation_id FROM sb_messages WHERE user_id IN (' . implode(',', $agents_ids) . ') AND conversation_id IN (SELECT id FROM sb_conversations WHERE source = "wa" AND user_id = ' . $user['id'] . ') ORDER BY creation_time DESC LIMIT 1');
            if ($message) {
                $GLOBALS['SB_FORCE_ADMIN'] = true;
                $user_language = sb_get_user_language($user['id']);
                $user_name = sb_get_user_name($user);
                $user_email = sb_isset($user, 'email', '');
                $conversation_url_parameter = $conversation_id && $user ? ('?conversation=' . $conversation_id . '&token=' . $user['token']) : '';

                // SMS
                if (sb_get_multi_setting('whatsapp-sms', 'whatsapp-sms-active')) {
                    $template = sb_get_multi_setting('whatsapp-sms', 'whatsapp-sms-template');
                    $message_sms = $template ? str_replace('{message}', $message['message'], sb_translate_string($template, $user_language)) : $message['message'];
                    $message_sms = str_replace(['{conversation_url_parameter}', '{recipient_name}', '{recipient_email}'], [$conversation_url_parameter, $user_name, $user_email], $message_sms);
                    $response_sms = sb_send_sms($message_sms, $phone, false, $message['conversation_id']);
                    if ($response_sms['status'] == 'sent' || $response_sms['status'] == 'queued') $response = ['whatsapp-fallback' => true];
                }

                // WhatsApp Template
                $template = sb_get_setting('whatsapp-template');
                if ($template) {
                    $response_template = sb_whatsapp_send_message($phone, str_replace(['{conversation_url_parameter}', '{recipient_name}', '{recipient_email}'], [$conversation_url_parameter, $user_name, $user_email], sb_translate_string($template, $user_language)));
                    if ($response_template['status'] == 'sent' || $response_template['status'] == 'queued') {
                        if (isset($response['whatsapp-fallback'])) {
                            $response['whatsapp-template-fallback'] = true;
                        } else {
                            $response = ['whatsapp-template-fallback' => true];
                        }
                    }
                }

                sb_update_message($message['id'], false, false, $response);
                $GLOBALS['SB_FORCE_ADMIN'] = false;
            }
        }
    }
}

?>