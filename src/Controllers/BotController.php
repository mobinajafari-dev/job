<?php
namespace Controllers;

use Models\User;
use Models\Wallet;
use Services\TelegramService;

class BotController {
    private $telegram;
    private $userModel;
    private $walletModel;
    private $userStates = []; // برای وضعیت‌های کاربر
    
    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->userModel = new User();
        $this->walletModel = new Wallet();
    }
    
    public function handle($update) {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }
    
    private function handleMessage($message) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $username = $message['chat']['username'] ?? '';
        
        // اگر کاربر شماره فرستاده
        if (isset($message['contact'])) {
            $this->handlePhoneNumber($chat_id, $username, $message['contact']);
            return;
        }
        
        // اگر کاربر قبلاً ثبت‌نام نکرده
        if(!$this->userModel->exists($chat_id) && $text != '/start') {
            $this->telegram->sendMessage($chat_id, "❌ لطفاً ابتدا /start را بزنید و شماره خود را ارسال کنید.");
            return;
        }
        
        // پردازش دستورات
        switch ($text) {
            case '/start':
                $this->handleStart($chat_id, $username);
                break;
            case '📝 ارسال آگهی':
                $this->askForJobDetails($chat_id);
                break;
            case '💰 کیف پول من':
                $this->showWallet($chat_id);
                break;
            case '🎁 کد تخفیف':
                $this->askForDiscountCode($chat_id);
                break;
            case '👥 دعوت از دوستان':
                $this->showReferralLink($chat_id);
                break;
            case '📞 پشتیبانی':
                $this->createTicket($chat_id);
                break;
            case 'ℹ️ راهنما':
                $this->showHelp($chat_id);
                break;
            case '❌ انصراف':
                $this->cancelAction($chat_id);
                break;
            default:
                $this->handleUserState($chat_id, $text);
        }
    }
    
    /**
     * هندل کردن استارت - گرفتن شماره تلفن
     */
    private function handleStart($chat_id, $username) {
        // بررسی وجود کاربر
        if($this->userModel->exists($chat_id)) {
            // کاربر قبلاً ثبت‌نام کرده
            $balance = $this->userModel->getBalance($chat_id);
            $message = "✨ به ربات آگهی‌های کاری خوش آمدید!\n\n";
            $message .= "💰 موجودی کیف پول شما: " . number_format($balance) . " تومان\n\n";
            $message .= "از دکمه‌های زیر استفاده کنید:";
            
            $this->telegram->sendMessage($chat_id, $message, $this->getMainKeyboard());
        } else {
            // کاربر جدید - درخواست شماره
            $message = "📱 <b>به ربات آگهی‌های کاری خوش آمدید!</b>\n\n";
            $message .= "برای استفاده از ربات، لطفاً شماره تلفن خود را ارسال کنید.\n\n";
            $message .= "🎁 <b>هدیه ویژه:</b> پس از ثبت‌نام، 10,000 تومان به کیف پول شما تعلق می‌گیرد!";
            
            $this->telegram->requestContact($chat_id, $message);
        }
    }
    
    /**
     * پردازش شماره تلفن دریافتی
     */
 /**
 * پردازش شماره تلفن دریافتی
 */
