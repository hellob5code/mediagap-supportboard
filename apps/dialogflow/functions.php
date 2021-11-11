<?php

/*
 * ==========================================================
 * DIALOGFLOW APP
 * ==========================================================
 *
 * Dialogflow App main file. � 2021 board.support. All rights reserved.
 *
 */

define('SB_DIALOGFLOW', '1.1.9');

/*
 * -----------------------------------------------------------
 * OBJECTS
 * -----------------------------------------------------------
 *
 * Dialogflow objects
 *
 */

class SBDialogflowEntity {
    public $data;

    function __construct($id, $values, $prompts = []) {
        $this->data = ['displayName' => $id, 'entities' => $values, 'kind' => 'KIND_MAP', 'enableFuzzyExtraction' => true];
    }

    public function __toString() {
        return $this->json();
    }

    function json() {
        return json_encode($this->data);
    }

    function data() {
        return $this->data;
    }
}

class SBDialogflowIntent {
    public $data;

    function __construct($name, $training_phrases, $bot_responses, $entities = [], $entities_values = [], $payload = false, $input_contexts = [], $output_contexts = [], $prompts = [], $id = false) {
        $training_phrases_api = [];
        $parameters = [];
        $parameters_checks = [];
        $messages = [];
        $json = json_decode(file_get_contents(SB_PATH . '/apps/dialogflow/data.json'), true);
        $entities = array_merge($entities, $json['entities']);
        $entities_values = array_merge($entities_values, $json['entities-values']);
        $project_id = false;
        if (is_string($bot_responses)) {
            $bot_responses = [$bot_responses];
        }
        if (is_string($training_phrases)) {
            $training_phrases = [$training_phrases];
        }
        for ($i = 0; $i < count($training_phrases); $i++) {
            $parts_temp = explode('@', $training_phrases[$i]);
            $parts = [];
            $parts_after = false;
            for ($j = 0; $j < count($parts_temp); $j++) {
                $part = ['text' => ($j == 0 ? '' : '@') . $parts_temp[$j]];
                for ($y = 0; $y < count($entities); $y++) {
                    $entity = is_string($entities[$y]) ? $entities[$y] : $entities[$y]['displayName'];
                    $entity_type = '@' . $entity;
                    $entity_name = str_replace('.', '-', $entity);
                    $entity_value = empty($entities_values[$entity]) ? $entity_type : $entities_values[$entity][array_rand($entities_values[$entity])];
                    if (strpos($part['text'], $entity_type) !== false) {
                        $mandatory = true;
                        if (strpos($part['text'], $entity_type . '*') !== false) {
                            $mandatory = false;
                            $part['text'] = str_replace($entity_type . '*', $entity_type, $part['text']);
                        }
                        $parts_after = explode($entity_type, $part['text']);
                        $part = ['text' => $entity_value,  'entityType' => $entity_type,  'alias' => $entity_name, 'userDefined' => true];
                        if (count($parts_after) > 1) {
                            $parts_after = ['text' => $parts_after[1]];
                        } else {
                            $parts_after = false;
                        }
                        if (!in_array($entity, $parameters_checks)) {
                            array_push($parameters, ['displayName' => $entity_name, 'value' => '$' . $entity, 'mandatory' => $mandatory, 'entityTypeDisplayName' => '@' . $entity, 'prompts' => sb_isset($prompts, $entity_name, [])]);
                            array_push($parameters_checks, $entity);
                        }
                        break;
                    }
                }
                array_push($parts, $part);
                if ($parts_after) array_push($parts, $parts_after);
            }
            array_push($training_phrases_api, ['type' => 'EXAMPLE', 'parts' => $parts]);
        }
        for ($i = 0; $i < count($bot_responses); $i++) {
            array_push($messages, ['text' => ['text' => $bot_responses[$i]]]);
        }
        if (!empty($payload)) {
            $std = new stdClass;
            $std->payload = $payload;
            array_push($messages, $std);
        }
        if (!empty($input_contexts) && is_array($input_contexts)) {
            $project_id = sb_get_setting('dialogflow-project-id');
            for ($i = 0; $i < count($input_contexts); $i++) {
                $input_contexts[$i] = 'projects/' . $project_id. '/agent/sessions/-/contexts/' . $input_contexts[$i];
            }
        }
        if (!empty($output_contexts) && is_array($output_contexts)) {
            $project_id = $project_id == false ? sb_get_setting('dialogflow-project-id') : $project_id;
            for ($i = 0; $i < count($output_contexts); $i++) {
                $is_array = is_array($output_contexts[$i]);
                $output_contexts[$i] = ['name' => 'projects/' . $project_id . '/agent/sessions/-/contexts/' . ($is_array ? $output_contexts[$i][0] : $output_contexts[$i]), 'lifespanCount' => ($is_array ? $output_contexts[$i][1] : 3)];
            }
        }
        $t = ['displayName' => $name, 'trainingPhrases' => $training_phrases_api, 'parameters' => $parameters, 'messages' => $messages, 'inputContextNames' => $input_contexts, 'outputContexts' => $output_contexts];
        if ($id) $t['name'] = $id;
        $this->data = $t;
    }

    public function __toString() {
        return $this->json();
    }

    function json() {
        return json_encode($this->data);
    }

    function data() {
        return $this->data;
    }
}

/*
 * -----------------------------------------------------------
 * SEND DIALOGFLOW BOT MESSAGE
 * -----------------------------------------------------------
 *
 * Send the user message to the bot and return the reply
 *
 */

