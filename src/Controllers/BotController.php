<?php
namespace Controllers;

use Services\UserService;
use Services\WalletService;
use Services\JobService;
use Services\DiscountService;
use Services\TicketService;
use Services\ReferralService;
use Services\TelegramService;
use Middleware\ChannelMembership;
use Helpers\Logger;

class BotController {
    private $telegram;
    private $userService;
    private $walletService;
    private $jobService;
    private $discountService;
    private $ticketService;
    private $referralService;
    private $channelMiddleware;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->userService = new UserService($this->telegram);
        $this->walletService = new WalletService($this->telegram);
        $this->jobService = new JobService($this->telegram, $this->walletService);
        $this->discountService = new DiscountService($this->telegram, $this->walletService);
        $this->ticketService = new TicketService($this->telegram);
        $this->referralService = new ReferralService($this->telegram);
        $this->channelMiddleware = new ChannelMembership($this->telegram, CHANNEL_ID);
    }

    public function handle($update) {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
        }
    }

    public function handleMessage($message) {
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $username = $message['chat']['username'] ?? '';

        if (!$this->channelMiddleware->check($chat_id)) {
            return;
        }

        if (isset($message['contact'])) {
            $this->userService->handlePhoneNumber($chat_id, $username, $message['contact']);
            return;
        }

        if (!$this->userService->isUserExists($chat_id) && $text != '/start') {
            $this->telegram->sendMessage($chat_id, "❌ لطفاً ابتدا /start را بزنید و شماره خود را ارسال کنید.");
            return;
        }

        switch ($text) {
            case '/start':
                $this->handleStartCommand($chat_id, $username, $message);
                break;
            case '📝 ارسال آگهی':
                $this->jobService->startCreation($chat_id);
                break;
            case '💰 کیف پول من':
                $this->walletService->showWallet($chat_id);
                break;
            case '💳 شارژ کیف پول':
//                $this->discountService->startDiscount($chat_id);
                break;
            case '👥 دعوت از دوستان':
                $this->referralService->showReferralInfo($chat_id);
                break;
            case '📞 پشتیبانی':
                $this->ticketService->startTicket($chat_id);
                break;
            case 'ℹ️ راهنما':
                $this->showHelp($chat_id);
                break;
            case '❌ انصراف':
                $this->userService->clearUserState($chat_id);
                $this->telegram->sendMessage($chat_id, "❌ عملیات لغو شد.", $this->getMainKeyboard());
                break;
            default:
                $this->handleUserState($chat_id, $text);
        }
    }

    public function handleStartCommand($chat_id, $username, $referral_code = null) {
        $this->userService->handleStart($chat_id, $username, $referral_code);
    }

    public function handleCallback($callback) {
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        $callback_id = $callback['id'];
        $message_id = $callback['message']['message_id'];

        $this->telegram->answerCallbackQuery($callback_id, 'در حال پردازش...');

        // ارسال پیام برای دوستان
        if (strpos($data, 'forward_message_') === 0) {
            $referral_code = str_replace('forward_message_', '', $data);
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $referral_code;

            $message = "🎁 به ربات آگهی‌های کاری دعوتت می‌کنم!\n\n";
            $message .= "✨ با ثبت‌نام در این ربات، 10,000 تومان هدیه ثبت‌نام دریافت می‌کنی!\n\n";
            $message .= "🔗 لینک عضویت:\n";
            $message .= $link . "\n\n";
            $message .= "📝 چه امکاناتی داره؟\n";
            $message .= "• ارسال آگهی استخدام\n";
            $message .= "• پیدا کردن شغل مناسب\n";
            $message .= "• دریافت پاداش معرف دوستان (2,000 تومان هر نفر)\n\n";
            $message .= "🚀 همین الان عضو شو و از امکانات استفاده کن!\n\n";
            $message .= "────────────\n";
            $message .= "@" . BOT_USERNAME;

            $shareKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '📤 ارسال به مخاطب', 'switch_inline_query' => $message],
                        ['text' => '📋 کپی متن', 'callback_data' => "copy_text_{$referral_code}"]
                    ]
                ]
            ];

            $this->telegram->sendMessage($chat_id, $message, $shareKeyboard);
            $this->telegram->answerCallbackQuery($callback_id, "✅ پیام آماده ارسال شد!");
            return;
        }

        // کپی لینک
        if (strpos($data, 'copy_link_') === 0) {
            $referral_code = str_replace('copy_link_', '', $data);
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $referral_code;
            $this->telegram->answerCallbackQuery($callback_id, "✅ لینک کپی شد!", true);
            $this->telegram->sendMessage($chat_id, "🔗 لینک دعوت شما:\n" . $link);
            return;
        }

        // کپی متن کامل
        if (strpos($data, 'copy_text_') === 0) {
            $referral_code = str_replace('copy_text_', '', $data);
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $referral_code;

            $text = "🎁 به ربات آگهی‌های کاری دعوتت می‌کنم!\n\n";
            $text .= "✨ با ثبت‌نام در این ربات، 10,000 تومان هدیه ثبت‌نام دریافت می‌کنی!\n\n";
            $text .= "🔗 لینک عضویت:\n";
            $text .= $link . "\n\n";
            $text .= "📝 چه امکاناتی داره؟\n";
            $text .= "• ارسال آگهی استخدام\n";
            $text .= "• پیدا کردن شغل مناسب\n";
            $text .= "• دریافت پاداش معرف دوستان (2,000 تومان هر نفر)\n\n";
            $text .= "🚀 همین الان عضو شو و از امکانات استفاده کن!\n\n";
            $text .= "────────────\n";
            $text .= "@" . BOT_USERNAME;

            $this->telegram->answerCallbackQuery($callback_id, "✅ متن کپی شد!", true);
            $this->telegram->sendMessage($chat_id, "📝 متن آماده ارسال:\n\n" . $text);
            return;
        }

        if ($data == 'back_to_menu') {
            $this->telegram->sendMessage($chat_id, "🔙 به منوی اصلی بازگشتید.", $this->getMainKeyboard());
            return;
        }
    }

    private function handleUserState($chat_id, $text) {
        $state = $this->userService->getUserState($chat_id);

        switch ($state) {
            case 'waiting_job':
                $this->jobService->saveJobText($chat_id, $text);
                break;
            case 'waiting_contact':
                $this->jobService->saveContactAndSubmit($chat_id, $text);
                break;
            case 'waiting_discount':
                $this->discountService->applyDiscount($chat_id, $text);
                $this->userService->clearUserState($chat_id);
                break;
            case 'waiting_ticket':
                $this->ticketService->createTicket($chat_id, $text);
                $this->userService->clearUserState($chat_id);
                $this->telegram->sendMessage($chat_id, "به منوی اصلی برگشتید.", $this->getMainKeyboard());
                break;
            default:
                $this->telegram->sendMessage($chat_id, "❌ دستور نامعتبر. لطفاً از منوی اصلی استفاده کنید.", $this->getMainKeyboard());
        }
    }

    private function showHelp($chat_id) {
        $help = "📖 <b>راهنمای ربات</b>\n\n";
        $help .= "1️⃣ <b>ارسال آگهی:</b>\n   هزینه: " . number_format(JOB_PRICE) . " تومان\n\n";
        $help .= "2️⃣ <b>کیف پول:</b>\n   مقدار موجودی و تراکنش ها\n\n";
        $help .= "3️⃣ <b>شارژ کیف پول:</b>\n   شارژ کیف پول\n\n ";
        $help .= "4️⃣ <b>دعوت از دوستان:</b>\n   هر دعوت " . number_format(REFERRAL_BONUS) . " تومان پاداش\n\n";
        $help .= "5️⃣ <b>پشتیبانی:</b>\n   از دکمه پشتیبانی استفاده کنید";

        $this->telegram->sendMessage($chat_id, $help);
    }

    public function getMainKeyboard() {
        return [
            'keyboard' => [
                [['text' => '📝 ارسال آگهی'], ['text' => '💰 کیف پول من']],
                [['text' =>'💳 شارژ کیف پول'], ['text' => '👥 دعوت از دوستان']],
                [['text' => '📞 پشتیبانی'], ['text' => 'ℹ️ راهنما']]
            ],
            'resize_keyboard' => true
        ];
    }

    public function backToMenu($chat_id) {
        $this->telegram->sendMessage($chat_id, "🔙 به منوی اصلی بازگشتید.", $this->getMainKeyboard());
    }

    public function handlePayment($chat_id, $amount) {
        $paymentService = new \Services\PaymentService($this->telegram);
        $paymentService->handlePaymentCommand($chat_id, $amount);
    }

    public function searchJobs($query) {
        return $this->jobService->searchJobs($query);
    }

    public function handleCheckMembership($callback) {
        $chat_id = $callback['message']['chat']['id'];
        $callback_id = $callback['id'];
        $message_id = $callback['message']['message_id'];
        $username = $callback['message']['chat']['username'] ?? '';

        // بررسی مجدد عضویت در کانال با استفاده از channelMiddleware
        $isMember = $this->channelMiddleware->isMember($chat_id);

        if ($isMember) {
            // حذف پیام قبلی
            $this->telegram->deleteMessage($chat_id, $message_id);

            // پاسخ به callback
            $this->telegram->answerCallbackQuery($callback_id, "✅ عضویت شما تأیید شد!");

            // بررسی اینکه کاربر قبلاً ثبت‌نام کرده یا نه
            if (!$this->userService->isUserExists($chat_id)) {
                // کاربر ثبت‌نام نکرده - درخواست شماره بفرست
                $this->userService->handleStart($chat_id, $username, null);
            } else {
                // کاربر ثبت‌نام کرده - منوی اصلی رو نشون بده
                $this->telegram->sendMessage($chat_id,
                    "✅ <b>عضویت شما تأیید شد!</b>\n\nبه ربات خوش آمدید. از منوی اصلی استفاده کنید.",
                    $this->getMainKeyboard()
                );
            }
        } else {
            $this->telegram->answerCallbackQuery($callback_id, "❌ شما هنوز عضو کانال نشده‌اید! لطفاً ابتدا عضو شوید.", true);
        }
    }

}