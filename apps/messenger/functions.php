<?php

/*
 * ==========================================================
 * MESSENGER APP
 * ==========================================================
 *
 * Facebook Messenger app main file. © 2021 board.support. All rights reserved.
 *
 */

define('SB_MESSENGER', '1.0.5');

/*
 * -----------------------------------------------------------
 * SEND MESSENGER MESSAGE
 * -----------------------------------------------------------
 *
 * Send a message to the Facebook user in Messenger
 *
 */

function sb_messenger_send_message($psid, $facebook_page_id, $message = '', $attachments = [], $metadata = false) {
    if (empty($message) && empty($attachments)) return new SBError('missing-arguments', 'sb_messenger_send_message', 'No message or attachments.');
    $facebook_pages = sb_get_setting('messenger-pages', []);
    $response = false;
    $user = sb_get_user_by('facebook-id', $psid);

    for ($i = 0; $i < count($facebook_pages); $i++) {
        if ($facebook_pages[$i]['messenger-page-id'] == $facebook_page_id) {

            // Message
            $data = ['messaging_type' => 'RESPONSE', 'recipient' => ['id' => $psid], 'message' => []];
            if (!empty($message)) {
                $message = sb_messenger_rich_messages($message, ['user_id' => $user['id']]);
                if ($message[0] || $message[1]) {
                    $data['message']['text'] = $message[0];
                    $data['message'] = array_merge($data['message'], $message[1]);
                    $data['message']['metadata'] = $metadata;
                    $response = sb_curl('https://graph.facebook.com/me/messages?access_token=' . $facebook_pages[$i]['messenger-page-token'], $data);
                } else if (isset($message[2]['attachments'])) $attachments = $message[2]['attachments'];
            }

            // Attachments
            if (!empty($attachments) && is_array($attachments)) {
                for ($y = 0; $y < count($attachments); $y++) {
                    $attachment = $attachments[$y];
                    $attachment_type = false;
                    switch (strtolower(pathinfo($attachment[0], PATHINFO_EXTENSION))) {
                        case 'gif':
                        case 'jpeg':
                        case 'jpg':
                        case 'png':
                            $attachment_type = 'image';
                            break;
                        case 'mp4':
                        case 'mov':
                        case 'avi':
                        case 'mkv':
                        case 'wmv':
                            $attachment_type = 'video';
                            break;
                        case 'mp3':
                        case 'aac':
                        case 'wav':
                        case 'flac':
                            $attachment_type = 'audio';
                            break;
                        default:
                            $attachment_type = 'file';
                    }
                    $response = sb_curl('https://graph.facebook.com/me/messages?access_token=' . $facebook_pages[$i]['messenger-page-token'], ['messaging_type' => 'RESPONSE', 'recipient' => ['id' => $psid], 'message' => ['attachment' => ['type' => $attachment_type, 'payload' => ['url' => $attachment[1], 'is_reusable' => true]], 'metadata' => $metadata, 'text' => '']]);
                }
            }

            return $response;
        }
    }
    return new SBError('facebook-page-not-found', 'sb_messenger_send_message', 'Facebook page not found.');
}

/*
 * -----------------------------------------------------------
 * MESSENGER RICH MESSAGES
 * -----------------------------------------------------------
 *
 * Convert Support Board rich messages to Messenger rich messages
 *
 */

