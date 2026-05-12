<?php
namespace Models;

use Core\Model;
use Helpers\Logger;

class User extends Model {
    protected $table = 'users';

    private function generateReferralCode($telegram_id, $username) {
        $base = $telegram_id . ($username ?? '') . time() . rand(1000, 9999);
        $code = substr(md5($base), 0, 8);

        $result = $this->select(['id'], ['referral_code' => $code], false);

        if ($result['status'] && $result['details']) {
            return $this->generateReferralCode($telegram_id, $username . rand(1, 999));
        }

        return strtoupper($code);
    }

    public function findByTelegramId($telegram_id) {
        $result = $this->select(['*'], ['telegram_id' => $telegram_id], false);
        if ($result['status'] && $result['details']) {
            return $result['details'];
        }
        return null;
    }

    public function findByReferralCode($code) {
        $result = $this->select(['*'], ['referral_code' => $code], false);
        if ($result['status'] && $result['details']) {
            return $result['details'];
        }
        return null;
    }

    public function findById($id) {
        $result = $this->select(['*'], ['id' => $id], false);
        if ($result['status'] && $result['details']) {
            return $result['details'];
        }
        return null;
    }

    public function createUser($telegram_id, $username, $phone, $referral_code_inviter = null) {
        Logger::info("========== CREATE USER START ==========");
        Logger::info("Telegram ID: {$telegram_id}, Username: {$username}, Phone: {$phone}");

        try {
            // ===== مرحله 1: ثبت کاربر (بدون تراکنش) =====
            Logger::info("Generating referral code...");
            $referral_code = $this->generateReferralCode($telegram_id, $username);
            Logger::info("Referral code generated: {$referral_code}");

            $referred_by_id = null;
            $inviter_telegram_id = null;

            if (!empty($referral_code_inviter)) {
                Logger::info("Checking inviter with code: {$referral_code_inviter}");
                $inviter = $this->findByReferralCode($referral_code_inviter);
                if ($inviter) {
                    $referred_by_id = $inviter['id'];
                    $inviter_telegram_id = $inviter['telegram_id'];
                    Logger::info("Inviter found: ID={$referred_by_id}");
                } else {
                    Logger::warning("Inviter NOT found with code: {$referral_code_inviter}");
                }
            }

            $userData = [
                'telegram_id' => $telegram_id,
                'username' => $username,
                'phone' => $phone,
                'referral_code' => $referral_code,
                'referred_by' => $referred_by_id,
                'created_at' => date('Y-m-d H:i:s')
            ];

            Logger::info("Inserting user...");
            $result = $this->insert($userData, true);

            if (!$result['status']) {
                Logger::error("User insert failed: " . ($result['response'] ?? 'unknown'));
                throw new \Exception("User insert failed");
            }

            $user_id = $result['last_id'];
            Logger::info("User inserted with ID: {$user_id}");

            // ===== مرحله 2: کیف پول (خارج از تراکنش) =====
            Logger::info("Creating wallet...");
            $walletModel = new Wallet();
            $walletResult = $walletModel->createWallet($user_id, 10000);

            if (!$walletResult['status']) {
                Logger::error("Wallet insert failed: " . json_encode($walletResult));
                // کاربر ثبت شده ولی کیف پول نه - نیاز به مدیریت دارد
                throw new \Exception("Wallet insert failed: " . ($walletResult['response'] ?? 'Unknown error'));
            }
            Logger::info("Wallet created successfully");

            // ===== مرحله 3: تراکنش خوش آمدید =====
            Logger::info("Creating welcome bonus transaction...");
            $transactionData = [
                'user_id' => $user_id,
                'amount' => 10000,
                'type' => 'welcome_bonus',
                'description' => 'هدیه ثبت‌نام',
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $transactionModel = new Transaction();
            $transactionResult = $transactionModel->insert($transactionData);

            if (!$transactionResult['status']) {
                Logger::error("Transaction insert failed");
                throw new \Exception("Transaction insert failed");
            }
            Logger::info("Welcome bonus transaction created");

            // ===== مرحله 4: پاداش معرف (اگر وجود داشته باشد) =====
            if ($referred_by_id && $inviter_telegram_id) {
                Logger::info("Processing referral bonus for inviter: {$referred_by_id}");

                try {
                    // افزایش موجودی کیف پول دعوت کننده
                    $walletModel = new Wallet();
                    $walletModel->increaseBalance($referred_by_id, REFERRAL_BONUS);

                    // ثبت تراکنش پاداش
                    $referralTransaction = [
                        'user_id' => $referred_by_id,
                        'amount' => REFERRAL_BONUS,
                        'type' => 'referral_bonus',
                        'description' => 'پاداش معرف دوست',
                        'reference_id' => $user_id,
                        'status' => 'completed',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    $transactionModel->insert($referralTransaction);

                    // ارسال پیام به دعوت کننده
                    Logger::info("Sending referral bonus message...");
                    $this->sendReferralBonusMessage($inviter_telegram_id, REFERRAL_BONUS, $username);
                    Logger::info("Referral bonus message sent");

                } catch (\Exception $e) {
                    Logger::error("Referral bonus failed but continuing: " . $e->getMessage());
                    // این خطا نباید باعث شکست ثبت‌نام شود
                }
            }

            Logger::info("========== CREATE USER SUCCESS ==========");

            return [
                'success' => true,
                'user_id' => $user_id,
                'referral_code' => $referral_code,
                'referred_by' => $referred_by_id
            ];

        } catch (\Exception $e) {
            Logger::error("========== CREATE USER FAILED ==========");
            Logger::error("Error: " . $e->getMessage());
            Logger::error("File: " . $e->getFile() . " Line: " . $e->getLine());
            Logger::error("Trace: " . $e->getTraceAsString());

            // توجه: دیگر نیازی به rollback نیست چون تراکنشی وجود ندارد

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function sendReferralBonusMessage($telegram_id, $bonus_amount, $invited_username) {
        try {
            $telegram = new \Services\TelegramService(BOT_TOKEN);

            $message = "🎉 <b>تبریک!</b>\n\n";
            $message .= "یک نفر با کد معرف شما ثبت‌نام کرد!\n";
            $message .= "👤 نام کاربری: @{$invited_username}\n\n";
            $message .= "💰 مبلغ " . number_format($bonus_amount) . " تومان به کیف پول شما اضافه شد.\n\n";
            $message .= "به دعوت از دوستان ادامه دهید و پاداش بیشتری دریافت کنید! 🚀";

            $telegram->sendMessage($telegram_id, $message);
        } catch (\Exception $e) {
            error_log("Failed to send referral bonus message: " . $e->getMessage());
        }
    }

    public function updateUser($telegram_id, $data) {
        return $this->update($data, ['telegram_id' => $telegram_id]);
    }

    public function exists($telegram_id) {
        $result = $this->select(['id'], ['telegram_id' => $telegram_id], false);
        return $result['status'] && !empty($result['details']);
    }

    public function getReferralCode($telegram_id) {
        $user = $this->findByTelegramId($telegram_id);
        return $user ? $user['referral_code'] : null;
    }

    public function getReferredUsers($telegram_id) {
        $user = $this->findByTelegramId($telegram_id);
        if (!$user) return [];

        $result = $this->select(
            ['id', 'telegram_id', 'username', 'phone', 'created_at'],
            ['referred_by' => $user['id']],
            true,
            'ORDER BY created_at DESC'
        );

        return $result['status'] ? $result['details'] : [];
    }

    public function getReferredCount($telegram_id) {
        $user = $this->findByTelegramId($telegram_id);
        if (!$user) return 0;

        $result = $this->select(['COUNT(*) as count'], ['referred_by' => $user['id']], false);
        return $result['status'] && $result['details'] ? (int) $result['details']['count'] : 0;
    }

    public function getBalance($telegram_id) {
        $user = $this->findByTelegramId($telegram_id);
        if (!$user) return 0;

        $walletModel = new Wallet();
        return $walletModel->getBalance($user['id']);
    }

    public function increaseBalance($telegram_id, $amount) {
        $user = $this->findByTelegramId($telegram_id);
        if (!$user) return false;

        $walletModel = new Wallet();
        return $walletModel->increaseBalance($user['id'], $amount);
    }

    public function decreaseBalance($telegram_id, $amount) {
        $user = $this->findByTelegramId($telegram_id);
        if (!$user) return false;

        $walletModel = new Wallet();
        return $walletModel->decreaseBalance($user['id'], $amount);
    }

    public function count() {
        $result = $this->select(['COUNT(*) as total'], [], false);
        return $result['status'] && $result['details'] ? (int) $result['details']['total'] : 0;
    }
}