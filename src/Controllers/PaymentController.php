<?php
namespace Controllers;

use Services\PaymentService;
use Models\User;
use Models\Transaction;
use Helpers\Logger;

class PaymentController {
    private $paymentService;
    private $userModel;

    public function __construct() {
        $this->paymentService = new PaymentService();
        $this->userModel = new User();
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

        // استخراج مبلغ از دستور /pay 10000
        $parts = explode(' ', $text);
        $amount = isset($parts[1]) ? (int) $parts[1] : 0;

        if ($amount < 10000) {
            $this->telegram->sendMessage($chat_id, "❌ حداقل مبلغ شارژ 10,000 تومان است.");
            return;
        }

        // دریافت کاربر
        $sql = "SELECT id FROM users WHERE telegram_id = :telegram_id";
        $user = Database::query($sql, ['telegram_id' => $chat_id]);

        if (!$user) {
            $this->telegram->sendMessage($chat_id, "❌ لطفاً ابتدا ربات را استارت کنید.");
            return;
        }

        // ایجاد درخواست پرداخت
        $result = $this->paymentService->createPaymentRequest($user['id'], $amount);

        if ($result['success']) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💳 پرداخت آنلاین', 'url' => $result['payment_url']]
                    ],
                    [
                        ['text' => '❌ انصراف', 'callback_data' => 'cancel_payment']
                    ]
                ]
            ];

            $message_text = "💰 درخواست شارژ کیف پول\n\n";
            $message_text .= "مبلغ: " . number_format($amount) . " تومان\n";
            $message_text .= "برای پرداخت روی دکمه زیر کلیک کنید:";

            $this->telegram->sendMessage($chat_id, $message_text, $keyboard);
        } else {
            $this->telegram->sendMessage($chat_id,
                "❌ خطا در ایجاد درخواست پرداخت. لطفاً مجدداً تلاش کنید.\n\n" .
                "خطا: " . $result['error']
            );
        }
    }

    public function verifyCallback() {
        // دریافت پارامترها از回调 Zarinpal
        $authority = $_GET['Authority'] ?? '';
        $status = $_GET['Status'] ?? '';
        $transaction_id = $_GET['transaction_id'] ?? 0;

        if (!$authority || !$transaction_id) {
            return $this->redirectToBot("پارامترهای پرداخت معتبر نیست.");
        }

        // تایید پرداخت
        $result = $this->paymentService->verifyPayment($transaction_id, $authority, $status);

        if ($result['success']) {
            // دریافت اطلاعات تراکنش
            $sql = "SELECT user_id FROM transactions WHERE id = :transaction_id";
            $transaction = Database::query($sql, ['transaction_id' => $transaction_id]);

            if ($transaction) {
                $sql = "SELECT telegram_id FROM users WHERE id = :user_id";
                $user = Database::query($sql, ['user_id' => $transaction['user_id']]);

                if ($user) {
                    $message = "✅ پرداخت با موفقیت انجام شد.\n\n";
                    $message .= "مبلغ شارژ: " . number_format($result['amount']) . " تومان\n";
                    $message .= "شماره پیگیری: {$result['ref_id']}\n\n";
                    $message .= "💰 موجودی کیف پول شما بروزرسانی شد.";

                    $this->telegram->sendMessage($user['telegram_id'], $message);
                }
            }

            return $this->redirectToBot("پرداخت شما با موفقیت انجام شد.");
        } else {
            return $this->redirectToBot($result['message']);
        }
    }

    private function redirectToBot($message) {
        // ارسال کاربر به ربات
        $bot_username = BOT_USERNAME;
        $text = urlencode($message);
        header("Location: https://t.me/{$bot_username}?start={$text}");
        exit;
    }

    private function handleCallback($callback) {
        $data = $callback['data'];
        $chat_id = $callback['message']['chat']['id'];

        if ($data == 'cancel_payment') {
            $this->telegram->sendMessage($chat_id, "❌ عملیات پرداخت لغو شد.");
            $this->telegram->answerCallbackQuery($callback['id']);
        }
    }
}