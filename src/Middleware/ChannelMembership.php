<?php
namespace Middleware;

use Services\TelegramService;

class ChannelMembership {
    private $telegram;
    private $channelId;

    public function __construct(TelegramService $telegram, $channelId) {
        $this->telegram = $telegram;
        $this->channelId = $channelId;
    }

    public function check($chat_id) {
        if (empty($this->channelId)) {
            return true;
        }

        $result = $this->telegram->getChatMember($this->channelId, $chat_id);

        if (isset($result['ok']) && $result['ok']) {
            $status = $result['result']['status'];
            $isMember = in_array($status, ['member', 'administrator', 'creator']);

            if ($isMember) {
                return true;
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ عضویت در کانال', 'url' => $this->channelId],
                    ['text' => '🔄 بررسی مجدد', 'callback_data' => 'check_membership']
                ]
            ]
        ];

        $this->telegram->sendMessage($chat_id,
            "❌ <b>برای استفاده از ربات، ابتدا در کانال زیر عضو شوید:</b>\n\n"
            . "🔗 {$this->channelId}\n\n"
            . "✅ بعد از عضویت، روی دکمه «بررسی مجدد» کلیک کنید.",
            $keyboard
        );

        return false;
    }

    public function checkMembershipWithMessage($chat_id, $customMessage = null) {
        if (empty($this->channelId)) {
            return true;
        }

        $result = $this->telegram->getChatMember($this->channelId, $chat_id);

        if (isset($result['ok']) && $result['ok']) {
            $status = $result['result']['status'];
            $isMember = in_array($status, ['member', 'administrator', 'creator']);

            if ($isMember) {
                return true;
            }
        }

        $message = $customMessage ?? "❌ برای ادامه، ابتدا در کانال ما عضو شوید:\n\n🔗 {$this->channelId}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📢 عضویت در کانال', 'url' => $this->channelId],
                    ['text' => '🔄 تایید عضویت', 'callback_data' => 'check_membership']
                ]
            ]
        ];

        $this->telegram->sendMessage($chat_id, $message, $keyboard);

        return false;
    }

    public function getChannelInfo() {
        if (empty($this->channelId)) {
            return null;
        }

        $result = $this->telegram->getChat($this->channelId);

        if (isset($result['ok']) && $result['ok']) {
            return [
                'id' => $result['result']['id'],
                'title' => $result['result']['title'],
                'username' => $result['result']['username'],
                'member_count' => $result['result']['members_count'] ?? null,
                'description' => $result['result']['description'] ?? null
            ];
        }

        return null;
    }

    public function getUserStatus($chat_id) {
        if (empty($this->channelId)) {
            return 'no_channel';
        }

        $result = $this->telegram->getChatMember($this->channelId, $chat_id);

        if (isset($result['ok']) && $result['ok']) {
            return $result['result']['status'];
        }

        return 'unknown';
    }

    public function isAdminInChannel($chat_id) {
        if (empty($this->channelId)) {
            return false;
        }

        $result = $this->telegram->getChatMember($this->channelId, $chat_id);

        if (isset($result['ok']) && $result['ok']) {
            $status = $result['result']['status'];
            return in_array($status, ['administrator', 'creator']);
        }

        return false;
    }

    public function handleCallback($callback) {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];
        $callback_id = $callback['id'];

        if ($data == 'check_membership') {
            $isMember = $this->check($chat_id);

            if ($isMember) {
                $this->telegram->sendMessage($chat_id, "✅ عضویت شما تأیید شد! حالا می‌توانید از ربات استفاده کنید.");
                $this->telegram->answerCallbackQuery($callback_id, "✅ عضویت شما تأیید شد!");
            } else {
                $this->telegram->answerCallbackQuery($callback_id, "❌ شما هنوز عضو کانال نشده‌اید!", true);
            }

            return true;
        }

        return false;
    }

    public function getJoinLink() {
        if (empty($this->channelId)) {
            return null;
        }

        if (strpos($this->channelId, '@') === 0) {
            return "https://t.me/" . ltrim($this->channelId, '@');
        }

        return $this->channelId;
    }
}