function sb_messenger_rich_messages($message, $extra = false) {
    $shortcode = sb_get_shortcode($message);
    $facebook = [];
    $extra_values = [];
    if ($shortcode) {
        $shortcode_id = sb_isset($shortcode, 'id', '');
        $shortcode_name = $shortcode['shortcode_name'];
        $message = trim(str_replace($shortcode['shortcode'], '', $message) . (isset($shortcode['title']) ? ' *' . sb_($shortcode['title']) . '*' : '') . PHP_EOL . sb_(sb_isset($shortcode, 'message', '')));
        switch ($shortcode_name) {
            case 'slider-images':
                $extra_values = explode(',', $shortcode['images']);
                for ($i = 0; $i < count($extra_values); $i++) {
                    $extra_values[$i] = [$extra_values[$i], $extra_values[$i]];
                }
                $extra_values = ['attachments' => $extra_values];
                $facebook = false;
                $message = false;
                break;
            case 'slider':
            case 'card':
                $elements = [];
                if ($shortcode_name == 'card') {
                    $elements = [['title' => sb_($shortcode['header']), 'subtitle' => sb_(sb_isset($shortcode, 'description', '')) . (isset($shortcode['extra']) ? (PHP_EOL . $shortcode['extra']) : ''), 'image_url' => $shortcode['image'], 'buttons' => [['type' => 'web_url', 'url' => $shortcode['link'], 'title' => sb_($shortcode['link-text'])]]]];
                } else {
                    $index = 1;
                    while ($index) {
                        if (isset($shortcode['header-' . $index])) {
                            array_push($elements, ['title' => sb_($shortcode['header-' . $index]), 'subtitle' => sb_(sb_isset($shortcode, 'description-' . $index, '')) . (isset($shortcode['extra-' . $index]) ? (PHP_EOL . $shortcode['extra-' . $index]) : ''), 'image_url' => $shortcode['image-' . $index], 'buttons' => [['type' => 'web_url', 'url' => $shortcode['link-' . $index], 'title' => sb_($shortcode['link-text-' . $index])]]]);
                            $index++;
                        } else $index = false;
                    }
                }
                $facebook = ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'generic', 'elements' => $elements]]];
                $message = '';
                break;
            case 'select':
            case 'buttons':
            case 'chips':
                $facebook = ['quick_replies' => []];
                $values = explode(',', $shortcode['options']);
                for ($i = 0; $i < count($values); $i++) {
                    array_push($facebook['quick_replies'], ['content_type' => 'text', 'title' => sb_($values[$i]), 'payload' => $shortcode_id]);
                }
                if ($shortcode_id == 'sb-human-takeover' && defined('SB_DIALOGFLOW')) sb_dialogflow_set_active_context('human-takeover', [], 2, false, sb_isset($extra, 'user_id'));
                break;
            case 'email':
                $facebook = ['quick_replies' => [['content_type' => 'user_email', 'payload' => $shortcode_id]]];
                if (sb_isset($shortcode, 'phone')) $extra_values = 'phone';
                break;
            case 'phone':
                $facebook = ['quick_replies' => [['content_type' => 'user_phone_number', 'payload' => $shortcode_id]]];
                break;
            case 'button':
                if ($message) {
                    $facebook = ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'button', 'text' => $message, 'buttons' => [['type' => 'web_url', 'url' => $shortcode['link'], 'title' => sb_($shortcode['name'])]]]]];
                    $message = '';
                } else {
                    $message = $shortcode['link'];
                }
                break;
            case 'video':
                $message = ($shortcode['type'] == 'youtube' ? 'https://www.youtube.com/embed/' : 'https://player.vimeo.com/video/') . $shortcode['id'];
                break;
            case 'image':
                $extra_values = ['attachments' => [[$shortcode['url'], $shortcode['url']]]];
                $facebook = false;
                $message = false;
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
            case 'rating' :
                $facebook = ['attachment' => ['type' => 'template', 'payload' => ['template_type' => 'button', 'text' => $message, 'buttons' => [['type' => 'postback', 'title' => sb_($shortcode['label-positive']), 'payload' => 'rating-positive'], ['type' => 'postback', 'title' => sb_($shortcode['label-negative']), 'payload' => 'rating-negative']]]]];
                if (defined('SB_DIALOGFLOW')) sb_dialogflow_set_active_context('rating', [], 2, false, sb_isset($extra, 'user_id'));
                $message = '';
                break;
            default:
                $facebook = false;
                $message = '';
        }
    }
    return [$message, $facebook, $extra_values];
}

/*
 * -----------------------------------------------------------
 * ADD FACEBOOK USER TO SUPPORT BOARD
 * -----------------------------------------------------------
 *
 * Get the details of a Facebook user and add it to Support Board
 *
 */

function sb_messenger_add_user($page_id, $user_id, $user_type = 'lead', $token = false) {
    if (!$token) {
        $facebook_pages = sb_get_setting('messenger-pages', []);
        for ($i = 0; $i < count($facebook_pages); $i++) {
            if ($facebook_pages[$i]['messenger-page-id'] == $page_id) {
                $token = $facebook_pages[$i]['messenger-page-token'];
                $user_details = json_decode(sb_get('https://graph.facebook.com/' . $user_id . '?fields=first_name,last_name&access_token=' . $token), true);
                $profile_image = json_decode(sb_get('https://graph.facebook.com/' . $user_id . '/picture?redirect=false&width=600&height=600&access_token=' . $token), true);
                if (isset($profile_image['data']) && isset($profile_image['data']['url'])) {
                    $profile_image = sb_download_file($profile_image['data']['url'], $user_id . '.jpg');
                    $user_details['profile_image'] = sb_is_error($profile_image) || empty($profile_image) ? '' : $profile_image;
                }
                $user_details['user_type'] = $user_type;
                return sb_add_user($user_details, ['facebook-id' => [$user_id, 'Facebook ID']]);
            }
        }
    }
    return false;
}

/*
 * -----------------------------------------------------------
 * LISTENER
 * -----------------------------------------------------------
 *
 * Receive and process the messages from Facebook Messenger forwarded by board.support
 *
 */

