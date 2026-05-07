<?php
namespace Services;

use Models\User;
use Models\Transaction;
use Services\TelegramService;

class ReferralService {
    private $userModel;
    private $transactionModel;
    private $telegramService;

    public function __construct() {
        $this->userModel = new User();
        $this->transactionModel = new Transaction();
        $this->telegramService = new TelegramService(BOT_TOKEN);
    }

    /**
     * ایجاد کد معرف برای کاربر جدید
     */
    public function generateReferralCode($user_id) {
        $code = substr(md5($user_id . time()), 0, 8);
        $sql = "UPDATE users SET referral_code = :code WHERE id = :user_id";
        Database::execute($sql, ['code' => $code, 'user_id' => $user_id]);
        return $code;
    }

    /**
     * ثبت معرفی شده
     */
    public function registerReferral($new_user_id, $referral_code) {
        // پیدا کردن کاربر معرف
        $sql = "SELECT id FROM users WHERE referral_code = :code";
        $referrer = Database::query($sql, ['code' => $referral_code]);

        if (!$referrer) {
            return false;
        }

        // ثبت معرفی
        $sql = "UPDATE users SET referred_by = :referrer_id WHERE id = :new_user_id";
        Database::execute($sql, [
            'referrer_id' => $referrer['id'],
            'new_user_id' => $new_user_id
        ]);

        // اعطای پاداش به معرف
        $this->giveReferralBonus($referrer['id'], $new_user_id);

        // ارسال پیام تبریک به معرف
        $this->sendReferralNotification($referrer['id'], $new_user_id);

        return true;
    }

    /**
     * اعطای پاداش معرف
     */
    private function giveReferralBonus($referrer_id, $new_user_id) {
        $bonus_amount = REFERRAL_BONUS;

        // شارژ کیف پول معرف
        $this->userModel->updateWallet($referrer_id, $bonus_amount);

        // ثبت تراکنش پاداش
        $this->transactionModel->create([
            'user_id' => $referrer_id,
            'amount' => $bonus_amount,
            'type' => 'referral',
            'status' => 'completed',
            'reference_id' => $new_user_id
        ]);

        return true;
    }

    /**
     * ارسال پیام تبریک به معرف
     */
    private function sendReferralNotification($referrer_id, $new_user_id) {
        // دریافت تلگرام آیدی کاربر معرف
        $sql = "SELECT telegram_id FROM users WHERE id = :id";
        $user = Database::query($sql, ['id' => $referrer_id]);

        $message = "🎉 تبریک!\n\n";
        $message .= "یک نفر از طریق کد معرف شما ثبت‌نام کرد.\n";
        $message .= "💰 مبلغ " . number_format(REFERRAL_BONUS) . " تومان به کیف پول شما اضافه شد.\n\n";
        $message .= "به معرفی دوستان خود ادامه دهید و پاداش بگیرید!";

        $this->telegramService->sendMessage($user['telegram_id'], $message);
    }

    /**
     * دریافت آمار معرف‌های کاربر
     */
    public function getReferralStats($user_id) {
        $sql = "SELECT COUNT(*) as total_referrals FROM users WHERE referred_by = :user_id";
        $result = Database::query($sql, ['user_id' => $user_id]);

        $sql = "SELECT COUNT(*) as total_bonus FROM transactions 
                WHERE user_id = :user_id AND type = 'referral' AND status = 'completed'";
        $bonus = Database::query($sql, ['user_id' => $user_id]);

        return [
            'total_referrals' => $result['total_referrals'],
            'total_bonus' => $bonus['total_bonus'] ?? 0
        ];
    }

    /**
     * دریافت لینک معرف
     */
    public function getReferralLink($telegram_id, $bot_username) {
        $sql = "SELECT referral_code FROM users WHERE telegram_id = :telegram_id";
        $user = Database::query($sql, ['telegram_id' => $telegram_id]);

        if (!$user || !$user['referral_code']) {
            return null;
        }

        return "https://t.me/{$bot_username}?start={$user['referral_code']}";
    }
}