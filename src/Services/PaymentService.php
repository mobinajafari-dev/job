<?php
namespace Services;

use Models\User;
use Models\Wallet;
use Models\Transaction;
use Helpers\Logger;
use Helpers\Validator;

class PaymentService {
    private $merchantId;
    private $zarinpalGateway = 'https://api.zarinpal.com/pg/v4/payment/request.json';
    private $zarinpalVerification = 'https://api.zarinpal.com/pg/v4/payment/verify.json';
    private $callbackUrl;
    private $userModel;
    private $walletModel;
    private $transactionModel;
    private $telegram;

    public function __construct($telegramService) {
        $this->merchantId = defined('ZARINPAL_MERCHANT_ID') ? ZARINPAL_MERCHANT_ID : '';
        $this->callbackUrl = defined('CALLBACK_URL') ? CALLBACK_URL : '';
        $this->userModel = new User();
        $this->walletModel = new Wallet();
        $this->transactionModel = new Transaction();
        $this->telegram = $telegramService;
    }

    public function handlePaymentCommand($chat_id, $amount) {
        if (!Validator::amount($amount)) {
            $this->telegram->sendMessage($chat_id, "❌ مبلغ باید بین 10,000 تا 5,000,000 تومان باشد.");
            return;
        }

        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            $this->telegram->sendMessage($chat_id, "❌ لطفاً ابتدا /start را بزنید.");
            return;
        }

        $result = $this->createPaymentRequest($user['id'], $amount);

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

            $message = "💰 درخواست شارژ کیف پول\n\n";
            $message .= "مبلغ: " . number_format($amount) . " تومان\n";
            $message .= "شماره پیگیری: {$result['authority']}\n\n";
            $message .= "برای پرداخت روی دکمه زیر کلیک کنید:";

            $this->telegram->sendMessage($chat_id, $message, $keyboard);
        } else {
            $this->telegram->sendMessage($chat_id, "❌ خطا در ایجاد درخواست پرداخت. لطفاً مجدداً تلاش کنید.\n\nخطا: " . $result['error']);
        }
    }

    public function createPaymentRequest($user_id, $amount, $description = "شارژ کیف پول") {
        $amount = (int) $amount;

        $transaction_id = $this->transactionModel->createTransaction([
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => Transaction::TYPE_DEPOSIT,
            'status' => Transaction::STATUS_PENDING,
            'description' => $description
        ]);

        if (!$transaction_id) {
            Logger::error("Failed to create transaction for user {$user_id}");
            return ['success' => false, 'error' => 'خطا در ثبت تراکنش'];
        }

        if (empty($this->merchantId)) {
            return $this->createTestPayment($transaction_id, $user_id, $amount);
        }

        $data = [
            'merchant_id' => $this->merchantId,
            'amount' => $amount,
            'callback_url' => $this->callbackUrl . "?transaction_id={$transaction_id}",
            'description' => $description,
            'metadata' => [
                'user_id' => $user_id,
                'transaction_id' => $transaction_id
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->zarinpalGateway);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error("Payment request HTTP error: {$httpCode}");
            return ['success' => false, 'error' => 'خطا در ارتباط با درگاه پرداخت'];
        }

        $result = json_decode($response, true);

        if (isset($result['data']['code']) && $result['data']['code'] == 100) {
            $this->transactionModel->updateTransaction($transaction_id, [
                'reference_id' => $result['data']['authority']
            ]);

            return [
                'success' => true,
                'payment_url' => "https://www.zarinpal.com/pg/StartPay/{$result['data']['authority']}",
                'authority' => $result['data']['authority'],
                'transaction_id' => $transaction_id
            ];
        }

        $errorMessage = $result['errors']['message'] ?? 'خطا در ایجاد درخواست پرداخت';
        Logger::error("Payment request failed: " . json_encode($result));

        $this->transactionModel->updateTransaction($transaction_id, [
            'status' => Transaction::STATUS_FAILED
        ]);

        return ['success' => false, 'error' => $errorMessage];
    }

    private function createTestPayment($transaction_id, $user_id, $amount) {
        return [
            'success' => true,
            'payment_url' => "https://example.com/payment?transaction_id={$transaction_id}&amount={$amount}",
            'authority' => 'TEST_' . $transaction_id,
            'transaction_id' => $transaction_id,
            'is_test' => true
        ];
    }

    public function verifyPayment($transaction_id, $authority, $status) {
        if ($status != 'OK') {
            $this->transactionModel->updateTransaction($transaction_id, [
                'status' => Transaction::STATUS_CANCELLED
            ]);
            return ['success' => false, 'message' => 'پرداخت توسط کاربر لغو شد'];
        }

        $transaction = $this->transactionModel->findTransaction($transaction_id);
        if (!$transaction) {
            return ['success' => false, 'message' => 'تراکنش یافت نشد'];
        }

        if ($transaction['status'] === Transaction::STATUS_COMPLETED) {
            return ['success' => true, 'message' => 'این تراکنش قبلاً تایید شده است'];
        }

        if (strpos($authority, 'TEST_') === 0) {
            return $this->verifyTestPayment($transaction);
        }

        $data = [
            'merchant_id' => $this->merchantId,
            'amount' => $transaction['amount'],
            'authority' => $authority
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->zarinpalVerification);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (isset($result['data']['code']) && $result['data']['code'] == 100) {
            return $this->completePayment($transaction, $result['data']['ref_id']);
        }

        $this->transactionModel->updateTransaction($transaction_id, [
            'status' => Transaction::STATUS_FAILED
        ]);

        return ['success' => false, 'message' => 'تایید پرداخت با خطا مواجه شد'];
    }

    private function verifyTestPayment($transaction) {
        return $this->completePayment($transaction, 'TEST_REF_' . $transaction['id']);
    }

    private function completePayment($transaction, $ref_id) {
        $this->transactionModel->updateTransaction($transaction['id'], [
            'status' => Transaction::STATUS_COMPLETED,
            'ref_id' => $ref_id
        ]);

        $result = $this->walletModel->increaseBalance($transaction['user_id'], $transaction['amount']);

        if ($result) {
            $this->transactionModel->createTransaction([
                'user_id' => $transaction['user_id'],
                'amount' => $transaction['amount'],
                'type' => Transaction::TYPE_DEPOSIT,
                'description' => 'شارژ کیف پول - شماره پیگیری: ' . $ref_id,
                'status' => Transaction::STATUS_COMPLETED,
                'reference_id' => $ref_id
            ]);

            $user = $this->userModel->findById($transaction['user_id']);
            if ($user) {
                $message = "✅ پرداخت با موفقیت انجام شد.\n\n";
                $message .= "مبلغ شارژ: " . number_format($transaction['amount']) . " تومان\n";
                $message .= "شماره پیگیری: {$ref_id}\n\n";
                $message .= "💰 موجودی کیف پول شما بروزرسانی شد.";

                $this->telegram->sendMessage($user['telegram_id'], $message);
            }

            return [
                'success' => true,
                'ref_id' => $ref_id,
                'amount' => $transaction['amount'],
                'message' => 'پرداخت با موفقیت انجام شد'
            ];
        }

        return ['success' => false, 'message' => 'خطا در شارژ کیف پول'];
    }

    public function cancelPayment($chat_id) {
        $this->telegram->sendMessage($chat_id, "❌ عملیات پرداخت لغو شد.");
    }
}