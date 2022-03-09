<?php

/*
 * ==========================================================
 * Conversation Dynamic Handoff FILE
 * ==========================================================
 *
 * Dynamic handoff file to invoke function by cron job. © 2021 board.support. All rights reserved.
 *
 */

require_once('../include/functions.php');

function sb_init() {
    $dynamic_handoff = sb_get_setting('dynamic-handoff');
    $department = sb_get_setting('sms-department');
    
    if (sb_isset_num($dynamic_handoff['dynamic-handoff-time']) 
        && $dynamic_handoff['dynamic-handoff-time'] > 0
        && sb_isset_num($dynamic_handoff['dynamic-handoff-count']) 
        && $dynamic_handoff['dynamic-handoff-count'] > 0) {
        $conversations = sb_get_unassigned_conversations($dynamic_handoff['dynamic-handoff-time'], $dynamic_handoff['dynamic-handoff-count'], $department);

        $GLOBALS['SB_FORCE_ADMIN'] = true;
        foreach ($conversations as $key => $value) {
            $agent_id = sb_routing(-1, $department, false, $value['agent_id']);
            
            if (sb_isset_num($agent_id) && sb_isset_num($value['id'])) {
                // $message = sb_isset(sb_db_get('select message from sb_messages where conversation_id = '. $value['id'] .' and message != "" ORDER BY id DESC LIMIT 1'), 'message');
                // $message = trim(preg_replace('/\s\s+/', ' ', $message));
            
                $header = ['Content-Type: application/x-www-form-urlencoded'];
                $query = [
                    'function' => 'update-conversation-agent',
                    'conversation_id' => $value['id'],
                    'agent_id' => $agent_id,
                    'message' => 'Yes',
                    'user_id' => $value['user_id'],
                    'language' => false,
                    'login-cookie' => 'OVNsY0wxeVAyRWVNWEtjbmQvNjdBcFpBcmNSN29RMVVGRTIzSGdBUmVCS1hkT2ZyWkVoZVNIdlNVRkZ1OWdzdjM4ais5cE9RSzZNTk8vUEZpOXI4amRiRERjYlJnbGlYeUd0dGxGV2pLdy9JNUIyT1AzMVZ5b2oyazEvSUdDaXBiTER3UmJudXVHR04wS0I5V2kzWUo3MUdUY0JlNWoyUmp1enNQbVZDeWdFTkhmYXdBSFpERXFCc0VVeDV6RW9iaVFLKzBuUVNuT04zSGhpWldGODFRdEY2ckhOWFZwVkFGYnY3UW1NWkEzNlI2eFVaaVNGUUhBdlNqcFNvdVFVTWR2QmJCUGhmY1B2UkU2Ny9hNVJwQ3ZMdTBlL25zWnpNcWplVXE3YnZ0WEx4QUxaOHd3WExSRnowV0FGaC93NnJBTTFHaGtGY056S3hHRGh5MVhkNnNIVERKeituMTVJVnVHRENoWmpPU05KN1YrT3JHaFZlUGVUbm9kN2NHOTZVTUhnSjBEUWZoeVBCQm9wTXozOERVVzdtQW9zU3lENkRhYkhGOXk4ZWRJTDNOR3AwNkNkS2h4VDdNM1U0SnNteVh3SzRFNjdTQkRGdkJVWXRFYVllSWNwTjhiZGppQ3pkNlZaWGVCM1FKWEU9'
                ];
                $url = SB_URL . '/include/ajax.php';
                $response = sb_curl($url, $query, $header);
                $count = intval($value['dynamic_handoff_count']) + 1;
                sb_db_query('UPDATE sb_conversations SET dynamic_handoff_count = ' . $count . ' WHERE id = '. $value['id']);
                print_r($response);
            } else {
                print_r($agent_id);
                break;
            }
        }
        $GLOBALS['SB_FORCE_ADMIN'] = false;
    }
    
}

