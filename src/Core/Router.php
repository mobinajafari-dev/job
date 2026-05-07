<?php
namespace Core;

use Controllers\BotController;
use Controllers\AdminController;
use Controllers\PaymentController;
use Controllers\TicketController;
use Services\TelegramService;
use Helpers\Logger;

class Router {
    private $botController;
    private $adminController;
    private $paymentController;
    private $ticketController;
    private $telegram;

    public function __construct() {
        $this->botController = new BotController();
        $this->adminController = new AdminController();
        $this->paymentController = new PaymentController();
        $this->ticketController = new TicketController();
        $this->telegram = new TelegramService(BOT_TOKEN);
    }

    /**
     * پردازش درخواست ورودی از تلگرام
     */
    public function process($update) {
        try {
            // ثبت لاگ درخواست
            Logger::info("Received update: " . json_encode($update));

            // پردازش پیام‌ها
            if (isset($update['message'])) {
                $this->processMessage($update['message']);
            }

            // پردازش callback ها
            elseif (isset($update['callback_query'])) {
                $this->processCallback($update['callback_query']);
            }

            // پردازش inline query ها
            elseif (isset($update['inline_query'])) {
                $this->processInlineQuery($update['inline_query']);
            }

        } catch (\Exception $e) {
            Logger::error("Router error: " . $e->getMessage());
            $this->handleError($update, $e);
        }
    }

    private function processMessage($message) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';

        // مسیریابی بر اساس متن پیام
        if (strpos($text, '/admin') === 0) {
            $this->adminController->handle(['message' => $message]);
        }
        elseif (strpos($text, '/pay') === 0) {
            $this->paymentController->handle(['message' => $message]);
        }
        elseif ($text == '/start') {
            $this->handleStartCommand($message);
        }
        elseif (isset($message['contact'])) {
            $this->botController->handleContact($message);
        }
        else {
            // مسیریابی به کنترلر اصلی
            $this->botController->handle(['message' => $message]);
        }
    }

    private function processCallback($callback) {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];

        // مسیریابی دکمه‌های اینلاین
        if (strpos($data, 'approve_job_') === 0) {
            $job_id = str_replace('approve_job_', '', $data);
            $this->adminController->approveJob($chat_id, $job_id);
        }

        elseif (strpos($data, 'reject_job_') === 0) {
            $job_id = str_replace('reject_job_', '', $data);
            $this->adminController->rejectJob($chat_id, $job_id);
        }

        elseif (strpos($data, 'connect_') === 0) {
            $parts = explode('_', $data);
            $job_id = $parts[1] ?? 0;
            $contact_id = $parts[2] ?? '';
            $this->handleConnection($chat_id, $job_id, $contact_id);
        }

        elseif (strpos($data, 'reply_ticket_') === 0) {
            $ticket_id = str_replace('reply_ticket_', '', $data);
            $this->askForTicketReply($chat_id, $ticket_id);
        }

        elseif (strpos($data, 'close_ticket_') === 0) {
            $ticket_id = str_replace('close_ticket_', '', $data);
            $this->ticketController->closeTicket($chat_id, $ticket_id);
        }

        elseif (strpos($data, 'admin_') === 0) {
            $this->handleAdminCallbacks($chat_id, $data);
        }

        // پاسخ به callback
        $this->telegram->answerCallbackQuery($callback['id']);
    }

    private function processInlineQuery($inline_query) {
        $query = $inline_query['query'];
        $results = [];

        // جستجوی آگهی‌های فعال
        if (strlen($query) > 2) {
            $sql = "SELECT id, content FROM jobs 
                    WHERE status = 'active' AND content LIKE :query 
                    LIMIT 10";
            $jobs = Database::queryAll($sql, ['query' => "%{$query}%"]);

            foreach ($jobs as $job) {
                $results[] = [
                    'type' => 'article',
                    'id' => $job['id'],
                    'title' => 'آگهی استخدام',
                    'description' => mb_substr($job['content'], 0, 50),
                    'input_message_content' => [
                        'message_text' => $job['content']
                    ]
                ];
            }
        }

        $this->telegram->answerInlineQuery($inline_query['id'], $results);
    }

    private function handleStartCommand($message) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';

        // بررسی وجود کد معرف
        $parts = explode(' ', $text);
        $referral_code = $parts[1] ?? null;

        if ($referral_code && strpos($referral_code, 'start') === false) {
            $_SESSION['referral_code'] = $referral_code;
        }

        $this->botController->handleStart($chat_id);
    }

    private function handleConnection($chat_id, $job_id, $contact_id) {
        // بررسی معتبر بودن آگهی
        $sql = "SELECT status FROM jobs WHERE id = :job_id";
        $job = Database::query($sql, ['job_id' => $job_id]);

        if (!$job || $job['status'] != 'active') {
            $this->telegram->sendMessage($chat_id, "❌ این آگهی دیگر فعال نیست.");
            return;
        }

        // ایجاد لینک ارتباطی
        $contact_link = "https://t.me/{$contact_id}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📱 ارتباط با کارفرما', 'url' => $contact_link]
                ],
                [
                    ['text' => 'ℹ️ گزارش مشکل', 'callback_data' => "report_job_{$job_id}"]
                ]
            ]
        ];

        $message = "🔗 لینک ارتباطی کارفرما:\n\n";
        $message .= "برای ارتباط مستقیم روی دکمه زیر کلیک کنید:";

        $this->telegram->sendMessage($chat_id, $message, $keyboard);
    }

    private function handleAdminCallbacks($chat_id, $data) {
        switch ($data) {
            case 'admin_stats':
                $this->adminController->showStats($chat_id);
                break;
            case 'admin_pending':
                $this->adminController->showPendingJobs($chat_id);
                break;
            case 'admin_users':
                $this->adminController->showUsers($chat_id);
                break;
            case 'admin_transactions':
                $this->adminController->showTransactions($chat_id);
                break;
            case 'admin_financial':
                $this->adminController->showFinancialReport($chat_id);
                break;
            case 'admin_add_discount':
                $this->adminController->askForDiscountCode($chat_id);
                break;
        }
    }

    private function askForTicketReply($chat_id, $ticket_id) {
        // ذخیره وضعیت در session یا cache
        $_SESSION['replying_ticket'] = $ticket_id;

        $this->telegram->sendMessage($chat_id,
            "✏️ لطفاً پاسخ تیکت را وارد کنید:\n\n" .
            "برای لغو، /cancel را بفرستید."
        );
    }

    private function handleError($update, $e) {
        // ارسال خطا به ادمین
        foreach (ADMIN_IDS as $admin_id) {
            $this->telegram->sendMessage($admin_id,
                "⚠️ خطا در ربات:\n\n" .
                "Error: " . $e->getMessage() . "\n" .
                "File: " . $e->getFile() . "\n" .
                "Line: " . $e->getLine()
            );
        }
    }
}