private function handlePhoneNumber($chat_id, $username, $contact) {
    $phone = $contact['phone_number'];
    
    // بررسی اینکه کاربر قبلاً ثبت نشده باشه
    if($this->userModel->exists($chat_id)) {
        $this->telegram->sendMessage($chat_id, "✅ شما قبلاً ثبت‌نام کرده‌اید!", $this->getMainKeyboard());
        return;
    }
    
    // ثبت کاربر در دیتابیس (با 10000 تومان هدیه - داخل متد create انجام میشه)
    $result = $this->userModel->create($chat_id, $username, $phone);
    
    if($result) {
        // فقط پیام خوش آمدید نمایش بده (بقیه کارها توی متد create انجام شده)
        $message = "✅ <b>شماره شما با موفقیت ثبت شد!</b>\n\n";
        $message .= "🎁 10,000 تومان هدیه به کیف پول شما اضافه شد.\n\n";
        $message .= "✨ به جمع کاربران ما خوش آمدید!\n\n";
        $message .= "از دکمه‌های زیر استفاده کنید:";
        
        $this->telegram->sendMessage($chat_id, $message, $this->getMainKeyboard());
    } else {
        $this->telegram->sendMessage($chat_id, "❌ خطا در ثبت اطلاعات. لطفاً دوباره تلاش کنید.");
        $this->telegram->requestContact($chat_id, "لطفاً شماره خود را مجدداً ارسال کنید:");
    }
}
    
    /**
     * نمایش کیف پول
     */
    private function showWallet($chat_id) {
        $balance = $this->userModel->getBalance($chat_id);
        $user = $this->userModel->findByTelegramId($chat_id);
        
        $message = "💰 <b>کیف پول شما</b>\n\n";
        $message .= "💵 موجودی: " . number_format($balance) . " تومان\n\n";
        $message .= "📊 برای شارژ کیف پول از دستور /pay [مبلغ] استفاده کنید.\n";
        $message .= "مثال: /pay 50000";
        
        // دریافت آخرین تراکنش‌ها
        if($user) {
            $transactions = $this->walletModel->getTransactions($user['id'], 5);
            if($transactions) {
                $message .= "\n📋 <b>آخرین تراکنش‌ها:</b>\n";
                foreach($transactions as $t) {
                    $sign = $t['amount'] > 0 ? '+' : '';
                    $message .= "• " . date('d/m H:i', strtotime($t['created_at'])) . " - {$sign}" . number_format($t['amount']) . " تومان\n";
                }
            }
        }
        
        $this->telegram->sendMessage($chat_id, $message);
    }
    
    /**
     * دریافت کیبورد اصلی
     */
    private function getMainKeyboard() {
        return [
            'keyboard' => [
                [['text' => '📝 ارسال آگهی'], ['text' => '💰 کیف پول من']],
                [['text' => '🎁 کد تخفیف'], ['text' => '👥 دعوت از دوستان']],
                [['text' => '📞 پشتیبانی'], ['text' => 'ℹ️ راهنما']]
            ],
            'resize_keyboard' => true
        ];
    }
    
    // متدهای دیگه (خلاصه برای اختصار)
    private function askForJobDetails($chat_id) {
        $_SESSION['user_state'][$chat_id] = 'waiting_job';
        $this->telegram->sendMessage($chat_id, "✏️ لطفاً متن آگهی خود را ارسال کنید:");
    }
    
    private function askForDiscountCode($chat_id) {
        $_SESSION['user_state'][$chat_id] = 'waiting_discount';
        $this->telegram->sendMessage($chat_id, "🎁 کد تخفیف خود را وارد کنید:");
    }
    
    private function showReferralLink($chat_id) {
        $bot_username = getenv('BOT_USERNAME') ?: 'mobina_bot';
        $link = "https://t.me/{$bot_username}?start=ref_{$chat_id}";
        $message = "👥 <b>سیستم دعوت از دوستان</b>\n\n";
        $message .= "لینک دعوت اختصاصی شما:\n<code>{$link}</code>\n\n";
        $message .= "🎁 به ازای هر دوست که ثبت‌نام کند، " . number_format(REFERRAL_BONUS) . " تومان پاداش می‌گیرید!";
        $this->telegram->sendMessage($chat_id, $message);
    }
    
    private function createTicket($chat_id) {
        $_SESSION['user_state'][$chat_id] = 'waiting_ticket';
        $this->telegram->sendMessage($chat_id, "📞 لطفاً مشکل خود را بنویسید:");
    }
    
    private function showHelp($chat_id) {
        $help = "📖 <b>راهنمای ربات</b>\n\n";
        $help .= "1️⃣ <b>ارسال آگهی:</b>\n   هزینه: " . number_format(JOB_PRICE) . " تومان\n\n";
        $help .= "2️⃣ <b>کیف پول:</b>\n   دستور /pay [مبلغ]\n\n";
        $help .= "3️⃣ <b>کد تخفیف:</b>\n   از ادمین دریافت کنید\n\n";
        $help .= "4️⃣ <b>دعوت از دوستان:</b>\n   هر دعوت " . number_format(REFERRAL_BONUS) . " تومان پاداش";
        $this->telegram->sendMessage($chat_id, $help);
    }
    
    private function cancelAction($chat_id) {
        unset($_SESSION['user_state'][$chat_id]);
        $this->telegram->sendMessage($chat_id, "❌ عملیات لغو شد.", $this->getMainKeyboard());
    }
    
    private function handleUserState($chat_id, $text) {
        $state = $_SESSION['user_state'][$chat_id] ?? null;
        
        if($state == 'waiting_job') {
            $_SESSION['temp_job'][$chat_id] = $text;
            $_SESSION['user_state'][$chat_id] = 'waiting_contact';
            $this->telegram->sendMessage($chat_id, "🆔 لطفاً آیدی تلگرام خود را وارد کنید:\n(مثال: @username)");
            
        } elseif($state == 'waiting_contact') {
            $job_text = $_SESSION['temp_job'][$chat_id];
            $contact_id = $text;
            $user = $this->userModel->findByTelegramId($chat_id);
            
            // بررسی موجودی
            $balance = $this->userModel->getBalance($chat_id);
            
            if($balance >= JOB_PRICE) {
                // کسر از کیف پول
                $this->userModel->increaseBalance($chat_id, -JOB_PRICE);
                
                // ثبت آگهی (فعلاً ساده)
                $this->telegram->sendMessage($chat_id, "✅ آگهی شما ثبت شد و برای تایید ارسال گردید.");
                
                // اطلاع به ادمین
                foreach(ADMIN_IDS as $admin_id) {
                    $this->telegram->sendMessage($admin_id, "📝 آگهی جدید از کاربر {$chat_id}:\n\n{$job_text}\n\nآیدی: {$contact_id}");
                }
            } else {
                $this->telegram->sendMessage($chat_id, "❌ موجودی کیف پول شما کافی نیست!\n💰 موجودی: " . number_format($balance) . " تومان\nلطفاً کیف پول خود را شارژ کنید.");
            }
            
            unset($_SESSION['user_state'][$chat_id]);
            unset($_SESSION['temp_job'][$chat_id]);
            
        } elseif($state == 'waiting_discount') {
            $this->telegram->sendMessage($chat_id, "🎁 کد تخفیف {$text} اعمال شد.");
            unset($_SESSION['user_state'][$chat_id]);
            
        } elseif($state == 'waiting_ticket') {
            foreach(ADMIN_IDS as $admin_id) {
                $this->telegram->sendMessage($admin_id, "📞 تیکت جدید از کاربر {$chat_id}:\n\n{$text}");
            }
            $this->telegram->sendMessage($chat_id, "✅ تیکت شما ثبت شد. کد پیگیری: " . rand(1000, 9999));
            unset($_SESSION['user_state'][$chat_id]);
            $this->telegram->sendMessage($chat_id, "به منوی اصلی برگشتید.", $this->getMainKeyboard());
        }
    }
    
    private function handleCallback($callback) {
        // برای دکمه‌های اینلاین
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        
        $this->telegram->answerCallbackQuery($callback['id']);
        
        if(strpos($data, 'approve_') === 0) {
            $this->telegram->sendMessage($chat_id, "✅ آگهی تایید شد.");
        }
    }
    
}