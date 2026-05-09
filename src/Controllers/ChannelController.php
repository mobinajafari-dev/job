<?php
namespace Controllers;

use Services\ChannelService;
use Services\TelegramService;
use Helpers\Logger;

class ChannelController {
    private $telegram;
    private $channelService;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->channelService = new ChannelService($this->telegram);
    }

    public function handleCallback($callback) {
        $data = $callback['data'];
        $callback_id = $callback['id'];

        if (strpos($data, 'connect_') === 0) {
            $parts = explode('_', $data);
            $job_id = $parts[1] ?? 0;
            $contact_id = urldecode($parts[2] ?? '');
            $this->handleConnection($callback['message']['chat']['id'], $job_id, $contact_id);
            $this->telegram->answerCallbackQuery($callback_id);
        } elseif (strpos($data, 'close_job_') === 0) {
            $job_id = str_replace('close_job_', '', $data);
            $this->handleCloseJob($callback['message']['chat']['id'], $job_id);
            $this->telegram->answerCallbackQuery($callback_id, "✅ آگهی بسته شد");
        }
    }

    private function handleConnection($chat_id, $job_id, $contact_id) {
        $contact_link = "https://t.me/" . ltrim($contact_id, '@');

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📱 ارتباط با کارفرما', 'url' => $contact_link]
                ]
            ]
        ];

        $message = "🔗 لینک ارتباطی کارفرما:\n\nبرای ارتباط مستقیم روی دکمه زیر کلیک کنید:";
        $this->telegram->sendMessage($chat_id, $message, $keyboard);
    }

    private function handleCloseJob($chat_id, $job_id) {
        $jobService = new \Services\JobService($this->telegram, new \Services\WalletService($this->telegram));
        $result = $jobService->closeJob($chat_id, $job_id);

        if ($result) {
            $this->telegram->sendMessage($chat_id, "✅ آگهی شما با موفقیت بسته شد.");
        } else {
            $this->telegram->sendMessage($chat_id, "❌ خطا در بستن آگهی.");
        }
    }

    public function publishJob($job_id, $content, $contact_id) {
        return $this->channelService->publishJob($content, $job_id, $contact_id);
    }

    public function closeJobInChannel($channel_message_id, $content, $job_id, $contact_id) {
        return $this->channelService->closeJob($channel_message_id, $content, $job_id, $contact_id);
    }
}