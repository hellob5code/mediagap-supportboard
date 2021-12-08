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
                    'login-cookie' => 'QksxK2d3aUY3UkVVcGhQcUo4RlhYeWJjZXZ0enBnSk1SWms3RE0ycllodFcvcVRtbFVhekU1dmljcHhVajdjWm5Vc1d3NnhvWk14ZGNPakZOcGJqb0tUSlY1Y2NqUmdIdVV2RXZQSHMxV0NiNmN2c2lUbkNkTGM4bXRQMHJxMXA4THdFdzdQUWtWcExkcHlxZFVOQSszRzVYaUpDckRSVmdYNDFZVUJmelJMdG1CQnR4TFlmaEpOTzluc0pvaWxTK1ZqOC9RcnBzRmRlODdhY002N3VtSnlFYThIRzBmdjczdGdURmI0c3ZselgzNjJlL0VVUStXY2xmdnFvMGdqanAxeXhmSXgrUEorL2hLZjlNQ1hiK2FaVWJwc3pueEZYbGpCbHJiQVJWRkI1NURYV2ZJSVpZNzcvem13eGRFWlp1Q0h6OHcyTzNFMDNCMDhSamoxazBKQ1UrQ2o1cGhmb0Z5bDdvRFNhR2JXbHBXN1E3V2k4U3lNOTZCQzRRdGNLU250Si8ra2J2dDFpUEp5YTFLV21INXc2UTN2MlJQZk4wTGxNZFhQMGJIdCtpWnNaSVNhVTFrb3JNRmplMUUwMU5TU3ZrTXAxdU10clVIUllOblU4NEE9PQ'
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
    $conversations = sb_db_get('SELECT sb_conversations.id, sb_conversations.user_id, sb_conversations.agent_id, sb_conversations.dynamic_handoff_count FROM sb_conversations inner join sb_messages ON sb_conversations.id = sb_messages.conversation_id WHERE sb_conversations.status_code IN(0, 1, 2, 6) AND sb_conversations.source = "sm" AND sb_conversations.dynamic_handoff_count <= ' . $count . ' AND sb_conversations.department = ' . $department . ' AND sb_messages.id IN ( SELECT max(sb_messages.id) FROM sb_messages WHERE sb_messages.creation_time < (utc_timestamp() - INTERVAL '. $minute .' MINUTE) group by sb_messages.conversation_id) ORDER BY sb_conversations.id DESC', false);
    // print_r($conversations);
    return $conversations;
}

sb_init();

?>
