<?php

/*
 * ==========================================================
 * SMS APP POST FILE
 * ==========================================================
 *
 * SMS app post file to receive messages sent by Twilio. © 2021 board.support. All rights reserved.
 *
 */

$raw = file_get_contents('php://input');
require_once('../../include/functions.php');


function sb_sms_get_historical_data($to, $from, $messaging_service_sid, $latest_message) {

    $settings = sb_get_setting('sms-twilio');
    $to = trim($to);
    $from = trim($from);

    // API call to fetch data sent from Agent to User
    $header = ['Authorization: Basic ' . base64_encode($settings['sms-twilio-user'] . ':' . $settings['sms-twilio-token']),
        'Content-Type: application/json'
    ];
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['sms-twilio-user'] . '/Messages.json' . '?To=' . $to . '&MessagingServiceSid=' . $messaging_service_sid . '&From=' . $from . '&PageSize=1000';
    $response = sb_curl($url, '', $header, 'GET');
    $response = json_decode($response, true);

    // $responseTo = [];
    $responseTo = array_map(function($value) {
        $object = new stdClass();
        $object->user_id = 1;
        $object->body = $value['body'];
        if ($value['date_sent']) {
            $object->date_sent = gmdate('Y-m-d H:i:s', strtotime($value['date_sent']));
        }
        else {
            $object->date_sent = gmdate('Y-m-d H:i:s');
        }
        return $object;
    }, $response["messages"]);
    
    // API call to fetch data sent from User to Agent
    $header = ['Authorization: Basic ' . base64_encode($settings['sms-twilio-user'] . ':' . $settings['sms-twilio-token']),
        'Content-Type: application/json'
    ];
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $settings['sms-twilio-user'] . '/Messages.json' . '?MessagingServiceSid=' . $messaging_service_sid . '&From=' . $to . '&To=' . $from . '&PageSize=1000';
    $response = sb_curl($url, '', $header, 'GET');
    $response = json_decode($response, true);


    $user = sb_get_user_by('phone', $to);
    $to_user_id = $user['id'];
    // $responseTo = [];
    $responseFrom = array_map(function($value) use ($to_user_id) {
        $object = new stdClass();
        $object->user_id = $to_user_id;
        $object->body = $value['body'];
        if ($value['date_sent']) {
            $object->date_sent = gmdate('Y-m-d H:i:s', strtotime($value['date_sent']));
        }
        else {
            $object->date_sent = gmdate('Y-m-d H:i:s');
        }
        return $object;
    }, $response["messages"]);

    $response = array_merge($responseTo, $responseFrom);
    usort($response, function($a, $b) {
        return strtotime($a->date_sent) - strtotime($b->date_sent);
    });

    if (count($response) > 0) {
        if (isset($response[count($response) - 1]) && $response[count($response) - 1]->body === $latest_message) {
            array_pop($response);
        }
    }
    
    return $response;
}


function insert_history_data_to_message($history_data, $conversation_id) {
    for($i = 0; $i < count($history_data); $i++) {
        sb_db_query('INSERT INTO sb_messages(user_id, message, creation_time, status_code, attachments, payload, conversation_id) VALUES ("' . $history_data[$i]->user_id . '", "' . sb_db_escape($history_data[$i]->body) . '", "' . $history_data[$i]->date_sent . '", 0, "", "", "' . $conversation_id . '")', true);
    }
}


if ($raw) {
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
        $phone = $response['From'];
        $agent_phone = $response['To'];
        $user = sb_get_user_by('phone', $phone);
        $department = sb_get_setting('sms-department');
        $message = $response['Body'];

        if (!$user) {
            $name = $phone;
            $space_in_name = strpos($name, ' ');
            $first_name = $space_in_name ? trim(substr($name, 0, $space_in_name)) : $name . $space_in_name;
            $last_name = $space_in_name ? trim(substr($name, $space_in_name)) : '';
            $extra = ['phone' => [$phone, 'Phone']];
            if (sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')) {
                $detected_language = sb_google_language_detection($message);
                if (!empty($detected_language)) $extra['language'] = [$detected_language, 'Language'];
            }
            $user_id = sb_add_user(['first_name' => $first_name, 'last_name' => $last_name, 'user_type' => 'user'], $extra, true, 'sms');
            $user = sb_get_user($user_id);
        } else {
            $user_id = $user['id'];
            $conversation_id = sb_isset(sb_db_get('SELECT id FROM sb_conversations WHERE source = "sm" AND user_id = ' . $user_id . ' LIMIT 1'), 'id');
        }
        if (!$conversation_id) {
            $conversation_id = sb_isset(sb_new_conversation($user_id, 2, '', $department, -1, 'sm'), 'details', [])['id'];
            
            // for new conversation we will check the historical data first
            $settings = sb_get_setting('sms-twilio');
            $to = $phone;
            $from = $agent_phone;
            $messaging_service_sid = $settings['sms-twilio-sender'];
            $history_data = sb_sms_get_historical_data($to, $from, $messaging_service_sid, $message);
            insert_history_data_to_message($history_data, $conversation_id);
        }

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
        $agent_id = sb_isset(sb_db_get('SELECT agent_id FROM sb_conversations WHERE source = "sm" AND id = ' . $conversation_id . ' LIMIT 1'), 'agent_id');
        // if agent is assigned to conversation so on new message we keep the conversation on Agent Inbox
        $conversation_ststus_code = sb_isset_num($agent_id) ? 6 : 2; 
        $response = sb_send_message($user_id, $conversation_id, $message, $attachments, $conversation_ststus_code);

        // Dialogflow, Notifications, Bot messages
        $response_extarnal = sb_messaging_platforms_functions($conversation_id, $message, $attachments, $user, ['source' => 'sm', 'phone' => $phone]);
        $GLOBALS['SB_FORCE_ADMIN'] = false;
    } else if ($error === 470) {
        $phone = $response['To'];
        $user = sb_get_user_by('phone', $phone);
        if (!isset($response['ErrorMessage']) && isset($response['MessageStatus'])) $response['ErrorMessage'] = $response['MessageStatus'];
        if ($user) {
            $agents_ids = sb_get_agents_ids();
            $message = sb_db_get('SELECT id, message, conversation_id FROM sb_messages WHERE user_id IN (' . implode(',', $agents_ids) . ') AND conversation_id IN (SELECT id FROM sb_conversations WHERE source = "sm" AND user_id = ' . $user['id'] . ') ORDER BY creation_time DESC LIMIT 1');
            if ($message) {
                $GLOBALS['SB_FORCE_ADMIN'] = true;
                $user_language = sb_get_user_language($user['id']);
                $user_name = sb_get_user_name($user);
                $user_email = sb_isset($user, 'email', '');
                $conversation_url_parameter = $conversation_id && $user ? ('?conversation=' . $conversation_id . '&token=' . $user['token']) : '';

                sb_update_message($message['id'], false, false, $response);
                $GLOBALS['SB_FORCE_ADMIN'] = false;
            }
        }
    }
}

?>
