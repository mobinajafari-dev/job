<?php
namespace Models;

use Core\Model;

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
        $this->db->beginTransaction();

        try {
            $referral_code = $this->generateReferralCode($telegram_id, $username);

            $referred_by_id = null;
            $inviter_telegram_id = null;

            if (!empty($referral_code_inviter)) {
                $inviter = $this->findByReferralCode($referral_code_inviter);
                if ($inviter) {
                    $referred_by_id = $inviter['id'];
                    $inviter_telegram_id = $inviter['telegram_id'];
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

            $result = $this->insert($userData, true);

            if (!$result['status']) {
                throw new \Exception("User insert failed");
            }

            $user_id = $result['last_id'];

            $walletData = [
                'user_id' => $user_id,
                'balance' => 10000,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $walletModel = new Wallet();
            $walletModel->insert($walletData);

            $transactionData = [
                'user_id' => $user_id,
                'amount' => 10000,
                'type' => 'welcome_bonus',
                'description' => 'هدیه ثبت‌نام',
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $transactionModel = new Transaction();
            $transactionModel->insert($transactionData);

            if ($referred_by_id && $inviter_telegram_id) {
                $sql = "UPDATE wallets SET balance = balance + :bonus WHERE user_id = :user_id";
                $this->db->query($sql, [
                    'bonus' => REFERRAL_BONUS,
                    'user_id' => $referred_by_id
                ]);

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

                $this->sendReferralBonusMessage($inviter_telegram_id, REFERRAL_BONUS, $username);
            }

            $this->db->commit();

            return [
                'success' => true,
                'user_id' => $user_id,
                'referral_code' => $referral_code,
                'referred_by' => $referred_by_id
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
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