function sb_get_unassigned_conversations($minute, $count, $department) {
    // $conversations = sb_db_get('SELECT sb_conversations.id, sb_conversations.user_id, sb_conversations.agent_id, sb_conversations.dynamic_handoff_count FROM sb_conversations INNER JOIN sb_messages ON sb_conversations.id = sb_messages.conversation_id WHERE sb_conversations.status_code IN(0, 1, 2, 6) AND sb_conversations.source = "sm" AND sb_conversations.dynamic_handoff_count < ' . $count . ' AND sb_conversations.department = ' . $department . ' AND sb_messages.id IN (SELECT MAX(sb_messages.id) FROM sb_messages inner join sb_users ON sb_messages.user_id = sb_users.id WHERE sb_messages.creation_time < (UTC_TIMESTAMP() - INTERVAL '. $minute .' MINUTE) AND sb_users.user_type not in ("admin", "agent") GROUP BY sb_messages.conversation_id) ORDER BY sb_conversations.id DESC', false);
    
    // conv with last message before 60 minutes
    $conversations = sb_db_get('SELECT sb_messages.conversation_id FROM sb_messages WHERE sb_messages.id IN(SELECT MAX(sb_messages.id) FROM sb_messages INNER JOIN sb_conversations ON sb_messages.conversation_id = sb_conversations.id WHERE sb_conversations.status_code IN (0 , 1, 2, 6) AND sb_conversations.source = "sm" AND sb_conversations.dynamic_handoff_count < ' . $count . ' AND sb_conversations.department = ' . $department . ' GROUP BY sb_messages.conversation_id) AND sb_messages.creation_time < (UTC_TIMESTAMP() - INTERVAL ' . $minute . ' MINUTE)', false);
    $time_conversation_list = array();
    $time_conversation_comma_str = null;
    if ($conversations) {
        foreach ($conversations as $key => $value) {
            array_push($time_conversation_list, $value['conversation_id']);
        }
        $time_conversation_comma_str = implode(",", $time_conversation_list);
    }

    // conv list where admin agent have sent atleast one message
    $conversation_list_comma_str = null;
    if ($time_conversation_comma_str) {
        $conversations = sb_db_get('SELECT sb_messages.conversation_id FROM sb_messages INNER JOIN sb_users ON sb_messages.user_id = sb_users.id INNER JOIN sb_conversations ON sb_messages.conversation_id = sb_conversations.id WHERE sb_users.user_type IN("admin" , "agent") AND sb_conversations.status_code IN (0 , 1, 2, 6) AND sb_conversations.source = "sm" AND sb_conversations.dynamic_handoff_count < ' . $count . ' AND sb_conversations.department = ' . $department . ' AND sb_conversations.id IN ('. $time_conversation_comma_str .') AND sb_messages.user_id <> 1 AND sb_messages.message <> "" GROUP BY sb_messages.conversation_id', false);
        $agent_admin_conversation_list = array();
        foreach ($conversations as $key => $value) {
            array_push($agent_admin_conversation_list, $value['conversation_id']);
        }
        $conversation_list = array_diff($time_conversation_list, $agent_admin_conversation_list);
        $conversation_list_comma_str = implode(",", $conversation_list);
    }

    // conv list which are older then 60 minutes and does not contain the message sent by admin or agent
    $conversations = array();
    if ($conversation_list_comma_str) {
        $conversations = sb_db_get('SELECT sb_conversations.id, sb_conversations.user_id, sb_conversations.agent_id, sb_conversations.dynamic_handoff_count FROM sb_conversations WHERE sb_conversations.status_code IN(0, 1, 2, 6) AND sb_conversations.source = "sm" AND sb_conversations.dynamic_handoff_count < ' . $count . ' AND sb_conversations.department = ' . $department . '  AND sb_conversations.id IN ( '. $conversation_list_comma_str .' ) ORDER BY sb_conversations.id DESC', false);
    }
    return $conversations;
}

sb_init();

?>
