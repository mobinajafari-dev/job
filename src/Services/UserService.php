<?php

namespace Services;

use Models\User;
use Models\Wallet;
use Models\Transaction;
use Helpers\Validator;
use Helpers\Logger;

class UserService
{
    private $userModel;
    private $walletModel;
    private $transactionModel;
    private $telegram;

    public function __construct($telegramService)
    {
        $this->userModel = new User();
        $this->walletModel = new Wallet();
        $this->transactionModel = new Transaction();
        $this->telegram = $telegramService;
    }

    public function handleStart($chat_id, $username, $referral_code_inviter = null)
    {
        Logger::info("Start command from {$chat_id}, referral: " . ($referral_code_inviter ?? 'none'));

        if ($this->userModel->exists($chat_id)) {
            $balance = $this->userModel->getBalance($chat_id);
            $message = "✨ به ربات آگهی‌های کاری خوش آمدید!\n\n";
            $message .= "💰 موجودی کیف پول شما: " . number_format($balance) . " تومان\n\n";
            $message .= "از دکمه‌های زیر استفاده کنید:";

            $this->telegram->sendMessage($chat_id, $message, $this->getMainKeyboard());
        } else {
            if ($referral_code_inviter) {
                $_SESSION['temp_referral_code'][$chat_id] = $referral_code_inviter;
            }

            $message = "📱 <b>به ربات آگهی‌های کاری خوش آمدید!</b>\n\n";
            $message .= "برای استفاده از ربات، لطفاً شماره تلفن خود را ارسال کنید.\n\n";
            $message .= "🎁 <b>هدیه ویژه:</b> پس از ثبت‌نام، 10,000 تومان به کیف پول شما تعلق می‌گیرد!";

            if ($referral_code_inviter) {
                $message .= "\n\n🔖 شما با کد معرف دعوت شده‌اید! پس از ثبت‌نام، 2,000 تومان پاداش به دعوت‌کننده تعلق می‌گیرد.";
            }

            $this->telegram->requestContact($chat_id, $message);
        }
    }

    public function handlePhoneNumber($chat_id, $username, $contact)
    {
        $phone = $contact['phone_number'];

        // لاگ برای دیباگ
        Logger::info("Received phone number: " . $phone . " from user: " . $chat_id);

        if (!Validator::phone($phone)) {
            Logger::warning("Invalid phone number format: " . $phone . " from user: " . $chat_id);
            $this->telegram->sendMessage($chat_id, "❌ شماره تلفن نامعتبر است. لطفاً شماره خود را مجدداً ارسال کنید.");
            $this->telegram->requestContact($chat_id, "لطفاً شماره خود را با فرمت صحیح ارسال کنید:");
            return;
        }

        if ($this->userModel->exists($chat_id)) {
            $this->telegram->sendMessage($chat_id, "✅ شما قبلاً ثبت‌نام کرده‌اید!", $this->getMainKeyboard());
            return;
        }

        $referral_code_inviter = $_SESSION['temp_referral_code'][$chat_id] ?? null;
        unset($_SESSION['temp_referral_code'][$chat_id]);

        $result = $this->userModel->createUser($chat_id, $username, $phone, $referral_code_inviter);

        if ($result['success']) {
            $message = "✅ <b>شماره شما با موفقیت ثبت شد!</b>\n\n";
            $message .= "🎁 10,000 تومان هدیه به کیف پول شما اضافه شد.\n\n";
            $message .= "🔖 <b>کد معرف شما:</b> <code>" . $result['referral_code'] . "</code>\n\n";
            $message .= "از دکمه‌های زیر استفاده کنید:";

            $this->telegram->sendMessage($chat_id, $message, $this->getMainKeyboard());
        } else {
            Logger::error("Registration failed for {$chat_id}: " . ($result['error'] ?? 'unknown'));
            $this->telegram->sendMessage($chat_id, "❌ خطا در ثبت اطلاعات. لطفاً دوباره تلاش کنید.");
            $this->telegram->requestContact($chat_id, "لطفاً شماره خود را مجدداً ارسال کنید:");
        }
    }

    public function showReferralLink($chat_id)
    {
        $referral_code = $this->userModel->getReferralCode($chat_id);
        $link = "https://t.me/" . BOT_USERNAME . "?start=" . $referral_code;

        $referred_count = $this->userModel->getReferredCount($chat_id);
        $total_bonus = $referred_count * REFERRAL_BONUS;

        $stats_message = "📊 <b>آمار دعوت‌های شما</b>\n\n";
        $stats_message .= "🔖 کد معرف شما: <code>{$referral_code}</code>\n\n";
        $stats_message .= "📈 <b>آمار:</b>\n";
        $stats_message .= "• تعداد دعوت‌های موفق: <b>{$referred_count}</b> نفر\n";
        $stats_message .= "• مجموع پاداش دریافتی: <b>" . number_format($total_bonus) . "</b> تومان\n\n";
        $stats_message .= "🎁 پاداش هر دعوت: <b>" . number_format(REFERRAL_BONUS) . "</b> تومان";

        $share_keyboard = [
            'inline_keyboard' => [
                [['text' => '📋 کپی لینک', 'callback_data' => 'copy_link_' . $referral_code]],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_menu']]
            ]
        ];

        $this->telegram->sendMessage($chat_id, $stats_message);
        $this->telegram->sendMessage($chat_id, "🔗 لینک دعوت شما:\n<code>{$link}</code>", $share_keyboard);
    }

    public function getUserBalance($chat_id)
    {
        return $this->userModel->getBalance($chat_id);
    }

    public function isUserExists($chat_id)
    {
        return $this->userModel->exists($chat_id);
    }

    public function getUserByTelegramId($chat_id)
    {
        return $this->userModel->findByTelegramId($chat_id);
    }

    public function getUserState($chat_id)
    {
        return $_SESSION['user_state'][$chat_id] ?? null;
    }

    public function setUserState($chat_id, $state)
    {
        if ($state === null) {
            unset($_SESSION['user_state'][$chat_id]);
        } else {
            $_SESSION['user_state'][$chat_id] = $state;
        }
    }

    public function clearUserState($chat_id)
    {
        unset($_SESSION['user_state'][$chat_id]);
        unset($_SESSION['temp_job'][$chat_id]);
        unset($_SESSION['temp_discount'][$chat_id]);
    }

    public function saveTempData($chat_id, $key, $value)
    {
        $_SESSION['temp_' . $key][$chat_id] = $value;
    }

    public function getTempData($chat_id, $key)
    {
        return $_SESSION['temp_' . $key][$chat_id] ?? null;
    }

    public function clearTempData($chat_id, $key)
    {
        unset($_SESSION['temp_' . $key][$chat_id]);
    }

    private function getMainKeyboard()
    {
        return [
            'keyboard' => [
                [['text' => '📝 ارسال آگهی'], ['text' => '💰 کیف پول من']],
                [['text' => '🎁 کد تخفیف'], ['text' => '👥 دعوت از دوستان']],
                [['text' => '📞 پشتیبانی'], ['text' => 'ℹ️ راهنما']]
            ],
            'resize_keyboard' => true
        ];
    }

    // در UserService.php، متد increaseBalance را تغییر دهید:
    public function increaseBalance($chat_id, $amount, $type, $description = null) {
        $walletService = new WalletService($this->telegram);
        return $walletService->increaseBalance($chat_id, $amount, $type, $description);
    }
}