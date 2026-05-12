<?php
namespace Middleware;

use Services\TelegramService;

class ChannelMembership {
    private $telegram;
    private $channelId;
    private $channelUsername;

    public function __construct(TelegramService $telegram, $channelId) {
        $this->telegram = $telegram;
        $this->channelId = $channelId;
        $this->channelUsername = ltrim($channelId, '@');
    }

    private function getJoinLink() {
        return 'https://t.me/' . $this->channelUsername;
    }

    private function getChatMemberStatus($chat_id) {
        if (empty($this->channelUsername)) {
            return null;
        }

        $result = $this->telegram->getChatMember('@' . $this->channelUsername, $chat_id);

        if (isset($result['ok']) && $result['ok']) {
            return $result['result']['status'];
        }
        return null;
    }

    public function isMember($chat_id) {
        $status = $this->getChatMemberStatus($chat_id);
        return in_array($status, ['member', 'administrator', 'creator']);
    }

    public function check($chat_id) {
        if (empty($this->channelUsername)) {
            return true;
        }

        if ($this->isMember($chat_id)) {
            return true;
        }

        $joinLink = $this->getJoinLink();

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '✅ عضویت در کانال', 'url' => $joinLink],
                ['text' => '🔄 بررسی مجدد', 'callback_data' => 'check_membership']
            ]]
        ];

        $this->telegram->sendMessage($chat_id,
            "❌ <b>برای استفاده از ربات، ابتدا در کانال زیر عضو شوید:</b>\n\n"
            . "🔗 {$joinLink}\n\n"
            . "✅ بعد از عضویت، روی دکمه «بررسی مجدد» کلیک کنید.",
            $keyboard
        );

        return false;
    }

    public function handleCallback($callback) {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];
        $callback_id = $callback['id'];
        $message_id = $callback['message']['message_id'];

        if ($data == 'check_membership') {
            if ($this->isMember($chat_id)) {
                $this->telegram->deleteMessage($chat_id, $message_id);

                $keyboard = [
                    'keyboard' => [
                        [['text' => '📝 ارسال آگهی'], ['text' => '💰 کیف پول من']],
                        [['text' => '🎁 کد تخفیف'], ['text' => '👥 دعوت از دوستان']],
                        [['text' => '📞 پشتیبانی'], ['text' => 'ℹ️ راهنما']]
                    ],
                    'resize_keyboard' => true
                ];

                $this->telegram->sendMessage($chat_id,
                    "✅ <b>عضویت شما تأیید شد!</b>\n\nبه ربات خوش آمدید. از منوی اصلی استفاده کنید.",
                    $keyboard
                );
                $this->telegram->answerCallbackQuery($callback_id, "✅ عضویت شما تأیید شد!");
            } else {
                $this->telegram->answerCallbackQuery($callback_id, "❌ شما هنوز عضو کانال نشده‌اید! لطفاً ابتدا عضو شوید.", true);
            }
            return true;
        }

        return false;
    }

    public function checkMembershipWithMessage($chat_id, $customMessage = null) {
        if (empty($this->channelUsername)) {
            return true;
        }

        if ($this->isMember($chat_id)) {
            return true;
        }

        $joinLink = $this->getJoinLink();
        $message = $customMessage ?? "❌ برای ادامه، ابتدا در کانال ما عضو شوید:\n\n🔗 {$joinLink}";

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '📢 عضویت در کانال', 'url' => $joinLink],
                ['text' => '🔄 تایید عضویت', 'callback_data' => 'check_membership']
            ]]
        ];

        $this->telegram->sendMessage($chat_id, $message, $keyboard);

        return false;
    }

    public function getChannelInfo() {
        if (empty($this->channelUsername)) {
            return null;
        }

        $result = $this->telegram->getChat('@' . $this->channelUsername);

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
        if (empty($this->channelUsername)) {
            return 'no_channel';
        }

        return $this->getChatMemberStatus($chat_id) ?? 'unknown';
    }

    public function isAdminInChannel($chat_id) {
        if (empty($this->channelUsername)) {
            return false;
        }

        $status = $this->getChatMemberStatus($chat_id);
        return in_array($status, ['administrator', 'creator']);
    }

    public function getJoinLinkPublic() {
        return $this->getJoinLink();
    }

}