function sb_messenger_listener($response) {
    $message = false;
    $attachments = [];
    $sender_id = false;
    $department = -1;
    $page_id = false;
    $response_messaging = isset($response['messaging']) ? $response['messaging'] : (isset($response['object']) && $response['object'] == 'page' && isset($response['entry'][0]['messaging']) ? $response['entry'][0]['messaging'] : false);
    $response_message = $response_messaging && isset($response_messaging[0]['message']) ? $response_messaging[0]['message'] : [];
    $is_echo = isset($response_message['is_echo']);
    $postback = sb_isset($response_messaging, 'postback');
    $user = false;

    if ($response_message) {
        $sender_id = $response_messaging[0]['sender']['id'];
        $message = sb_isset($response_message, 'text');
        $attachments = sb_isset($response_message, 'attachments', []);
    } else if (isset($response['sender'])) {
        $sender_id = $response['sender']['id'];
        $message = sb_isset($response['message'], 'text');
        $attachments = sb_isset($response['attachments'], 'attachments', []);
    } else if ($postback) {
        $sender_id = $response_messaging[0]['sender']['id'];
        $message = sb_isset($postback, 'title', '');
    }

    if ($sender_id && ($message || $attachments)) {
        $GLOBALS['SB_FORCE_ADMIN'] = true;

        // Page ID
        $page_sender = false;
        if (isset($response['object']) && $response['object'] == 'page' && isset($response['entry'])) {
            $page_id = $response['entry'][0]['id'];
        } else if (isset($response['recipient'])) {
            $page_id = $response['recipient']['id'];
        } else if ($response_messaging) {
            $page_id = $response_messaging[0]['recipient']['id'];
        }
        if ($page_id == $sender_id) {
            $page_id = $sender_id;
            $sender_id = $response_messaging[0]['recipient']['id'];
            $page_sender = sb_db_get('SELECT id FROM sb_users WHERE user_type = "agent" OR user_type = "admin" ORDER BY user_type, creation_time LIMIT 1')['id'];
        }

        // User
        $user = sb_db_get('SELECT A.id, A.first_name, A.last_name, A.profile_image, A.email, A.user_type FROM sb_users A, sb_users_data B WHERE A.user_type <> "agent" AND A.user_type <> "admin" AND A.id = B.user_id AND B.slug = "facebook-id" AND B.value = "' . sb_db_escape($sender_id) . '" LIMIT 1');
        if (!$user) {
            $user_id = sb_messenger_add_user($page_id, $sender_id);
            $user = sb_get_user($user_id);
        } else $user_id = $user['id'];

        if ($user_id) {

            // Get user and conversation information
            $GLOBALS['SB_LOGIN'] = $user;
            $conversation = sb_db_get('SELECT id, status_code FROM sb_conversations WHERE source = "fb" AND user_id = ' . $user_id . ' LIMIT 1');
            $conversation_id = sb_isset($conversation, 'id');

            if (!$conversation_id) {
                $facebook_pages = sb_get_setting('messenger-pages', []);
                for ($i = 0; $i < count($facebook_pages); $i++) {
                    if ($facebook_pages[$i]['messenger-page-id'] == $page_id) {
                        $department = sb_isset($facebook_pages[$i], 'messenger-page-department', -1);
                    }
                }
                $conversation_id = sb_isset(sb_new_conversation($user_id, 2, '', $department, -1, 'fb', $page_id), 'details', [])['id'];
            } else if ($is_echo && $page_sender && $response_message && isset($response_message['metadata']) && sb_isset(sb_db_get('SELECT COUNT(*) AS `count` FROM sb_messages WHERE id = ' . explode('|', $response_message['metadata'])[0]), 'count') != 0) {
                $GLOBALS['SB_FORCE_ADMIN'] = false;
                return false;
            }

            // Attachments
            $attachments_2 = [];
            for ($i = 0; $i < count($attachments); $i++) {
                if ($attachments[$i]['type'] == 'image' && sb_isset($attachments[$i]['payload'], 'sticker_id') == '369239263222822' && $message == '') {
                    $message = "👍";
                } else {
                    $url = sb_isset($attachments[$i]['payload'], 'url');
                    if ($url) {
                        array_push($attachments_2, [basename(strpos($url, '?') ? substr($url, 0, strpos($url, '?')) : $url), sb_download_file($url)]);
                    } else if ($attachments[$i]['type'] == 'fallback') {
                        $message_id = sb_isset($response, 'id', $response['entry'][0]['id']);
                        $message .= sb_('Attachment unavailable.') . ($message_id ? ' ' . sb_('View it on Messenger.') . PHP_EOL . 'https://www.facebook.com/messages/t/' . $message_id : '');
                    }
                }
            }

            // Send message
            $response = sb_send_message($page_sender ? $page_sender : $user_id, $conversation_id, $message, $attachments_2);

            // Dialogflow and bot messages
            sb_messaging_platforms_functions($conversation_id, $message, $attachments_2, $user,  ['source' => 'fb', 'psid' => $sender_id, 'page_id' => $page_id]);
        }
    }

    $GLOBALS['SB_FORCE_ADMIN'] = false;
    return $response;
}

?>