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

    public function sendJobToChannel($job_content, $job_id, $contact_id) {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_{$contact_id}"
                ]
            ]]
        ];

        $message = "📢 <b>آگهی استخدام</b>\n\n";
        $message .= $job_content . "\n\n";
        $message .= "———————————\n";
        $message .= "✅ <b>وضعیت: فعال</b>";

        return $this->sendMessage(CHANNEL_ID, $message, $keyboard);
    }

    public function checkUsernameValidity($username) {
        // حذف @ از ابتدای username
        $username = ltrim($username, '@');

        try {
            $result = $this->call('getChat', ['chat_id' => "@{$username}"]);
            return isset($result['ok']) && $result['ok'];
        } catch (\Exception $e) {
            return false;
        }
    }

    private function call($method, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url . $method);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    // در src/Services/TelegramService.php اضافه کن:

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
// در src/Services/TelegramService.php اضافه کن:

/**
 * پاسخ به Callback Query (برای دکمه‌های اینلاین)
 */
public function answerCallbackQuery($callback_query_id, $text = null, $show_alert = false) {
    $data = [
        'callback_query_id' => $callback_query_id
    ];
    
    if($text) {
        $data['text'] = $text;
        $data['show_alert'] = $show_alert;
    }
    
    return $this->call('answerCallbackQuery', $data);
}
}