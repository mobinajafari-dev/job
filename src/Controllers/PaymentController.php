<?php
namespace Controllers;

use Services\PaymentService;
use Services\TelegramService;
use Helpers\Logger;

class PaymentController {
    private $telegram;
    private $paymentService;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->paymentService = new PaymentService($this->telegram);
    }

    public function handle($update) {
        if (isset($update['message'])) {
            $text = $update['message']['text'] ?? '';
            if (strpos($text, '/pay') === 0) {
                $this->handlePaymentCommand($update['message']);
            }
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    private function handlePaymentCommand($message) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'];

        $parts = explode(' ', $text);
        $amount = isset($parts[1]) ? (int) $parts[1] : 0;

        $this->paymentService->handlePaymentCommand($chat_id, $amount);
    }

    public function verifyCallback() {
        $authority = $_GET['Authority'] ?? '';
        $status = $_GET['Status'] ?? '';
        $transaction_id = $_GET['transaction_id'] ?? 0;

        if (!$authority || !$transaction_id) {
            return $this->redirectToBot("پارامترهای پرداخت معتبر نیست.");
        }

        $result = $this->paymentService->verifyPayment($transaction_id, $authority, $status);

        if ($result['success']) {
            return $this->redirectToBot("پرداخت شما با موفقیت انجام شد. شماره پیگیری: " . $result['ref_id']);
        } else {
            return $this->redirectToBot($result['message']);
        }
    }

    private function redirectToBot($message) {
        $bot_username = BOT_USERNAME;
        $text = urlencode($message);
        header("Location: https://t.me/{$bot_username}?start={$text}");
        exit;
    }

    private function handleCallback($callback) {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];
        $callback_id = $callback['id'];

        if ($data == 'cancel_payment') {
            $this->paymentService->cancelPayment($chat_id);
            $this->telegram->answerCallbackQuery($callback_id);
        }
    }
}