$sb_recursion = true;
function sb_dialogflow_message($conversation_id = false, $message = '', $token = -1, $language = false, $attachments = [], $event = '') {
    $user_id = $conversation_id && sb_is_agent() ? sb_db_get('SELECT user_id FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id))['user_id'] : sb_get_active_user_ID();
    $query = ['queryInput' => [], 'queryParams' => ['payload' => ['support_board' => ['conversation_id' => $conversation_id, 'user_id' => $user_id]]]];
    $bot_id = sb_get_bot_id();
    $cx = sb_get_setting('dialogflow-edition', 'es') == 'cx';
    $human_takeover = sb_get_setting('dialogflow-human-takeover');
    $human_takeover = $human_takeover['dialogflow-human-takeover-active'] ? $human_takeover : false;
    $response_success = [];

    if (empty($bot_id)) {
        return new SBValidationError('bot-id-not-found');
    }
    if ($language == false || empty($language[0]) || !sb_get_setting('dialogflow-multilingual')) {
        $language = ['en'];
    } else {
        $language[0] = sb_dialogflow_language_code($language[0]);
        if (count($language) > 1 && $language[1] == 'language-detection') $response_success['language_detection'] = $language[0];
    }
    $query['queryInput']['languageCode'] = $language[0];

    // Retrive token
    if ($token == -1 || $token === false) {
        $token = sb_dialogflow_get_token();
        if (sb_is_error($token)) {
            return $token;
        }
    }

    // Attachments
    $attachments = sb_json_array($attachments);
    for ($i = 0; $i < count($attachments); $i++) {
        $message .= ' [name:' . $attachments[$i][0] . ',url:' . $attachments[$i][1] . ',extension:' . pathinfo($attachments[$i][0], PATHINFO_EXTENSION) . ']';
    }

    // Events
    if (!empty($event)) {
        $query['queryInput']['event'] = $cx ? ['event' => $event] : ['name' => $event, 'languageCode' => $language[0]];
    }

    // Message
    if (!empty($message)) {
        $query['queryInput']['text'] = ['text' => $message, 'languageCode' => $language[0]];
    }

    // Send user message to Dialogflow
    $query = json_encode($query);
    $session_id = $user_id ? $user_id : 'sb';
    $response = sb_dialogflow_curl('/agent/sessions/' . $session_id . ':detectIntent', $query, false, 'POST', $token);
    $response_query = sb_isset($response, 'queryResult', []);
    $messages = sb_isset($response_query, 'fulfillmentMessages', sb_isset($response_query, 'responseMessages', []));
    $unknow_answer = sb_isset($response_query, 'action') == 'input.unknown' || (isset($response_query['match']) && $response_query['match']['matchType'] == 'NO_MATCH');
    $results = [];

    // Parameters
    $parameters = isset($response_query['parameters']) && count($response_query['parameters']) ? $response_query['parameters'] : [];
    if (isset($response_query['outputContexts']) && count($response_query['outputContexts']) && isset($response_query['outputContexts'][0]['parameters'])) {
        for ($i = 0; $i < count($response_query['outputContexts']); $i++) {
            if (isset($response_query['outputContexts'][$i]['parameters'])) {
                $parameters = array_merge($response_query['outputContexts'][$i]['parameters'], $parameters);
            }
        }
    }

    // Language detection
    if ($unknow_answer && !sb_is_agent() && sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active') && count(sb_db_get('SELECT id FROM sb_messages WHERE user_id = ' . $user_id . ' LIMIT 3', false)) < 3) {
        $detected_language = sb_google_language_detection($message, $token);
        if ($detected_language != $language[0] && !empty($detected_language)) {
            $agent = sb_dialogflow_curl('/agent', '', false, 'GET');
            sb_update_user_value($user_id, 'language', $detected_language);
            $response['queryResult']['action'] = 'sb-language-detection';
            if ($detected_language == sb_isset($agent, 'defaultLanguageCode') || in_array($detected_language, sb_isset($agent, 'supportedLanguageCodes', []))) {
                return sb_dialogflow_message($conversation_id, $message, $token, [$detected_language, 'language-detection'], $attachments, $event);
            } else {
                $language_detection_message = sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-message');
                if (!empty($language_detection_message) && $conversation_id) {
                    $language_name = sb_google_get_language_name($detected_language);
                    $language_detection_message = str_replace('{language_name}', $language_name, sb_translate_string($language_detection_message, $detected_language));
                    $message_id = sb_send_message($bot_id, $conversation_id, $language_detection_message)['id'];
                    return ['token' => $token, 'messages' => [['message' => $language_detection_message, 'attachments' => [], 'payload' => ['language_detection' => true], 'id' => $message_id]], 'response' => $response, 'language_detection_message' => $language_detection_message, 'message_id' => $message_id];
                }
            }
        }
    }

    // Dialogflow response
    $count = count($messages);
    $is_assistant = true;
    $response['outputAudio'] = '';
    for ($i = 0; $i < $count; $i++) {
        if (isset($messages[$i]['text']) && $messages[$i]['text']['text'][0] != '') {
            $is_assistant = false;
            break;
        }
    }
    for ($i = 0; $i < $count; $i++) {
        $bot_message = '';

        // Payload
        $payload = sb_isset($messages[$i], 'payload');
        if ($payload) {
            if (isset($payload['redirect'])) {
                $payload['redirect'] = sb_dialogflow_merge_fields($payload['redirect'], $parameters, $language[0]);
            }
        }

        // Google Assistant
        if ($is_assistant) {
            if (isset($messages[$i]['platform']) && $messages[$i]['platform'] == 'ACTIONS_ON_GOOGLE') {
                if (isset($messages[$i]['simpleResponses']) && isset($messages[$i]['simpleResponses']['simpleResponses'])) {
                    $item = $messages[$i]['simpleResponses']['simpleResponses'];
                    if (isset($item[0]['textToSpeech'])) {
                        $bot_message = $item[0]['textToSpeech'];
                    } else if ($item[0]['displayText']) {
                        $bot_message = $item[0]['displayText'];
                    }
                }
            }
        } else if (isset($messages[$i]['text'])) {
            // Message
            $bot_message = $messages[$i]['text']['text'][0];
        }

        // Attachments
        $attachments = [];
        if ($payload) {
            if (isset($payload['attachments'])) {
                $attachments = $payload['attachments'];
                if ($attachments == '' && !is_array($attachments)) {
                    $attachments = [];
                }
            }
        }

        // WooCommerce
        if (defined('SB_WOOCOMMERCE')) {
            $woocommerce = sb_woocommerce_dialogflow_process_message($bot_message, $payload);
            $bot_message = $woocommerce[0];
            $payload = $woocommerce[1];
        }

        // Send the bot message to Support Board or start human takeover
        if ($bot_message || $payload) {
            if ($conversation_id) {
                $is_human_takeover = sb_dialogflow_is_human_takeover($conversation_id);
                if ($human_takeover && $unknow_answer && strlen($message) > 3 && strpos($message, ' ') && !$is_human_takeover) {      
                    if ($human_takeover['dialogflow-human-takeover-auto']) {
                        $human_takeover_messages = sb_dialogflow_human_takeover($conversation_id);
                        for ($j = 0; $j < count($human_takeover_messages); $j++) {
                        	array_push($results, ['message' => $human_takeover_messages[$j]['message'], 'attachments' => [], 'payload' => false, 'id' => $human_takeover_messages[$j]['id']]);
                        }    
                        $response_success['human_takeover'] = true;
                    } else {
                        $human_takeover_message = '[chips id="sb-human-takeover" options="' . sb_rich_value($human_takeover['dialogflow-human-takeover-confirm'], false) . ',' . sb_rich_value($human_takeover['dialogflow-human-takeover-cancel'], false) . '" message="' . sb_rich_value($human_takeover['dialogflow-human-takeover-message']) . '"]';
                        $message_id = sb_send_message($bot_id, $conversation_id, $human_takeover_message)['id'];
                        array_push($results, ['message' => $human_takeover_message, 'attachments' => [], 'payload' => false, 'id' => $message_id]);
                    }      
                } else if (!$is_human_takeover || (!sb_is_user_online(sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id')) && !$unknow_answer)) {
                    $bot_message = sb_dialogflow_merge_fields($bot_message, $parameters, $language[0]);
                    $message_id = sb_send_message($bot_id, $conversation_id, $bot_message, $attachments, -1, $response)['id'];
                    array_push($results, ['message' => $bot_message, 'attachments' => $attachments, 'payload' => $payload, 'id' => $message_id]);
                } 
            } else {
                array_push($results, ['message' => sb_dialogflow_merge_fields($bot_message, $parameters, $language[0]), 'attachments' => $attachments, 'payload' => $payload]);
            }  
        }
    }

    if (count($results)) {

        // Return the bot messages list
        $response_success['token'] = $token;
        $response_success['messages'] = $results;
        $response_success['response'] = $response;
        return $response_success;
    } else if (isset($response['error']) && $response['error']['code'] == 401) {

        // Reload the function and force it to generate a new token
        global $sb_recursion;
        if ($sb_recursion) {
            $sb_recursion = false;
            return sb_dialogflow_message($conversation_id, $message, -1, $language);
        }
    }

    return ['response' => $response];
}

// [Deprecated] This function will be removed soon
function sb_send_bot_message($conversation_id = false, $message, $token = -1, $language = false, $attachments = [], $event = '') {
    return sb_dialogflow_message($conversation_id, $message, $token, $language, $attachments, $event);
}

/*
 * -----------------------------------------------------------
 * INTENTS
 * -----------------------------------------------------------
 *
 * 1. Create an Intent
 * 2. Update an existing Intent
 * 3. Create multiple Intents
 * 4. Delete multiple Intents
 * 5. Return all Intents
 *
 */

function sb_dialogflow_create_intent($training_phrases, $bot_response, $language = '', $conversation_id = false) {
    $training_phrases_api = [];
    for ($i = 0; $i < count($training_phrases); $i++) {
        array_push($training_phrases_api, ['type' => 'TYPE_UNSPECIFIED', 'parts' => ['text' => $training_phrases[$i]], 'repeatCount' => 1]);
    }
    $response = sb_dialogflow_curl('/agent/intents', ['displayName' => sb_string_slug(strlen($training_phrases[0]) > 100 ? substr($training_phrases[0], 0, 99) : $training_phrases[0]), 'priority' => 500000, 'webhookState' => 'WEBHOOK_STATE_UNSPECIFIED', 'trainingPhrases' => $training_phrases_api, 'messages' => [['text' => ['text' => $bot_response]]]], $language);
    if (sb_get_setting('dialogflow-edition') == 'cx') {
        $flow_name = '00000000-0000-0000-0000-000000000000';
        if ($conversation_id) {
            $messages = sb_db_get('SELECT payload FROM sb_messages WHERE conversation_id = ' . sb_db_escape($conversation_id) . ' AND payload <> "" ORDER BY id DESC');
            for ($i = 0; $i < count($messages); $i++) {
            	$payload = json_decode($messages['payload'], true);
                if (isset($payload['queryResult']) && isset($payload['queryResult']['currentPage'])) {
                    $flow_name = $payload['queryResult']['currentPage'];
                    $flow_name = substr($flow_name, strpos($flow_name, '/flows/') + 7);
                    if (strpos($flow_name, '/')) $flow_name = substr($flow_name, 0, strpos($flow_name, '/'));
                    break;
                }
            }
        }
        $flow = sb_dialogflow_curl('/flows/' . $flow_name, '', $language, 'GET');
        array_push($flow['transitionRoutes'], ['intent' => $response['name'], 'triggerFulfillment' => ['messages' => [['text' => ['text' => [$bot_response]]]]]]);
        $response = sb_dialogflow_curl('/flows/' . $flow_name . '?updateMask=transitionRoutes', $flow, $language, 'PATCH');
    }
    if (isset($response['displayName'])) {
        return true;
    }
    return $response;
}

function sb_dialogflow_update_intent($intent_name, $training_phrases, $language = '') {
    $pos = strpos($intent_name, '/intents/');
    $intent_name = $pos ? substr($intent_name, $pos + 9) : $intent_name;
    $intent = sb_dialogflow_get_intents($intent_name, $language);
    if (!isset($intent['trainingPhrases'])) $intent['trainingPhrases'] = [];
    for ($i = 0; $i < count($training_phrases); $i++) {
        array_push($intent['trainingPhrases'], ['type' => 'TYPE_UNSPECIFIED', 'parts' => ['text' => $training_phrases[$i]], 'repeatCount' => 1]);
    }
    return isset(sb_dialogflow_curl('/agent/intents/' . $intent_name . '?updateMask=trainingPhrases', $intent, $language, 'PATCH')['name']);
}

function sb_dialogflow_batch_intents($intents, $language = '') {
    $intents_array = [];
    for ($i = 0; $i < count($intents); $i++) {
        array_push($intents_array, $intents[$i]->data());
    }
    $query = ['intentBatchInline' => ['intents' => $intents_array], 'intentView' => 'INTENT_VIEW_UNSPECIFIED'];
    if (!empty($language)) $query['languageCode'] = $language;
    return sb_dialogflow_curl('/agent/intents:batchUpdate', $query);
}

function sb_dialogflow_batch_intents_delete($intents) {
    return sb_dialogflow_curl('/agent/intents:batchDelete', ['intents' => $intents]);
}

function sb_dialogflow_get_intents($intent_name = false, $language = '') {
    $next_page_token = true;
    $paginatad_items = [];
    $intents = [];
    while ($next_page_token) {
        $items = sb_dialogflow_curl($intent_name ? ('/agent/intents/' . $intent_name . '?intentView=INTENT_VIEW_FULL') : ('/agent/intents?pageSize=1000&intentView=INTENT_VIEW_FULL' . ($next_page_token !== true && $next_page_token !== false ? ('&pageToken=' . $next_page_token) : '')), '', $language, 'GET');
        if ($intent_name) return $items;
        $next_page_token = sb_isset($items, 'nextPageToken');
        if (sb_is_error($next_page_token)) die($next_page_token);
        array_push($paginatad_items, sb_isset($items, 'intents'));
    }
    for ($i = 0; $i < count($paginatad_items); $i++) {
        $items = $paginatad_items[$i];
        if ($items) {
            for ($j = 0; $j < count($items); $j++) {
                if (!empty($items[$j])) array_push($intents, $items[$j]);
            }
        }
    }
    return $intents;
}

// [Deprecated] - This function will be removed soon
function sb_dialogflow_intent($training_phrases, $bot_response, $language = '') {
    return sb_dialogflow_create_intent($training_phrases, $bot_response, $language = '');
}

/*
 * -----------------------------------------------------------
 * ENTITIES
 * -----------------------------------------------------------
 *
 * Create, get, update, delete a Dialogflow entities
 *
 */

function sb_dialogflow_create_entity($entity_name, $values, $language = '') {
    $response = sb_dialogflow_curl('/agent/entityTypes', is_a($values, 'SBDialogflowEntity') ? $values->data() : (new SBDialogflowEntity($entity_name, $values))->data(), $language);
    if (isset($response['displayName'])) {
        return true;
    } else if (isset($response['error']) && sb_isset($response['error'], 'status') == 'FAILED_PRECONDITION') {
        return new SBValidationError('duplicate-dialogflow-entity');
    }
    return $response;
}

function sb_dialogflow_update_entity($entity_id, $values, $entity_name = false, $language = '') {
    $response = sb_dialogflow_curl('/agent/entityTypes/' . $entity_id, is_a($values, 'SBDialogflowEntity') ? $values->data() : (new SBDialogflowEntity($entity_name, $values))->data(), $language, 'PATCH');
    if (isset($response['displayName'])) {
        return true;
    }
    return $response;
}

function sb_dialogflow_get_entity($entity_id = 'all', $language = '') {
    $entities = sb_dialogflow_curl('/agent/entityTypes', '', $language, 'GET');
    if (isset($entities['entityTypes'])) {
        $entities = $entities['entityTypes'];
        if ($entity_id == 'all') {
            return $entities;
        }
        for ($i = 0; $i < count($entities); $i++) {
            if ($entities[$i]['displayName'] == $entity_id) {
                return $entities[$i];
            }
        }
        return new SBValidationError('entity-not-found');
    } else return $entities;
}

/*
 * -----------------------------------------------------------
 * MISCELLANEOUS
 * -----------------------------------------------------------
 *
 * 1. Get a fresh Dialogflow access token
 * 2. Convert the Dialogflow merge fields to the final values
 * 3. Activate a context in the active conversation
 * 4. Return the details of a Dialogflow agent
 * 5. Chinese language sanatization
 * 6. Dialogflow curl
 * 7. Human takeover
 * 8. Check if human takeover is active
 * 9. Execute payloads
 * 10. Knowledge articles csv
 * 
 */

function sb_dialogflow_get_token() {
    $token = sb_get_setting('dialogflow-token');
    if (empty($token)) {
        return new SBError('dialogflow-refresh-token-not-found', 'sb_dialogflow_get_token');
    }
    $response = sb_download('https://board.support/synch/dialogflow.php?refresh-roken=' . $token);
    if ($response != 'api-dialogflow-error' && $response != false) {
        $token = json_decode($response, true);
        if (isset($token['access_token'])) {
            return $token['access_token'];
        }
    }
    return new SBError('dialogflow-refresh-token-error', 'sb_dialogflow_get_token', $response);
}

function sb_dialogflow_merge_fields($message, $parameters, $language = '') {
    if (defined('SB_WOOCOMMERCE')) {
        $message = sb_woocommerce_merge_fields($message, $parameters, $language);
    }
    return $message;
}

function sb_dialogflow_set_active_context($context_name, $parameters = [], $life_span = 5, $token = false, $user_id = false, $language = false) {
    if (!sb_get_setting('dialogflow-active')) return false;
    $project_id = sb_get_setting('dialogflow-project-id');
    $language = $language === false ? (sb_get_setting('dialogflow-multilingual') ? sb_get_user_language() : '') : $language;
    $session_id = $user_id === false ? sb_isset(sb_get_active_user(), 'id', 'sb') : $user_id;
    $parameters = empty($parameters) ? '' : ', "parameters": ' . (is_string($parameters) ? $parameters : json_encode($parameters));
    $query = '{ "queryInput": { "text": { "languageCode": "' . (empty($language) ? 'en' : $language) . '", "text": "sb-trigger-context" }}, "queryParams": { "contexts": [{ "name": "projects/' . $project_id . '/agent/sessions/' . $session_id . '/contexts/' . $context_name . '", "lifespanCount": ' . $life_span . $parameters . ' }] }}';
    return sb_dialogflow_curl('/agent/sessions/' . $session_id . ':detectIntent', $query, false, 'POST', $token);
}

function sb_dialogflow_get_agent() {
    return sb_dialogflow_curl('/agent/', '', '', 'GET');
}

function sb_dialogflow_language_code($language) {
    return $language == 'zh' ? 'zh-CN' : $language;
}

function sb_dialogflow_curl($url_part, $query = '', $language = false, $type = 'POST', $token = false) {

    // Project ID
    $project_id = trim(sb_get_setting('dialogflow-project-id'));
    if (empty($project_id)) {
        return new SBError('project-id-not-found', 'sb_dialogflow_curl');
    }

    // Retrive token
    $token = empty($token) || $token == -1 ? sb_dialogflow_get_token() : $token;
    if (sb_is_error($token)) {
        return new SBError('token-error', 'sb_dialogflow_curl');
    }

    // Language
    if (!empty($language)) {
        $language = (strpos($url_part, '?') ? '&' : '?') . 'languageCode=' . $language;
    }

    // Query
    if (!is_string($query)) {
        $query = json_encode($query);
    }

    // Edition and version
    $edition = sb_get_setting('dialogflow-edition', 'es');
    $version = 'v2beta1/projects/';
    if ($edition == 'cx') {
        $version = 'v3beta1/';
        $url_part = str_replace('/agent/', '/', $url_part);
    }

    // Send
    $url = 'https://' . sb_get_setting('dialogflow-location', '') . 'dialogflow.googleapis.com/' . $version . $project_id . $url_part . $language;
    $response = sb_curl($url, $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], $type);
    return $type == 'GET' ? json_decode($response, true) : $response;
}

function sb_dialogflow_human_takeover($conversation_id, $auto_messages = false) {
    $human_takeover = sb_get_setting('dialogflow-human-takeover');
    $conversation_id = sb_db_escape($conversation_id);
    $bot_id = sb_get_bot_id();
    $data = sb_db_get('SELECT A.id AS `user_id`, A.email, A.first_name, A.last_name, A.profile_image, B.agent_id, B.department, B.status_code FROM sb_users A, sb_conversations B WHERE A.id = B.user_id AND B.id = ' . sb_db_escape($conversation_id));
    $user_id = $data['user_id'];
    $messages = sb_db_get('SELECT A.user_id, A.message, A.attachments, A.creation_time, B.first_name, B.last_name, B.profile_image, B.user_type FROM sb_messages A, sb_users B WHERE A.conversation_id = ' . $conversation_id . ' AND A.user_id = B.id AND A.message <> "' . sb_($human_takeover['dialogflow-human-takeover-confirm']) . '" AND A.message NOT LIKE "%sb-human-takeover%" AND A.payload NOT LIKE "%human-takeover%" ORDER BY A.id ASC', false);
    $count = count($messages);
    $last_message = $messages[$count - 1]['message']; 
    $response = [];
    sb_send_message($bot_id, $conversation_id, '', [], false, ['human-takeover' => true]);
    
    // Human takeover message and status code
    $message = sb_($human_takeover['dialogflow-human-takeover-message-confirmation']);
    if (!empty($message)) {
        $message_id = sb_send_message($bot_id, $conversation_id, $message, [], 2, ['human-takeover-message-confirmation' => true, 'preview' => $last_message])['id'];
        array_push($response, ['message' => $message, 'id' => $message_id]);
    } else if ($data['status_code'] != 2) sb_update_conversation_status($conversation_id, 2);


    // Auto messages
    if ($auto_messages) {
        $auto_messages = ['offline', 'follow_up', 'subscribe'];
        for ($i = 0; $i < count($auto_messages); $i++) {
            $auto_message = $i == 0 || empty($data['email']) ? sb_execute_bot_message($auto_messages[$i], $conversation_id, $last_message) : false;
            if ($auto_message) array_push($response, $auto_message);
        }
    }

    // Notifications
    $recipient_id = $data['agent_id'] ? $data['agent_id'] : ($data['department'] ? ('department-' . $data['department']) : 'agents');
    $code = '<div style="max-width:600px;clear:both;">';
    for ($i = ($count - 1); $i > -1; $i--) {
        $message = $messages[$i];
        $css = sb_is_agent($messages[$i]) ? ['left', '0 50px 20px 0', '#F0F0F0'] : ['right', '0 0 20px 50px', '#E6F2FC'];
        $attachments = sb_isset($message, 'attachments', []);
        $code .= '<div style="float:' . $css[0] . ';clear:both;margin:' . $css[1] . ';"><span style="background-color:' . $css[2] . ';padding:10px 15px;display:inline-block;border-radius:4px;margin:0;">' . $message['message'] . '</span>';
        if ($attachments) { 
            $code .= '<br>';
            $attachments = json_decode($attachments, true);
            for ($j = 0; $j < count($attachments); $j++) {
                $code .= '<br><a style="color:#626262;text-decoration:underline;" href="' . $attachments[$j][1] . '">' . $attachments[$j][0] . '</a>';
            }
        }
        $code .= '<br><span style="color:rgb(168,168,168);font-size:12px;display:block;margin:10px 0 0 0;">' . $message['first_name'] . ' ' . $message['last_name'] . ' | ' . $message['creation_time'] . '</span></div>';
    }
    $code .= '<div style="clear:both;"></div></div>';
    sb_send_agents_notifications($recipient_id, $last_message, str_replace('{name}', sb_get_setting('bot-name', 'Dialogflow'), sb_('This message has been sent because {name} does not know the answer to the user\'s question.')), $conversation_id, false, $data, $data['department'], ['email' => $code]);

    // Slack
    if (defined('SB_SLACK') && sb_get_setting('slack-active')) {
        for ($i = 0; $i < count($messages); $i++) {
        	sb_send_slack_message($user_id, sb_get_user_name($messages[$i]), $messages[$i]['profile_image'], $messages[$i]['message'], sb_isset($messages[$i], 'attachments'));
        }
    }

    return $response;
}

function sb_dialogflow_is_human_takeover($conversation_id) {
    $name = 'human-takeover-' . $conversation_id;
    if (isset($GLOBALS[$name])) return $GLOBALS[$name];
    $datetime = gmdate('Y-m-d H:i:s', time() - 864000);
    if (sb_db_get('SELECT COUNT(*) AS `count` FROM sb_messages A, sb_users B WHERE A.user_id = B.id AND (B.user_type = "agent" OR B.user_type = "admin") AND A.conversation_id = ' . sb_db_escape($conversation_id) . ' AND A.creation_time > "' . $datetime . '" LIMIT 1')['count'] > 0) {
        $GLOBALS[$name] = true;
        return true;
    }
    $GLOBALS[$name] = sb_db_get('SELECT COUNT(*) AS `count` FROM sb_messages WHERE payload = "{\"human-takeover\":true}" AND conversation_id = ' . sb_db_escape($conversation_id) . ' AND creation_time > "' . $datetime . '" LIMIT 1')['count'] > 0;
    return $GLOBALS[$name];
}

function sb_dialogflow_payload($payload, $conversation_id, $message = false, $extra = false) {
    if (isset($payload['agent'])) {
        sb_update_conversation_agent($conversation_id, $payload['agent'], $message);
    }
    if (isset($payload['department'])) {
        sb_update_conversation_department($conversation_id, $payload['department'], $message);
    }
    if (isset($payload['human-takeover']) || isset($payload['disable-bot'])) {
        $messages = sb_dialogflow_human_takeover($conversation_id, $extra && isset($extra['source']));
        if ($extra && isset($extra['source'])) {
            switch ($extra['source']) {
                case 'fb':
                    for ($i = 0; $i < count($messages); $i++) {
                        sb_messenger_send_message($extra['psid'], $extra['page_id'], $messages[$i]['message'], sb_isset($messages[$i], 'attachments'), $messages[$i]['id']);
                    }
                    break;
                case 'wa':
                    for ($i = 0; $i < count($messages); $i++) {
                        sb_whatsapp_send_message($extra['phone'], $messages[$i]['message'], sb_isset($messages[$i], 'attachments'));
                    }
                    break;
            }
        }
    }
    if (isset($payload['send-email'])) {
        $send_to_active_user = $payload['send-email']['recipient'] == 'active_user';
        sb_email_create($send_to_active_user ? sb_get_active_user_ID() : 'agents', $send_to_active_user ? sb_get_setting('bot-name') : sb_get_user_name(), $send_to_active_user ? sb_get_setting('bot-image') : sb_isset(sb_get_active_user(), 'profile_image'), $payload['send-email']['message'], sb_isset($payload['send-email'], 'attachments'), false, $conversation_id);
    }
    if (isset($payload['redirect']) && $extra) {
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, $payload['redirect']);
        switch ($extra['source']) {
            case 'fb':
                sb_messenger_send_message($extra['psid'], $extra['page_id'], $payload['redirect'], [], $message_id);
                break;
            case 'wa':
                sb_whatsapp_send_message($extra['phone'], $payload['redirect']);
                break;
        }
    }
    if (isset($payload['transcript']) && $extra) {
        $transcript_url = sb_transcript($conversation_id);
        $attachments = [[$transcript_url, $transcript_url]];
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, '', $attachments);
        switch ($extra['source']) {
            case 'fb':
                sb_messenger_send_message($extra['psid'], $extra['page_id'], '', $attachments, $message_id);
                break;
            case 'wa':
                sb_whatsapp_send_message($extra['phone'], $transcript_url);
                break;
        }
    }
    if (isset($payload['rating'])) {
        sb_set_rating(['conversation_id' => $conversation_id, 'agent_id' => sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id', sb_get_bot_id()), 'user_id' => sb_get_active_user_ID(), 'message' => '', 'rating' => $payload['rating']]);
    }
}

/*
 * -----------------------------------------------------------
 * SMART REPLY
 * -----------------------------------------------------------
 *
 * 1. Return the suggestions
 * 2. Update a smart reply conversation with a new message
 * 3. Generate the conversation transcript data for a dataset
 *
 */

function sb_dialogflow_smart_reply($message, $smart_reply_data = false, $language = false, $token = false, $language_detection = false) {
    $suggestions = [];
    $smart_reply_response = false;
    $token = empty($token) ? sb_dialogflow_get_token() : $token;
    $smart_reply = sb_get_multi_setting('dialogflow-smart-reply', 'dialogflow-smart-reply-profile');

    // Bot
    $messages = sb_dialogflow_message(false, $message, $token, $language);
    if (sb_is_error($messages)) return $messages;
    $query_result = sb_isset($messages['response'], 'queryResult', []);
    $detected_language_response = false;
    if (!empty($messages['messages']) && sb_isset($query_result, 'action') != 'input.unknown' && (!isset($query_result['match']) || $query_result['match']['matchType'] != 'NO_MATCH')) {
        for ($i = 0; $i < count($messages['messages']); $i++) {
            $value = $messages['messages'][$i]['message'];
            if (!empty($value)) array_push($suggestions, $value);
        }
    } else if ($language_detection) {
        $detected_language = sb_google_language_detection($message, $token);
        if ($detected_language != $language[0] && !empty($detected_language)) {
            $supported_language_codes = sb_isset(sb_dialogflow_curl('/agent', '', false, 'GET'), 'supportedLanguageCodes', []);
            if (in_array($detected_language, $supported_language_codes)) {
                $detected_language_response = $detected_language;
                if (isset($_POST['user_id'])) sb_update_user_value($_POST['user_id'], 'language', $detected_language);
                return sb_dialogflow_smart_reply($message, $smart_reply_data, [$detected_language], $token);
            }
        }
    }

    // Smart Reply
    if (!count($suggestions) && $smart_reply) {
        $query = '{ "textInput": { "text": "' .  str_replace('"', '\"', $message) . '", "languageCode": "' . $language[0] . '" }}';
        $exernal_setting = [];
        if ($smart_reply_data && empty($smart_reply_data['user'])) {
            $exernal_setting = sb_get_external_setting('smart-reply', []);
            $smart_reply_response = sb_isset($exernal_setting, $smart_reply_data['conversation_id']);
        } else {
            $smart_reply_response = $smart_reply_data;
        }
        if (!$smart_reply_response) {
            $query_2 = '{ "conversationProfile": "' . $smart_reply . '" }';
            $project_id = substr($smart_reply, 0, strpos($smart_reply, '/conversationProfiles'));
            $response = sb_curl('https://dialogflow.googleapis.com/v2/' . $project_id . '/conversations', $query_2, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query_2)], 'POST');
            if (isset($response['name'])) {
                $smart_reply_response = ['conversation' => $response['name']];
                for ($i = 0; $i < 2; $i++) {
                    $query_2 = '{ "role": "' . ($i ? 'HUMAN_AGENT' : 'END_USER') . '" }';
                    $smart_reply_response[$i ? 'agent' : 'user'] = sb_isset(sb_curl('https://dialogflow.googleapis.com/v2/' . $response['name'] . '/participants', $query_2, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query_2)], 'POST'), 'name');
                }
                if (isset($smart_reply_data['conversation_id'])) {
                    $exernal_setting[$smart_reply_data['conversation_id']] = $smart_reply_response;
                    sb_save_external_setting('smart-reply', $exernal_setting);
                }
            }
        }
        if (!empty($smart_reply_response['user'])) {
            $response = sb_curl('https://dialogflow.googleapis.com/v2/' . $smart_reply_response['user'] . ':analyzeContent', $query, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], 'POST');
        }
        if (isset($response['humanAgentSuggestionResults'])) {
            $results = $response['humanAgentSuggestionResults'];
            $keys = [['suggestSmartRepliesResponse', 'smartReplyAnswers', 'reply'], ['suggestFaqAnswersResponse', 'faqAnswers', 'answer'], ['suggestArticlesResponse', 'articleAnswers', 'uri']];
            for ($i = 0; $i < count($results); $i++) {
                for ($y = 0; $y < 3; $y++) {
                	if (isset($results[$i][$keys[$y][0]])) {
                        $answers = sb_isset($results[$i][$keys[$y][0]], $keys[$y][1], []);
                        for ($j = 0; $j < count($answers); $j++) {
                            array_push($suggestions, $answers[$j][$keys[$y][2]]);
                        }
                    }
                }
            }
        }
    }

    return ['suggestions' => $suggestions, 'token' => sb_isset($messages, 'token'), 'detected_language' => $detected_language_response, 'smart_reply' => $smart_reply_response];
}

