<?php
namespace Services;

use Models\User;
use Models\Ticket;
use Helpers\Logger;

class TicketService {
    private $ticketModel;
    private $userModel;
    private $telegram;

    public function __construct($telegramService) {
        $this->ticketModel = new Ticket();
        $this->userModel = new User();
        $this->telegram = $telegramService;
    }

    public function startTicket($chat_id) {
        $_SESSION['user_state'][$chat_id] = 'waiting_ticket';
        $this->telegram->sendMessage($chat_id, "📞 لطفاً مشکل خود را بنویسید:");
    }

    public function createTicket($chat_id, $message) {
        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            $this->telegram->sendMessage($chat_id, "❌ کاربر یافت نشد. لطفاً /start را بزنید.");
            return false;
        }

        $subject = mb_substr($message, 0, 50);
        $ticket_id = $this->ticketModel->createTicket($user['id'], $subject, $message);

        if ($ticket_id) {
            $this->telegram->sendMessage($chat_id, "✅ تیکت شما با موفقیت ثبت شد.\n\n📋 شماره تیکت: {$ticket_id}\n\nپشتیبان‌ها در اسرع وقت با شما تماس خواهند گرفت.");

            $this->notifyAdmins($ticket_id, $user, $subject, $message);

            return true;
        } else {
            $this->telegram->sendMessage($chat_id, "❌ خطا در ثبت تیکت. لطفاً دوباره تلاش کنید.");
            return false;
        }
    }

    private function notifyAdmins($ticket_id, $user, $subject, $message) {
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📞 پاسخ به تیکت', 'callback_data' => "reply_ticket_{$ticket_id}"],
                    ['text' => '✅ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]
                ]
            ]
        ];

        $adminMessage = "🎫 <b>تیکت جدید</b>\n\n";
        $adminMessage .= "<b>شماره تیکت:</b> {$ticket_id}\n";
        $adminMessage .= "<b>کاربر:</b> @{$user['username']}\n";
        $adminMessage .= "<b>آیدی:</b> {$user['telegram_id']}\n";
        $adminMessage .= "<b>موضوع:</b> {$subject}\n\n";
        $adminMessage .= "<b>پیام:</b>\n{$message}";

        $this->telegram->sendMessageToAdmins(ADMIN_IDS, $adminMessage, $keyboard);
    }

    public function replyToTicket($admin_id, $ticket_id, $response_message) {
        $ticket = $this->ticketModel->findTicket($ticket_id);

        if (!$ticket) {
            $this->telegram->sendMessage($admin_id, "❌ تیکت مورد نظر یافت نشد.");
            return;
        }

        $admin = $this->userModel->findByTelegramId($admin_id);
        $admin_name = $admin ? $admin['username'] : 'ادمین';

        $result = $this->ticketModel->reply($ticket_id, $ticket['user_id'], $response_message);

        if ($result['status']) {
            $userMessage = "📞 <b>پاسخ تیکت شماره {$ticket_id}</b>\n\n";
            $userMessage .= "پاسخ از طرف پشتیبانی:\n";
            $userMessage .= "———————————\n";
            $userMessage .= "{$response_message}\n";
            $userMessage .= "———————————\n\n";
            $userMessage .= "✅ وضعیت تیکت: در حال بررسی\n\n";
            $userMessage .= "برای پاسخ بیشتر، روی دکمه زیر کلیک کنید:";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✉️ ارسال پاسخ جدید', 'callback_data' => "reply_ticket_{$ticket_id}"]
                    ],
                    [
                        ['text' => '🔙 بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]
                    ]
                ]
            ];

            $this->telegram->sendMessage($ticket['telegram_id'], $userMessage, $keyboard);
            $this->telegram->sendMessage($admin_id, "✅ پاسخ شما به کاربر ارسال شد.");

            Logger::info("Admin {$admin_name} replied to ticket #{$ticket_id}");
        } else {
            $this->telegram->sendMessage($admin_id, "❌ خطا در ارسال پاسخ.");
        }
    }

    public function closeTicket($admin_id, $ticket_id, $resolve_note = null) {
        $ticket = $this->ticketModel->findTicket($ticket_id);

        if (!$ticket) {
            $this->telegram->sendMessage($admin_id, "❌ تیکت مورد نظر یافت نشد.");
            return;
        }

        $result = $this->ticketModel->close($ticket_id, $resolve_note);

        if ($result['status']) {
            $message = "✅ تیکت شماره {$ticket_id} بسته شد.\n\n";
            if ($resolve_note) {
                $message .= "توضیحات: {$resolve_note}\n\n";
            }
            $message .= "در صورت نیاز می‌توانید تیکت جدیدی ثبت کنید.";

            $this->telegram->sendMessage($ticket['telegram_id'], $message);
            $this->telegram->sendMessage($admin_id, "✅ تیکت شماره {$ticket_id} بسته شد.");

            Logger::info("Ticket #{$ticket_id} closed by admin");
        } else {
            $this->telegram->sendMessage($admin_id, "❌ خطا در بستن تیکت.");
        }
    }

    public function getUserTickets($chat_id) {
        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            $this->telegram->sendMessage($chat_id, "❌ کاربر یافت نشد.");
            return;
        }

        $tickets = $this->ticketModel->getUserTickets($user['id']);

        if (empty($tickets)) {
            $this->telegram->sendMessage($chat_id, "📭 شما هیچ تیکتی ثبت نکرده‌اید.");
            return;
        }

        $message = "📋 <b>لیست تیکت‌های شما</b>\n\n";
        foreach ($tickets as $ticket) {
            $statusIcon = [
                'open' => '🟢',
                'in_progress' => '🟡',
                'closed' => '🔴'
            ];

            $statusText = [
                'open' => 'باز',
                'in_progress' => 'در حال بررسی',
                'closed' => 'بسته شده'
            ];

            $icon = $statusIcon[$ticket['status']] ?? '⚪';
            $text = $statusText[$ticket['status']] ?? $ticket['status'];

            $message .= "{$icon} <b>تیکت #{$ticket['id']}</b>\n";
            $message .= "موضوع: {$ticket['subject']}\n";
            $message .= "وضعیت: {$text}\n";
            $message .= "تاریخ: " . date('Y/m/d H:i', strtotime($ticket['created_at'])) . "\n";

            if ($ticket['admin_response']) {
                $message .= "———————————\n";
                $message .= "📌 آخرین پاسخ:\n{$ticket['admin_response']}\n";
            }

            $message .= "———————————\n";
        }

        $this->telegram->sendMessage($chat_id, $message);
    }

    public function getOpenTickets($admin_id) {
        $tickets = $this->ticketModel->getOpenTickets(50);

        if (empty($tickets)) {
            $this->telegram->sendMessage($admin_id, "📭 هیچ تیکت باز و در انتظار پاسخی وجود ندارد.");
            return;
        }

        $message = "🎫 <b>تیکت‌های در انتظار پاسخ</b>\n\n";
        foreach ($tickets as $ticket) {
            $message .= "<b>تیکت #{$ticket['id']}</b>\n";
            $message .= "👤 کاربر: @{$ticket['username']}\n";
            $message .= "📝 موضوع: {$ticket['subject']}\n";
            $message .= "📅 تاریخ: " . date('Y/m/d H:i', strtotime($ticket['created_at'])) . "\n";
            $message .= "———————————\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📞 مشاهده و پاسخ', 'callback_data' => 'admin_tickets_list']
                ]
            ]
        ];

        $this->telegram->sendMessage($admin_id, $message, $keyboard);
    }

    public function getTicketStats($admin_id) {
        $stats = $this->ticketModel->getStats();
        $avgResponseTime = $this->ticketModel->getAverageResponseTime();
        $categoryStats = $this->ticketModel->getCategoryStats();

        $message = "📊 <b>آمار تیکت‌ها</b>\n\n";
        $message .= "🟢 در انتظار پاسخ: {$stats['open']}\n";
        $message .= "🟡 در حال بررسی: {$stats['in_progress']}\n";
        $message .= "🔴 بسته شده: {$stats['closed']}\n";
        $message .= "✅ حل شده: {$stats['resolved']}\n";
        $message .= "📊 مجموع کل: {$stats['total']}\n\n";
        $message .= "⏱️ میانگین زمان پاسخ: {$avgResponseTime} ساعت\n\n";

        if ($categoryStats) {
            $message .= "📂 <b>دسته‌بندی:</b>\n";
            foreach ($categoryStats as $cat) {
                $message .= "• {$cat['category']}: {$cat['count']} تیکت\n";
            }
        }

        $this->telegram->sendMessage($admin_id, $message);
    }

    public function clearUserState($chat_id) {
        unset($_SESSION['user_state'][$chat_id]);
    }
}