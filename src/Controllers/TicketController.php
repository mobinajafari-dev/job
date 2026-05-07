<?php
namespace Controllers;

use Models\Ticket;
use Models\User;
use Services\TelegramService;
use Helpers\Logger;

class TicketController {
    private $telegram;
    private $ticketModel;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->ticketModel = new Ticket();
    }

    public function createTicket($chat_id, $user_id, $subject, $message) {
        // ایجاد تیکت جدید
        $ticket_id = $this->ticketModel->create([
            'user_id' => $user_id,
            'subject' => $subject,
            'message' => $message,
            'status' => 'open'
        ]);

        // اطلاع به ادمین‌ها
        $this->notifyAdmins($ticket_id, $user_id, $subject, $message);

        // پاسخ به کاربر
        $response = "✅ تیکت شما با موفقیت ثبت شد.\n\n";
        $response .= "شماره تیکت: {$ticket_id}\n";
        $response .= "پشتیبان‌ها در اسرع وقت با شما تماس خواهند گرفت.";

        $this->telegram->sendMessage($chat_id, $response);

        return $ticket_id;
    }

    private function notifyAdmins($ticket_id, $user_id, $subject, $message) {
        // دریافت اطلاعات کاربر
        $sql = "SELECT username, telegram_id FROM users WHERE id = :user_id";
        $user = Database::query($sql, ['user_id' => $user_id]);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📞 پاسخ به تیکت', 'callback_data' => "reply_ticket_{$ticket_id}"],
                    ['text' => '✅ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]
                ]
            ]
        ];

        $admin_message = "🎫 <b>تیکت جدید</b>\n\n";
        $admin_message .= "<b>شماره تیکت:</b> {$ticket_id}\n";
        $admin_message .= "<b>کاربر:</b> @{$user['username']}\n";
        $admin_message .= "<b>موضوع:</b> {$subject}\n\n";
        $admin_message .= "<b>پیام:</b>\n{$message}";

        foreach (ADMIN_IDS as $admin_id) {
            $this->telegram->sendMessage($admin_id, $admin_message, $keyboard);
        }
    }

    public function replyToTicket($admin_id, $ticket_id, $response_message) {
        // دریافت اطلاعات تیکت
        $sql = "SELECT t.*, u.telegram_id, u.username 
                FROM tickets t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = :ticket_id";
        $ticket = Database::query($sql, ['ticket_id' => $ticket_id]);

        if (!$ticket) {
            $this->telegram->sendMessage($admin_id, "❌ تیکت مورد نظر یافت نشد.");
            return;
        }

        // بروزرسانی تیکت
        $sql = "UPDATE tickets SET 
                admin_response = :response, 
                status = 'in_progress',
                updated_at = NOW() 
                WHERE id = :ticket_id";
        Database::execute($sql, [
            'response' => $response_message,
            'ticket_id' => $ticket_id
        ]);

        // ارسال پاسخ به کاربر
        $user_message = "📞 <b>پاسخ تیکت شماره {$ticket_id}</b>\n\n";
        $user_message .= "پشتیبان: {$response_message}\n\n";
        $user_message .= "برای پاسخ، روی دکمه زیر کلیک کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✉️ ارسال پاسخ جدید', 'callback_data' => "ticket_reply_{$ticket_id}"]
                ]
            ]
        ];

        $this->telegram->sendMessage($ticket['telegram_id'], $user_message, $keyboard);
        $this->telegram->sendMessage($admin_id, "✅ پاسخ شما به کاربر ارسال شد.");
    }

    public function closeTicket($admin_id, $ticket_id) {
        $sql = "UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = :ticket_id";
        Database::execute($sql, ['ticket_id' => $ticket_id]);

        // اطلاع به کاربر
        $sql = "SELECT u.telegram_id FROM tickets t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = :ticket_id";
        $ticket = Database::query($sql, ['ticket_id' => $ticket_id]);

        if ($ticket) {
            $this->telegram->sendMessage($ticket['telegram_id'],
                "✅ تیکت شماره {$ticket_id} بسته شد.\nدر صورت نیاز می‌توانید تیکت جدیدی ثبت کنید."
            );
        }

        $this->telegram->sendMessage($admin_id, "✅ تیکت شماره {$ticket_id} بسته شد.");
    }

    public function getUserTickets($chat_id, $user_id) {
        $sql = "SELECT * FROM tickets WHERE user_id = :user_id ORDER BY created_at DESC";
        $tickets = Database::queryAll($sql, ['user_id' => $user_id]);

        if (empty($tickets)) {
            $this->telegram->sendMessage($chat_id, "📭 شما هیچ تیکتی ثبت نکرده‌اید.");
            return;
        }

        $message = "📋 <b>لیست تیکت‌های شما</b>\n\n";
        foreach ($tickets as $ticket) {
            $status_emoji = [
                'open' => '🟢',
                'in_progress' => '🟡',
                'closed' => '🔴'
            ];

            $message .= "{$status_emoji[$ticket['status']]} ";
            $message .= "<b>تیکت #{$ticket['id']}</b>\n";
            $message .= "موضوع: {$ticket['subject']}\n";
            $message .= "تاریخ: {$ticket['created_at']}\n";
            $message .= "———————————\n";
        }

        $this->telegram->sendMessage($chat_id, $message);
    }
}