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
            case '🎁 کد تخفیف':
                $this->discountService->startDiscount($chat_id);
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

    private function handleStartCommand($chat_id, $username, $message) {
        $text = $message['text'] ?? '';
        $referralCode = $this->referralService->extractReferralCodeFromStart($text);
        $this->userService->handleStart($chat_id, $username, $referralCode);
    }

    public function handleCallback($callback) {
        $chat_id = $callback['message']['chat']['id'];
        $data = $callback['data'];
        $callback_id = $callback['id'];

        $this->telegram->answerCallbackQuery($callback_id, 'در حال پردازش...');

        if (strpos($data, 'copy_link_') === 0) {
            $referral_code = str_replace('copy_link_', '', $data);
            $link = "https://t.me/" . BOT_USERNAME . "?start=" . $referral_code;
            $this->telegram->answerCallbackQuery($callback_id, "✅ لینک کپی شد!", true);
            $this->telegram->sendMessage($chat_id, "🔗 لینک دعوت شما:\n<code>{$link}</code>");
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
        $help .= "2️⃣ <b>کیف پول:</b>\n   دستور /pay [مبلغ]\n\n";
        $help .= "3️⃣ <b>کد تخفیف:</b>\n   از ادمین دریافت کنید\n\n";
        $help .= "4️⃣ <b>دعوت از دوستان:</b>\n   هر دعوت " . number_format(REFERRAL_BONUS) . " تومان پاداش\n\n";
        $help .= "5️⃣ <b>پشتیبانی:</b>\n   از دکمه پشتیبانی استفاده کنید";

        $this->telegram->sendMessage($chat_id, $help);
    }

    public function getMainKeyboard() {
        return [
            'keyboard' => [
                [['text' => '📝 ارسال آگهی'], ['text' => '💰 کیف پول من']],
                [['text' => '🎁 کد تخفیف'], ['text' => '👥 دعوت از دوستان']],
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
}