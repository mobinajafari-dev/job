<?php
namespace Services;

use Models\User;
use Models\Job;
use Helpers\Logger;

class ChannelService {
    private $telegram;
    private $channelId;

    public function __construct($telegramService) {
        $this->telegram = $telegramService;
        $this->channelId = CHANNEL_ID;
    }

    public function checkMembership($chat_id) {
        $result = $this->telegram->getChatMember($this->channelId, $chat_id);

        if (isset($result['ok']) && $result['ok']) {
            $status = $result['result']['status'];
            $isMember = in_array($status, ['member', 'administrator', 'creator']);

            if ($isMember) {
                return true;
            }
        }

        $this->telegram->sendMessage($chat_id,
            "❌ برای استفاده از ربات، ابتدا در کانال زیر عضو شوید:\n\n"
            . "🔗 {$this->channelId}\n\n"
            . "✅ بعد از عضویت، دوباره /start را بزنید."
        );

        return false;
    }

    public function publishJob($job_content, $job_id, $contact_id) {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_" . urlencode($contact_id)
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

        $result = $this->telegram->sendMessage($this->channelId, $message, $keyboard);

        if (isset($result['ok']) && $result['ok'] && isset($result['result']['message_id'])) {
            return $result['result']['message_id'];
        }

        Logger::error("Failed to publish job to channel: " . json_encode($result));
        return false;
    }

    public function closeJob($channel_message_id, $job_content, $job_id, $contact_id) {
        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_" . urlencode($contact_id)
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

        $result = $this->telegram->editMessageText($this->channelId, $channel_message_id, $message, $keyboard);

        return isset($result['ok']) && $result['ok'];
    }

    public function updateJobStatus($channel_message_id, $job_content, $job_id, $contact_id, $status) {
        if ($status == 'closed') {
            return $this->closeJob($channel_message_id, $job_content, $job_id, $contact_id);
        }

        $statusText = [
            'active' => '✅ فعال',
            'expired' => '🔴 منقضی شده',
            'pending' => '🟡 در انتظار تایید'
        ];

        $statusIcon = $statusText[$status] ?? '⚪ ' . $status;

        $keyboard = [
            'inline_keyboard' => [[
                [
                    'text' => '🔗 ارتباط با کارفرما',
                    'callback_data' => "connect_{$job_id}_" . urlencode($contact_id)
                ]
            ]]
        ];

        if ($status == 'active') {
            $keyboard['inline_keyboard'][0][] = [
                'text' => '❌ بستن آگهی',
                'callback_data' => "close_job_{$job_id}"
            ];
        }

        $message = "📢 <b>آگهی استخدام</b>\n\n";
        $message .= $job_content . "\n\n";
        $message .= "———————————\n";
        $message .= "📌 <b>وضعیت: {$statusIcon}</b>";

        $result = $this->telegram->editMessageText($this->channelId, $channel_message_id, $message, $keyboard);

        return isset($result['ok']) && $result['ok'];
    }

    public function getChannelInfo() {
        $result = $this->telegram->call('getChat', ['chat_id' => $this->channelId]);

        if (isset($result['ok']) && $result['ok']) {
            return [
                'id' => $result['result']['id'],
                'title' => $result['result']['title'],
                'username' => $result['result']['username'],
                'member_count' => $result['result']['members_count'] ?? null
            ];
        }

        return null;
    }

    public function sendMessageToChannel($message, $keyboard = null) {
        return $this->telegram->sendMessage($this->channelId, $message, $keyboard);
    }

    public function deleteJobFromChannel($channel_message_id) {
        $result = $this->telegram->deleteMessage($this->channelId, $channel_message_id);
        return isset($result['ok']) && $result['ok'];
    }
}