function sb_dialogflow_smart_reply_update($message, $smart_reply_data, $language, $token, $user_type = 'agent') {
    $user = sb_isset($smart_reply_data, $user_type);
    if (empty($user)) {
        $user = sb_isset(sb_isset(sb_get_external_setting('smart-reply', []), $smart_reply_data['conversation_id'], []), $user_type);
    }
    if ($user) {
        $token = empty($token) ? sb_dialogflow_get_token() : $token;
        $query = '{ "textInput": { "text": "' .  str_replace('"', '\"', $message) . '", "languageCode": "' . $language[0] . '" }}';
        return sb_curl('https://dialogflow.googleapis.com/v2/' . $user . ':analyzeContent', $query, ['Content-Type: text/plain', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], 'POST');
    }
    return false;
}

function sb_dialogflow_smart_reply_generate_conversations_data() {
    $path = sb_upload_path() . '/conversations-data/';
    if (!file_exists($path)) mkdir($path, 0777, true);
    $conversations = sb_db_get('SELECT id FROM sb_conversations', false);
    for ($i = 0; $i < count($conversations); $i++) {
        $code = '';
        $conversation_id = $conversations[$i]['id'];
        $messages = sb_db_get('SELECT A.message, A.creation_time, B.user_type, B.id FROM sb_messages A, sb_users B WHERE A.user_id = B.id AND B.user_type <> "bot" AND A.conversation_id = ' . $conversation_id . ' ORDER BY A.creation_time ASC', false);
        $count = count($messages);
        if ($count) {
            for ($j = 0; $j < $count; $j++) {
                $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $messages[$j]['creation_time']);
                $code .= '{ "start_timestamp_usec": ' . $datetime->getTimestamp() . ', "text": "' . str_replace('"', '\"', $messages[$j]['message']) . '", "role": " ' . (sb_is_agent($messages[$j]['user_type']) ? 'AGENT' : 'CUSTOMER') . '", "user_id": ' . $messages[$j]['id'] . ' },';
            }
            sb_file($path . 'conversation-' . $conversation_id . '.json', '{"entries": [' . substr($code, 0, -1) . ']}');
        }
    }
    return $path;
}
//sb_dialogflow_knowledge_articles();
function sb_dialogflow_knowledge_articles() {
    // Generate CSV
   // $knowledge_base_name = sb_isset(sb_dialogflow_curl('/knowledgeBases', ['displayName' => 'Support Board'], false, 'POST'), 'name');
    $knowledge_base_name = true;
    if ($knowledge_base_name) {
        $file_path = SB_PATH . '/uploads/dialogflow_faq.csv';
        $file = fopen($file_path, 'r');
        $file_bytes = fread($file, filesize($file_path));
        fclose($file);
 
        $t = sb_dialogflow_curl('/knowledgeBases/MTQxODY4MDE3MjA2MTIzNTYwOTY/documents', ['displayName' => 'Support Board', 'mimeType' => 'text/csv', 'knowledgeTypes' => ['FAQ'], 'rawContent' => base64_encode($file_bytes)], false, 'POST');

        //$t = sb_dialogflow_curl('/knowledgeBases/' . substr($knowledge_base_name, strripos($knowledge_base_name, '/') + 1) . '/documents', ['displayName' => 'Support Board', 'mimeType' => 'csv', 'knowledgeTypes' => ['FAQ'], 'contentUri' => 'https://sandbox.board.support/test.csv'], false, 'POST');
        $t = '';
    }
 

    // Create or update

}

