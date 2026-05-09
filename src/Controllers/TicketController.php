<?php
namespace Controllers;

use Services\TicketService;
use Services\TelegramService;
use Helpers\Logger;

class TicketController {
    private $telegram;
    private $ticketService;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->ticketService = new TicketService($this->telegram);
    }

    public function handle($update) {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    public function handleCallback($callback) {
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        $callback_id = $callback['id'];

        $this->telegram->answerCallbackQuery($callback_id, 'در حال پردازش...');

        if (strpos($data, 'reply_ticket_') === 0) {
            $ticket_id = str_replace('reply_ticket_', '', $data);
            $_SESSION['replying_ticket'] = $ticket_id;
            $this->telegram->sendMessage($chat_id, "✏️ لطفاً پاسخ تیکت را وارد کنید:\n\nبرای لغو، /cancel را بفرستید.");
        } elseif (strpos($data, 'close_ticket_') === 0) {
            $ticket_id = str_replace('close_ticket_', '', $data);
            $this->ticketService->closeTicket($chat_id, $ticket_id);
        } elseif ($data == 'admin_tickets_list') {
            $this->ticketService->getOpenTickets($chat_id);
        }
    }

    public function handleMessage($chat_id, $text, $user_id) {
        if (isset($_SESSION['replying_ticket'])) {
            $ticket_id = $_SESSION['replying_ticket'];
            $this->ticketService->replyToTicket($chat_id, $ticket_id, $text);
            unset($_SESSION['replying_ticket']);
            return true;
        }
        return false;
    }
}