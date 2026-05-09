<?php
namespace Services;

use Models\User;
use Models\Wallet;
use Models\Transaction;
use Helpers\Logger;

class ReferralService {
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

    public function generateReferralLink($chat_id) {
        $referralCode = $this->userModel->getReferralCode($chat_id);
        if (!$referralCode) {
            return null;
        }

        $botUsername = BOT_USERNAME;
        return "https://t.me/{$botUsername}?start={$referralCode}";
    }

    public function showReferralInfo($chat_id) {
        $referralCode = $this->userModel->getReferralCode($chat_id);
        if (!$referralCode) {
            $this->telegram->sendMessage($chat_id, "❌ کاربر یافت نشد. لطفاً /start را بزنید.");
            return;
        }

        $link = $this->generateReferralLink($chat_id);
        $referredCount = $this->userModel->getReferredCount($chat_id);
        $totalBonus = $referredCount * REFERRAL_BONUS;

        $message = "👥 <b>سیستم دعوت از دوستان</b>\n\n";
        $message .= "🎁 <b>پاداش هر دعوت:</b> " . number_format(REFERRAL_BONUS) . " تومان\n\n";

        $message .= "📊 <b>آمار شما:</b>\n";
        $message .= "• تعداد دعوت‌های موفق: <b>{$referredCount}</b> نفر\n";
        $message .= "• مجموع پاداش دریافتی: <b>" . number_format($totalBonus) . "</b> تومان\n\n";

        $message .= "🔖 <b>کد معرف شما:</b>\n";
        $message .= "<code>{$referralCode}</code>\n\n";

        $message .= "🔗 <b>لینک دعوت اختصاصی:</b>\n";
        $message .= "<code>{$link}</code>\n\n";

        $message .= "💡 <b>چگونه کار می‌کند؟</b>\n";
        $message .= "1. لینک بالا را برای دوستان خود ارسال کنید\n";
        $message .= "2. دوستان شما با لینک وارد ربات می‌شوند\n";
        $message .= "3. پس از ثبت‌نام، شما پاداش دریافت می‌کنید\n";
        $message .= "4. هرچه دوستان بیشتری دعوت کنید، پاداش بیشتری می‌گیرید!\n\n";

        $message .= "🚀 <b>برای دعوت از دوستان، لینک را کپی کنید:</b>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📋 کپی لینک دعوت', 'callback_data' => "copy_link_{$referralCode}"]
                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chat_id, $message, $keyboard);
    }

    public function processReferral($newUserId, $referralCode) {
        $inviter = $this->userModel->findByReferralCode($referralCode);

        if (!$inviter) {
            Logger::warning("Invalid referral code used: {$referralCode}");
            return false;
        }

        if ($inviter['id'] == $newUserId) {
            Logger::warning("User tried to refer themselves: {$newUserId}");
            return false;
        }

        $alreadyReferred = $this->userModel->findByTelegramId($newUserId);
        if ($alreadyReferred && $alreadyReferred['referred_by']) {
            Logger::warning("User already referred: {$newUserId}");
            return false;
        }

        $bonusResult = $this->addReferralBonus($inviter['telegram_id'], $newUserId);

        if ($bonusResult['success']) {
            Logger::info("Referral bonus given: inviter={$inviter['telegram_id']}, newUser={$newUserId}");
            return true;
        }

        return false;
    }

    public function addReferralBonus($inviterTelegramId, $newUserId) {
        $inviter = $this->userModel->findByTelegramId($inviterTelegramId);
        if (!$inviter) {
            return ['success' => false, 'error' => 'Inviter not found'];
        }

        $newUser = $this->userModel->findById($newUserId);
        $newUserUsername = $newUser ? $newUser['username'] : 'کاربر';

        $this->walletModel->beginTransaction();

        try {
            $currentBalance = $this->walletModel->getBalance($inviter['id']);

            $result = $this->walletModel->increaseBalance($inviter['id'], REFERRAL_BONUS);

            if (!$result) {
                throw new \Exception("Failed to increase balance");
            }

            $this->transactionModel->createTransaction([
                'user_id' => $inviter['id'],
                'amount' => REFERRAL_BONUS,
                'type' => Transaction::TYPE_REFERRAL,
                'description' => 'پاداش معرف دوست - ' . $newUserUsername,
                'reference_id' => $newUserId,
                'status' => Transaction::STATUS_COMPLETED
            ]);

            $this->walletModel->commit();

            $this->sendReferralBonusMessage($inviterTelegramId, REFERRAL_BONUS, $newUserUsername);

            return ['success' => true];

        } catch (\Exception $e) {
            $this->walletModel->rollBack();
            Logger::error("Referral bonus failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendReferralBonusMessage($telegramId, $bonusAmount, $invitedUsername) {
        $message = "🎉 <b>تبریک!</b>\n\n";
        $message .= "یک نفر با کد معرف شما ثبت‌نام کرد!\n";
        $message .= "👤 نام کاربری: @{$invitedUsername}\n\n";
        $message .= "💰 مبلغ " . number_format($bonusAmount) . " تومان به کیف پول شما اضافه شد.\n\n";
        $message .= "به دعوت از دوستان ادامه دهید و پاداش بیشتری دریافت کنید! 🚀";

        $this->telegram->sendMessage($telegramId, $message);
    }

    public function getReferralStats($chat_id) {
        $referredCount = $this->userModel->getReferredCount($chat_id);
        $referredUsers = $this->userModel->getReferredUsers($chat_id);

        $totalBonus = $referredCount * REFERRAL_BONUS;

        $message = "📊 <b>آمار دعوت‌های شما</b>\n\n";
        $message .= "👥 تعداد دعوت‌های موفق: <b>{$referredCount}</b> نفر\n";
        $message .= "💰 مجموع پاداش دریافتی: <b>" . number_format($totalBonus) . "</b> تومان\n\n";

        if (!empty($referredUsers)) {
            $message .= "📋 <b>لیست دوستان دعوت شده:</b>\n";
            foreach ($referredUsers as $index => $user) {
                $message .= ($index + 1) . ". @{$user['username']} - " . date('Y/m/d', strtotime($user['created_at'])) . "\n";
                if ($index >= 9) {
                    $message .= "... و " . (count($referredUsers) - 10) . " نفر دیگر\n";
                    break;
                }
            }
        }

        return $message;
    }

    public function extractReferralCodeFromStart($text) {
        if (strpos($text, '/start') !== 0) {
            return null;
        }

        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            return null;
        }

        $code = trim($parts[1]);

        if ($code == 'start' || empty($code)) {
            return null;
        }

        return $code;
    }

    public function validateReferralCode($code) {
        if (empty($code)) {
            return false;
        }

        $inviter = $this->userModel->findByReferralCode($code);
        return $inviter !== null;
    }

    public function getTopReferrers($limit = 10) {
        $sql = "SELECT u.telegram_id, u.username, COUNT(r.id) as referral_count
                FROM users u
                LEFT JOIN users r ON r.referred_by = u.id
                GROUP BY u.id
                ORDER BY referral_count DESC
                LIMIT :limit";

        $result = $this->userModel->rawQuery($sql, ['limit' => $limit]);

        if ($result['status'] && $result['details']) {
            return $result['details'];
        }

        return [];
    }

    public function getTotalReferralCount() {
        $sql = "SELECT COUNT(*) as total FROM users WHERE referred_by IS NOT NULL";
        $result = $this->userModel->rawQuery($sql);

        if ($result['status'] && $result['details']) {
            return $result['details'][0]['total'];
        }

        return 0;
    }

    public function getTotalReferralBonusPaid() {
        $sql = "SELECT SUM(amount) as total FROM transactions WHERE type = 'referral_bonus' AND status = 'completed'";
        $result = $this->transactionModel->rawQuery($sql);

        if ($result['status'] && $result['details']) {
            return (float)($result['details'][0]['total'] ?? 0);
        }

        return 0;
    }
}