/*
 * -----------------------------------------------------------
 * GOOGLE
 * -----------------------------------------------------------
 *
 * 1. Detect the language of a string
 * 2. Retrieve the full language name in the desired language
 * 4. Text translation
 * 5. Analyze Entities
 *
 */

function sb_google_language_detection($string, $token = false) {
    if (!strpos(trim($string), ' ')) return false;
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['q' => $string]);
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2/detect', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    return isset($response['data']) ? $response['data']['detections'][0][0]['language'] : false;
}

function sb_google_get_language_name($target_language_code, $token = false) {
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['target' => $target_language_code]);
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2/languages', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    if (isset($response['data'])) {
        $languages = $response['data']['languages'];
        for ($i = 0; $i < count($languages); $i++) {
            if ($languages[$i]['language'] == $target_language_code) {
                return $languages[$i]['name'];
            }
        }
    }
    return $response;
}

function sb_google_translate($strings, $language_code, $token = false) {
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['q' => $strings, 'target' => $language_code]);
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    return [$response, $token];
}

function sb_google_language_detection_update_user($string, $user_id = false, $token = false) {
    $user_id = $user_id ? $user_id : sb_get_active_user_ID();
    $detected_language = sb_google_language_detection($string, $token);
    $language = sb_get_user_language($user_id);
    if ($detected_language != $language[0] && !empty($detected_language)) {
        return sb_update_user_value($user_id, 'language', $detected_language);
    }
    return false;
}

