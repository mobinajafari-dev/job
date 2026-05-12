<?php
namespace Core;

use Controllers\BotController;
use Controllers\AdminController;
use Controllers\PaymentController;
use Controllers\TicketController;
use Controllers\ChannelController;
use Services\TelegramService;
use Helpers\Logger;

class Router {
    private $botController;
    private $adminController;
    private $paymentController;
    private $ticketController;
    private $channelController;
    private $telegram;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->botController = new BotController();
        $this->adminController = new AdminController();
        $this->paymentController = new PaymentController();
        $this->ticketController = new TicketController();
        $this->channelController = new ChannelController();
    }

    /**
     * پردازش درخواست ورودی از تلگرام
     */
    public function process($update) {
        try {
            $this->logRequest($update);

            if (isset($update['message'])) {
                $this->processMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->processCallback($update['callback_query']);
            } elseif (isset($update['inline_query'])) {
                $this->processInlineQuery($update['inline_query']);
            }

        } catch (\Exception $e) {
            Logger::error("Router error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            $this->handleError($update, $e);
        }
    }

    /**
     * پردازش پیام‌های معمولی
     */
    private function processMessage($message) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';

        Logger::info("Message from {$chat_id}: {$text}");

        // 1. اگر کاربر شماره فرستاده
        if (isset($message['contact'])) {
            $this->botController->handleMessage($message);
            return;
        }

        // 2. بررسی دستورات ادمین
        if (strpos($text, '/admin') === 0) {
            $this->adminController->handle(['message' => $message]);
            return;
        }

        // 3. بررسی دستور پرداخت
        if (strpos($text, '/pay') === 0) {
            $this->paymentController->handle(['message' => $message]);
            return;
        }

        // 4. دستور start (با کد معرف)
        if ($text == '/start' || strpos($text, '/start ') === 0) {
            $this->handleStartCommand($message);
            return;
        }

        // 5. بقیه پیام‌ها - ارسال به کنترلر اصلی
        $this->botController->handleMessage($message);
    }

    /**
     * پردازش دکمه‌های شیشه‌ای (Callback)
     */
    private function processCallback($callback) {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];
        $callback_id = $callback['id'];

        $this->telegram->answerCallbackQuery($callback_id);
        Logger::info("Callback from {$chat_id}: {$data}");

        // کپی لینک دعوت
        if (strpos($data, 'copy_link_') === 0) {
            $referral_code = str_replace('copy_link_', '', $data);
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $referral_code;
            $this->telegram->answerCallbackQuery($callback_id, "✅ لینک کپی شد!", true);
            $this->telegram->sendMessage($chat_id, "🔗 لینک دعوت شما:\n<code>{$link}</code>");
            return;
        }

        // بازگشت به منوی اصلی
        if ($data == 'back_to_menu') {
            $this->botController->backToMenu($chat_id);
            return;
        }




// بررسی عضویت در کانال
        if ($data == 'check_membership') {
            $this->botController->handleCheckMembership($callback);
            return;
        }

        // دکمه‌های مربوط به آگهی (تایید/رد توسط ادمین)
        if (strpos($data, 'approve_job_') === 0 || strpos($data, 'reject_job_') === 0) {
            $this->adminController->handle(['callback_query' => $callback]);
            return;
        }

        // دکمه‌های مربوط به تیکت
        if (strpos($data, 'reply_ticket_') === 0 || strpos($data, 'close_ticket_') === 0 || $data == 'admin_tickets_list') {
            $this->ticketController->handle(['callback_query' => $callback]);
            return;
        }

        // دکمه‌های مربوط به کانال (اتصال با کارفرما، بستن آگهی)
        if (strpos($data, 'connect_') === 0 || strpos($data, 'close_job_') === 0) {
            $this->channelController->handleCallback($callback);
            return;
        }

        // دکمه‌های منوی ادمین
        if (strpos($data, 'admin_') === 0) {
            $this->adminController->handle(['callback_query' => $callback]);
            return;
        }

        // دکمه‌های مربوط به پرداخت
        if ($data == 'cancel_payment') {
            $this->paymentController->handle(['callback_query' => $callback]);
            return;
        }

        // دکمه‌های مربوط به ارسال همگانی
        if (strpos($data, 'broadcast_') === 0) {
            $this->adminController->handle(['callback_query' => $callback]);
            return;
        }

        // سایر callbackها - ارسال به کنترلر اصلی
        $this->botController->handleCallback($callback);
    }

    /**
     * پردازش جستجوی درون خطی (Inline Query)
     */
    private function processInlineQuery($inline_query) {
        $query = $inline_query['query'];
        $results = [];

        if (mb_strlen($query) > 2) {
            $results = $this->botController->searchJobs($query);
        }

        $this->telegram->answerInlineQuery($inline_query['id'], $results);
    }

    /**
     * هندل کردن دستور /start با کد معرف
     */
    private function handleStartCommand($message) {
        $chat_id = $message['chat']['id'];
        $username = $message['chat']['username'] ?? '';
        $text = $message['text'] ?? '';

        $referral_code = null;
        if (strpos($text, ' ') !== false) {
            $parts = explode(' ', $text, 2);
            $referral_code = trim($parts[1]);
            if ($referral_code == 'start') {
                $referral_code = null;
            }
        }

        Logger::info("Start command from {$chat_id}, referral: " . ($referral_code ?? 'none'));
        $this->botController->handleStartCommand($chat_id, $username, $referral_code);
    }

    /**
     * ثبت لاگ درخواست
     */
    private function logRequest($update) {
        if (isset($update['message'])) {
            $chat_id = $update['message']['chat']['id'] ?? 'unknown';
            $text = $update['message']['text'] ?? '[no text]';
            Logger::info("Request: {$chat_id} -> {$text}");
        } elseif (isset($update['callback_query'])) {
            $chat_id = $update['callback_query']['message']['chat']['id'] ?? 'unknown';
            $data = $update['callback_query']['data'] ?? '[no data]';
            Logger::info("Callback: {$chat_id} -> {$data}");
        }
    }

    /**
     * مدیریت خطاها
     */
    private function handleError($update, $e) {
        $errorMessage = "⚠️ خطا در ربات:\n\n"
            . "Error: " . $e->getMessage() . "\n"
            . "Line: " . $e->getLine() . "\n"
            . "File: " . $e->getFile();

        if (defined('ADMIN_IDS') && !empty(ADMIN_IDS)) {
            foreach (ADMIN_IDS as $admin_id) {
                if ($admin_id) {
                    try {
                        $this->telegram->sendMessage($admin_id, $errorMessage);
                    } catch (\Exception $e2) {
                        Logger::error("Failed to send error to admin: " . $e2->getMessage());
                    }
                }
            }
        }

        if (isset($update['message']['chat']['id'])) {
            $this->telegram->sendMessage(
                $update['message']['chat']['id'],
                "❌ خطایی رخ داده است. لطفاً دقایقی دیگر تلاش کنید یا با پشتیبانی تماس بگیرید."
            );
        }

        Logger::error("Router error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    }

    public function handleStartFromRouter($chat_id, $username, $referral_code) {
        $message = [
            'chat' => ['id' => $chat_id, 'username' => $username],
            'text' => '/start' . ($referral_code ? " {$referral_code}" : '')
        ];
        $this->handleStartCommand($message);
    }

}