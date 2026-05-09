<?php
namespace Services;

use Models\User;
use Models\Wallet;
use Models\Transaction;
use Helpers\Validator;
use Helpers\Logger;

class WalletService {
    private $userModel;
    private $walletModel;
    private $transactionModel;
    private $telegram;

    public function __construct($telegramService) {
        $this->userModel = new User();
        $this->walletModel = new Wallet();
        $this->transactionModel = new Transaction();
        $this->telegram = $telegramService;
    }

    public function getBalance($chat_id) {
        return $this->userModel->getBalance($chat_id);
    }

    public function showWallet($chat_id) {
        $balance = $this->getBalance($chat_id);
        $user = $this->userModel->findByTelegramId($chat_id);

        $message = "💰 <b>کیف پول شما</b>\n\n";
        $message .= "💵 موجودی: " . number_format($balance) . " تومان\n\n";
        $message .= "📊 برای شارژ کیف پول از دستور /pay [مبلغ] استفاده کنید.\n";
        $message .= "مثال: /pay 50000";

        if ($user) {
            $transactions = $this->walletModel->getTransactions($user['id'], 5);
            if ($transactions) {
                $message .= "\n📋 <b>آخرین تراکنش‌ها:</b>\n";
                foreach ($transactions as $t) {
                    $sign = $t['amount'] > 0 ? '+' : '';
                    $message .= "• " . date('d/m H:i', strtotime($t['created_at'])) . " - " . $sign . number_format($t['amount']) . " تومان\n";
                }
            }
        }

        $this->telegram->sendMessage($chat_id, $message);
    }

    public function increaseBalance($chat_id, $amount, $type, $description = null) {
        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $result = $this->userModel->increaseBalance($chat_id, $amount);

        if ($result) {
            $this->transactionModel->createTransaction([
                'user_id' => $user['id'],
                'amount' => $amount,
                'type' => $type,
                'description' => $description,
                'status' => 'completed'
            ]);

            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Failed to increase balance'];
    }

    public function decreaseBalance($chat_id, $amount, $type, $description = null) {
        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $currentBalance = $this->getBalance($chat_id);
        if ($currentBalance < $amount) {
            return ['success' => false, 'error' => 'Insufficient balance'];
        }

        $result = $this->userModel->decreaseBalance($chat_id, $amount);

        if ($result) {
            $this->transactionModel->createTransaction([
                'user_id' => $user['id'],
                'amount' => -$amount,
                'type' => $type,
                'description' => $description,
                'status' => 'completed'
            ]);

            return ['success' => true];
        }

        return ['success' => false, 'error' => 'Failed to decrease balance'];
    }

    public function hasSufficientBalance($chat_id, $amount) {
        $balance = $this->getBalance($chat_id);
        return $balance >= $amount;
    }

    public function addWelcomeBonus($chat_id) {
        return $this->increaseBalance($chat_id, 10000, 'welcome_bonus', 'هدیه ثبت‌نام');
    }

    public function addReferralBonus($chat_id, $invited_username) {
        $result = $this->increaseBalance($chat_id, REFERRAL_BONUS, 'referral_bonus', 'پاداش معرف دوست - ' . $invited_username);

        if ($result['success']) {
            $message = "🎉 <b>تبریک!</b>\n\n";
            $message .= "یک نفر با کد معرف شما ثبت‌نام کرد!\n";
            $message .= "👤 نام کاربری: @{$invited_username}\n\n";
            $message .= "💰 مبلغ " . number_format(REFERRAL_BONUS) . " تومان به کیف پول شما اضافه شد.";
            $this->telegram->sendMessage($chat_id, $message);
        }

        return $result;
    }

    public function deductJobFee($chat_id) {
        return $this->decreaseBalance($chat_id, JOB_PRICE, 'job_payment', 'هزینه انتشار آگهی');
    }

    public function getTransactionHistory($chat_id, $limit = 10) {
        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            return [];
        }

        return $this->walletModel->getTransactions($user['id'], $limit);
    }

    public function getTotalBalance() {
        return $this->walletModel->getTotalBalance();
    }
}