function sb_google_analyze_entities($string, $token = false) {
    if (!strpos(trim($string), ' ')) return false;
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['document' => ['type' => 'PLAIN_TEXT', 'content' => $string]]);
    return sb_curl('https://language.googleapis.com/v1/documents:analyzeEntities', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
}

/*
 * ----------------------------------------------------------
 * DIALOGFLOW INTENT BOX
 * ----------------------------------------------------------
 *
 * Display the form to create a new intent for Dialogflow
 *
 */

function sb_dialogflow_intent_box() { ?>
<div class="sb-lightbox sb-dialogflow-intent-box">
    <div class="sb-info"></div>
    <div class="sb-top-bar">
        <div>Dialogflow Intent</div>
        <div>
            <a class="sb-send sb-btn sb-icon">
                <i class="sb-icon-check"></i><?php sb_e('Send') ?> Intent
            </a>
            <a class="sb-close sb-btn-icon">
                <i class="sb-icon-close"></i>
            </a>
        </div>
    </div>
    <div class="sb-main sb-scroll-area">
        <div class="sb-title sb-intent-add">
            <?php sb_e('Add user expressions') ?>
            <i data-value="add" data-sb-tooltip="<?php sb_e('Add expression') ?>" class="sb-btn-icon sb-icon-plus"></i>
            <i data-value="previous" class="sb-btn-icon sb-icon-arrow-up"></i>
            <i data-value="next" class="sb-btn-icon sb-icon-arrow-down"></i>
        </div>
        <div class="sb-input-setting sb-type-text sb-first">
            <input type="text" />
        </div>
        <div class="sb-title">
            <?php sb_e('Response from the bot') ?>
        </div>
        <div class="sb-input-setting sb-type-textarea">
            <textarea></textarea>
        </div>
        <div class="sb-title">
            <?php sb_e('Language') ?>
        </div>
        <?php echo sb_dialogflow_languages_list() ?>
        <div class="sb-title sb-title-search">
            <?php sb_e('Intent') ?>
            <div class="sb-search-btn">
                <i class="sb-icon sb-icon-search"></i>
                <input type="text" autocomplete="false" placeholder="<?php sb_e('Search for Intents...') ?>">
            </div>
            <i id="sb-intent-preview" data-sb-tooltip="<?php sb_e('Preview') ?>" class="sb-icon-help"></i>
        </div>
        <div class="sb-input-setting sb-type-select">
            <select id="sb-intents-select">
                <option value=""><?php sb_e('New Intent') ?></option>
            </select>
        </div>
    </div>
</div>
<?php } ?>