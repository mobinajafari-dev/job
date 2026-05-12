<?php
namespace Services;

class TelegramService {
    private $token;
    private $api_url;

    public function __construct($token) {
        $this->token = $token;
        $this->api_url = "https://api.telegram.org/bot{$token}/";
    }

    public function sendMessage($chat_id, $text, $keyboard = null) {
        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($keyboard) {
            $data['reply_markup'] = json_encode($keyboard);
        }

        return $this->call('sendMessage', $data);
    }

    public function requestContact($chat_id, $text) {
        $keyboard = [
            'keyboard' => [
                [[
                    'text' => '📱 ارسال شماره تلفن',
                    'request_contact' => true
                ]]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];

        return $this->sendMessage($chat_id, $text, $keyboard);
    }

    public function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
        $data = [
            'callback_query_id' => $callback_query_id
        ];

        if ($text) {
            $data['text'] = $text;
            $data['show_alert'] = $show_alert;
        }

        return $this->call('answerCallbackQuery', $data);
    }

    public function editMessageText($chat_id, $message_id, $text, $keyboard = null) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($keyboard) {
            $data['reply_markup'] = json_encode($keyboard);
        }

        return $this->call('editMessageText', $data);
    }

    public function deleteMessage($chat_id, $message_id) {
        $data = [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ];

        return $this->call('deleteMessage', $data);
    }

    public function sendJobToChannel($job_content, $job_id, $contact_id) {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_{$contact_id}"
                ],
                [
                    'text' => '❌ بستن آگهی',
                    'callback_data' => "close_job_{$job_id}"
                ]
            ]]
        ];

        $message = "📢 <b>آگهی استخدام</b>\n\n";
        $message .= $job_content . "\n\n";
        $message .= "———————————\n";
        $message .= "✅ <b>وضعیت: فعال</b>";

        return $this->sendMessage(CHANNEL_ID, $message, $keyboard);
    }

    public function editJobInChannel($channel_message_id, $job_content, $job_id, $contact_id) {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_{$contact_id}"
                ],
                [
                    'text' => '❌ بستن آگهی',
                    'callback_data' => "close_job_{$job_id}"
                ]
            ]]
        ];

        $message = "📢 <b>آگهی استخدام</b>\n\n";
        $message .= $job_content . "\n\n";
        $message .= "———————————\n";
        $message .= "✅ <b>وضعیت: فعال</b>";

        return $this->editMessageText(CHANNEL_ID, $channel_message_id, $message, $keyboard);
    }

    public function closeJobInChannel($channel_message_id, $job_content, $job_id, $contact_id) {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_{$contact_id}"
                ],
                [
                    'text' => '✅ آگهی بسته شد',
                    'callback_data' => "job_closed_{$job_id}"
                ]
            ]]
        ];

        $message = "📢 <b>آگهی استخدام</b>\n\n";
        $message .= $job_content . "\n\n";
        $message .= "———————————\n";
        $message .= "🔴 <b>وضعیت: بسته شده</b>";

        return $this->editMessageText(CHANNEL_ID, $channel_message_id, $message, $keyboard);
    }

    public function checkUsernameValidity($username) {
        $username = ltrim($username, '@');

        try {
            $result = $this->call('getChat', ['chat_id' => "@{$username}"]);
            return isset($result['ok']) && $result['ok'];
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getChatMember($channel_id, $user_id) {
        $data = [
            'chat_id' => $channel_id,
            'user_id' => $user_id
        ];
        return $this->call('getChatMember', $data);
    }

    public function answerInlineQuery($inline_query_id, $results) {
        $data = [
            'inline_query_id' => $inline_query_id,
            'results' => json_encode($results)
        ];
        return $this->call('answerInlineQuery', $data);
    }

    public function sendInvoice($chat_id, $title, $description, $payload, $provider_token, $amount, $currency = 'IRT') {
        $data = [
            'chat_id' => $chat_id,
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'provider_token' => $provider_token,
            'amount' => $amount,
            'currency' => $currency
        ];
        return $this->call('sendInvoice', $data);
    }

    public function sendMessageToAdmins($admins, $message, $keyboard = null) {
        $results = [];
        foreach ($admins as $admin_id) {
            if ($admin_id) {
                $results[] = $this->sendMessage($admin_id, $message, $keyboard);
            }
        }
        return $results;
    }

    private function call($method, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $method);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Telegram API error: HTTP {$httpCode} - {$response}");
        }

        return json_decode($response, true);
    }

    public function getChat($chat_id) {
    $data = ['chat_id' => $chat_id];
    return $this->call('getChat', $data);
}
}