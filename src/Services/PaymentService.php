<?php
namespace Services;

use Models\User;
use Models\Transaction;
use Helpers\Logger;

class PaymentService {
    private $merchantId;
    private $zarinpalGateway = 'https://api.zarinpal.com/pg/v4/payment/request.json';
    private $zarinpalVerification = 'https://api.zarinpal.com/pg/v4/payment/verify.json';
    private $callbackUrl;
    private $userModel;
    private $transactionModel;

    public function __construct() {
        $this->merchantId = ZARINPAL_MERCHANT_ID;
        $this->callbackUrl = CALLBACK_URL;
        $this->userModel = new User();
        $this->transactionModel = new Transaction();
    }

    /**
     * ایجاد درخواست پرداخت
     */
    public function createPaymentRequest($user_id, $amount, $description = "شارژ کیف پول") {
        $amount = (int) $amount;

        // ثبت تراکنش در دیتابیس
        $transaction_id = $this->transactionModel->create([
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => 'deposit',
            'status' => 'pending',
            'reference_id' => null
        ]);

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

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($result['data']['code'] == 100) {
            // ذخیره authority در تراکنش
            $this->transactionModel->update($transaction_id, [
                'reference_id' => $result['data']['authority']
            ]);

            return [
                'success' => true,
                'payment_url' => "https://www.zarinpal.com/pg/StartPay/{$result['data']['authority']}",
                'authority' => $result['data']['authority']
            ];
        }

        Logger::error("Payment request failed: " . json_encode($result));
        return ['success' => false, 'error' => $result['errors']['message']];
    }

    /**
     * تایید پرداخت
     */
    public function verifyPayment($transaction_id, $authority, $status) {
        if ($status != 'OK') {
            $this->transactionModel->update($transaction_id, ['status' => 'failed']);
            return ['success' => false, 'message' => 'پرداخت توسط کاربر لغو شد'];
        }

        $transaction = $this->transactionModel->find($transaction_id);
        if (!$transaction) {
            return ['success' => false, 'message' => 'تراکنش یافت نشد'];
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

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($result['data']['code'] == 100) {
            // بروزرسانی تراکنش
            $this->transactionModel->update($transaction_id, [
                'status' => 'completed',
                'ref_id' => $result['data']['ref_id']
            ]);

            // شارژ کیف پول کاربر
            $this->userModel->updateWallet($transaction['user_id'], $transaction['amount']);

            return [
                'success' => true,
                'ref_id' => $result['data']['ref_id'],
                'amount' => $transaction['amount']
            ];
        }

        $this->transactionModel->update($transaction_id, ['status' => 'failed']);
        return ['success' => false, 'message' => 'تایید پرداخت با خطا مواجه شد'];
    }

    /**
     * برداشت از کیف پول (برای آگهی)
     */
    public function withdrawFromWallet($user_id, $amount, $job_id) {
        $balance = $this->userModel->getWalletBalance($user_id);

        if ($balance < $amount) {
            return ['success' => false, 'message' => 'موجودی کیف پول کافی نیست'];
        }

        // کسر از کیف پول
        $this->userModel->updateWallet($user_id, -$amount);

        // ثبت تراکنش برداشت
        $this->transactionModel->create([
            'user_id' => $user_id,
            'amount' => -$amount,
            'type' => 'job_payment',
            'status' => 'completed',
            'reference_id' => $job_id
        ]);

        return ['success' => true];
    }
}