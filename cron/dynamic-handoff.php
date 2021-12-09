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
                $message = sb_isset(sb_db_get('select message from sb_messages where conversation_id = '. $value['id'] .' and message != "" ORDER BY id DESC LIMIT 1'), 'message');
                // sb_update_conversation_agent($value['id'], $agent_id, $message);
            
                $header = ['Content-Type: application/x-www-form-urlencoded'];
                $query = [
                    'function' => 'update-conversation-agent',
                    'conversation_id' => $value['id'],
                    'agent_id' => $agent_id,
                    'message' => $message,
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
    $conversations = sb_db_get('SELECT sb_conversations.id, sb_conversations.user_id, sb_conversations.agent_id, sb_conversations.dynamic_handoff_count FROM sb_conversations inner join sb_messages ON sb_conversations.id = sb_messages.conversation_id WHERE sb_conversations.status_code IN(0, 1, 2, 6) AND sb_conversations.source = "sm" AND sb_conversations.dynamic_handoff_count < ' . $count . ' AND sb_conversations.department = ' . $department . ' AND sb_messages.id IN ( SELECT max(sb_messages.id) FROM sb_messages WHERE sb_messages.creation_time < (utc_timestamp() - INTERVAL '. $minute .' MINUTE) group by sb_messages.conversation_id) ORDER BY sb_conversations.id DESC', false);
    return $conversations;
}

sb_init();

?>
