<?php
namespace Services;

use Models\User;
use Models\Job;
use Models\Transaction;
use Models\Ticket;
use Helpers\Logger;

class AdminService {
    private $userModel;
    private $jobModel;
    private $transactionModel;
    private $ticketModel;
    private $telegram;

    public function __construct($telegramService) {
        $this->userModel = new User();
        $this->jobModel = new Job();
        $this->transactionModel = new Transaction();
        $this->ticketModel = new Ticket();
        $this->telegram = $telegramService;
    }

    public function isAdmin($chat_id) {
        return in_array($chat_id, ADMIN_IDS);
    }

    public function showAdminMenu($chat_id) {
        if (!$this->isAdmin($chat_id)) {
            $this->telegram->sendMessage($chat_id, "⛔ شما دسترسی به این بخش ندارید!");
            return;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📊 آمار', 'callback_data' => 'admin_stats'],
                    ['text' => '⏳ آگهی‌های در انتظار', 'callback_data' => 'admin_pending']
                ],
                [
                    ['text' => '👥 لیست کاربران', 'callback_data' => 'admin_users'],
                    ['text' => '💰 تراکنش‌ها', 'callback_data' => 'admin_transactions']
                ],
                [
                    ['text' => '🎁 کد تخفیف جدید', 'callback_data' => 'admin_add_discount'],
                    ['text' => '📊 گزارش مالی', 'callback_data' => 'admin_financial']
                ],
                [
                    ['text' => '🎫 تیکت‌های باز', 'callback_data' => 'admin_tickets'],
                    ['text' => '📢 پیام همگانی', 'callback_data' => 'admin_broadcast']
                ]
            ]
        ];

        $message = "👨‍💼 <b>پنل مدیریت</b>\n\n";
        $message .= "از منوی زیر گزینه مورد نظر را انتخاب کنید:";

        $this->telegram->sendMessage($chat_id, $message, $keyboard);
    }

    public function showStats($chat_id) {
        if (!$this->isAdmin($chat_id)) return;

        $totalUsers = $this->userModel->count();

        $sql = "SELECT COUNT(*) as today FROM users WHERE DATE(created_at) = CURDATE()";
        $result = $this->userModel->rawQuery($sql);
        $todayUsers = $result['status'] && $result['details'] ? $result['details'][0]['today'] : 0;

        $jobStats = $this->jobModel->getStats();
        $financialStats = $this->transactionModel->getFinancialStats();
        $ticketStats = $this->ticketModel->getStats();

        $message = "📊 <b>آمار کلی ربات</b>\n\n";
        $message .= "👥 <b>کاربران:</b>\n";
        $message .= "• کل کاربران: " . number_format($totalUsers) . "\n";
        $message .= "• کاربران امروز: " . number_format($todayUsers) . "\n\n";

        $message .= "📝 <b>آگهی‌ها:</b>\n";
        $message .= "• در انتظار تایید: " . number_format($jobStats['pending'] ?? 0) . "\n";
        $message .= "• فعال: " . number_format($jobStats['active'] ?? 0) . "\n";
        $message .= "• منقضی شده: " . number_format($jobStats['expired'] ?? 0) . "\n";
        $message .= "• رد شده: " . number_format($jobStats['rejected'] ?? 0) . "\n\n";

        $message .= "💰 <b>مالی:</b>\n";
        $message .= "• کل شارژها: " . number_format($financialStats['total_deposits'] ?? 0) . " تومان\n";
        $message .= "• کل هزینه آگهی‌ها: " . number_format($financialStats['total_job_payments'] ?? 0) . " تومان\n";
        $message .= "• کل پاداش referrals: " . number_format($financialStats['total_referral_bonus'] ?? 0) . " تومان\n\n";

        $message .= "🎫 <b>تیکت‌ها:</b>\n";
        $message .= "• باز: " . number_format($ticketStats['open'] ?? 0) . "\n";
        $message .= "• در حال بررسی: " . number_format($ticketStats['in_progress'] ?? 0) . "\n";
        $message .= "• بسته شده: " . number_format($ticketStats['closed'] ?? 0);

        $this->telegram->sendMessage($chat_id, $message);
    }

    public function showPendingJobs($chat_id) {
        if (!$this->isAdmin($chat_id)) return;

        $jobs = $this->jobModel->getPendingJobs(20);

        if (empty($jobs)) {
            $this->telegram->sendMessage($chat_id, "✅ هیچ آگهی در انتظار تاییدی وجود ندارد.");
            return;
        }

        foreach ($jobs as $job) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ تایید', 'callback_data' => "approve_job_{$job['id']}"],
                        ['text' => '❌ رد', 'callback_data' => "reject_job_{$job['id']}"]
                    ]
                ]
            ];

            $message = "📝 <b>آگهی جدید</b>\n\n";
            $message .= "<b>شناسه:</b> {$job['id']}\n";
            $message .= "<b>کاربر:</b> @{$job['username']}\n";
            $message .= "<b>آیدی کاربر:</b> {$job['telegram_id']}\n";
            $message .= "<b>زمان ثبت:</b> {$job['created_at']}\n\n";
            $message .= "<b>متن آگهی:</b>\n{$job['content']}\n\n";
            $message .= "<b>آیدی تماس:</b> {$job['contact_id']}";

            $this->telegram->sendMessage($chat_id, $message, $keyboard);
        }
    }

    public function broadcastMessage($admin_id, $message) {
        if (!$this->isAdmin($admin_id)) return;

        $_SESSION['admin_broadcast'] = true;
        $this->telegram->sendMessage($admin_id, "📢 لطفاً پیامی که می‌خواهید برای همه کاربران ارسال شود را وارد کنید:\n\n(برای لغو /cancel را بفرستید)");
    }

    public function sendBroadcast($admin_id, $message) {
        if (!$this->isAdmin($admin_id)) return;

        $sql = "SELECT telegram_id FROM users WHERE status = 1";
        $result = $this->userModel->rawQuery($sql);

        if (!$result['status'] || empty($result['details'])) {
            $this->telegram->sendMessage($admin_id, "❌ هیچ کاربری برای ارسال پیام وجود ندارد.");
            return;
        }

        $users = $result['details'];
        $successCount = 0;
        $failCount = 0;

        foreach ($users as $user) {
            $msg = "📢 <b>پیام همگانی</b>\n\n" . $message;
            $response = $this->telegram->sendMessage($user['telegram_id'], $msg);

            if (isset($response['ok']) && $response['ok']) {
                $successCount++;
            } else {
                $failCount++;
            }

            usleep(50000); // تاخیر 50ms برای جلوگیری از محدودیت تلگرام
        }

        $this->telegram->sendMessage($admin_id, "✅ پیام همگانی ارسال شد.\n\n📊 تعداد ارسال موفق: {$successCount}\n❌ تعداد ناموفق: {$failCount}");
        unset($_SESSION['admin_broadcast']);
    }

    public function showUsers($admin_id, $page = 1, $limit = 20) {
        if (!$this->isAdmin($admin_id)) return;

        $offset = ($page - 1) * $limit;
        $sql = "SELECT id, telegram_id, username, phone, created_at FROM users ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $result = $this->userModel->rawQuery($sql, ['limit' => $limit, 'offset' => $offset]);

        if (!$result['status'] || empty($result['details'])) {
            $this->telegram->sendMessage($admin_id, "📭 هیچ کاربری یافت نشد.");
            return;
        }

        $message = "👥 <b>لیست کاربران</b>\n\n";
        foreach ($result['details'] as $user) {
            $message .= "🆔 ID: {$user['id']}\n";
            $message .= "👤 نام کاربری: @{$user['username']}\n";
            $message .= "📱 تلفن: {$user['phone']}\n";
            $message .= "📅 عضویت: " . date('Y/m/d', strtotime($user['created_at'])) . "\n";
            $message .= "———————————\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⬅️ قبلی', 'callback_data' => 'admin_users_prev_' . ($page - 1)],
                    ['text' => "صفحه {$page}", 'callback_data' => 'admin_users_page'],
                    ['text' => '➡️ بعدی', 'callback_data' => 'admin_users_next_' . ($page + 1)]
                ]
            ]
        ];

        $this->telegram->sendMessage($admin_id, $message, $keyboard);
    }

    public function showTransactions($admin_id, $page = 1, $limit = 20) {
        if (!$this->isAdmin($admin_id)) return;

        $offset = ($page - 1) * $limit;
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM transactions t 
                JOIN users u ON t.user_id = u.id 
                ORDER BY t.created_at DESC 
                LIMIT :limit OFFSET :offset";
        $result = $this->transactionModel->rawQuery($sql, ['limit' => $limit, 'offset' => $offset]);

        if (!$result['status'] || empty($result['details'])) {
            $this->telegram->sendMessage($admin_id, "📭 هیچ تراکنشی یافت نشد.");
            return;
        }

        $message = "💰 <b>لیست تراکنش‌ها</b>\n\n";
        foreach ($result['details'] as $trans) {
            $amountFormatted = number_format($trans['amount']);
            $sign = $trans['amount'] >= 0 ? '+' : '';

            $message .= "🆔 ID: {$trans['id']}\n";
            $message .= "👤 کاربر: @{$trans['username']}\n";
            $message .= "💰 مبلغ: {$sign}{$amountFormatted} تومان\n";
            $message .= "📋 نوع: {$trans['type']}\n";
            $message .= "📊 وضعیت: {$trans['status']}\n";
            $message .= "📅 تاریخ: " . date('Y/m/d H:i', strtotime($trans['created_at'])) . "\n";
            $message .= "———————————\n";
        }

        $this->telegram->sendMessage($admin_id, $message);
    }

    public function showFinancialReport($admin_id) {
        if (!$this->isAdmin($admin_id)) return;

        $stats = $this->transactionModel->getFinancialStats();

        $sql = "SELECT SUM(balance) as total_wallet FROM wallets";
        $walletResult = $this->userModel->rawQuery($sql);
        $totalWallet = $walletResult['status'] && $walletResult['details'] ? $walletResult['details'][0]['total_wallet'] : 0;

        $message = "📊 <b>گزارش مالی</b>\n\n";
        $message .= "💰 <b>درآمد کل:</b>\n";
        $message .= "• کل شارژها: " . number_format($stats['total_deposits'] ?? 0) . " تومان\n";
        $message .= "• کل هزینه آگهی‌ها: " . number_format($stats['total_job_payments'] ?? 0) . " تومان\n";
        $message .= "• کل پاداش referrals: " . number_format($stats['total_referral_bonus'] ?? 0) . " تومان\n";
        $message .= "• کل پاداش خوش‌آمدگویی: " . number_format($stats['total_welcome_bonus'] ?? 0) . " تومان\n\n";

        $totalIncome = ($stats['total_deposits'] ?? 0);
        $totalExpense = ($stats['total_job_payments'] ?? 0) + ($stats['total_referral_bonus'] ?? 0) + ($stats['total_welcome_bonus'] ?? 0);
        $profit = $totalIncome - $totalExpense;

        $message .= "📈 <b>خلاصه مالی:</b>\n";
        $message .= "• مجموع موجودی کیف پول کاربران: " . number_format($totalWallet) . " تومان\n";
        $message .= "• سود خالص: " . number_format($profit) . " تومان\n";
        $message .= "• حاشیه سود: " . ($totalIncome > 0 ? round(($profit / $totalIncome) * 100, 2) : 0) . "%";

        $this->telegram->sendMessage($admin_id, $message);
    }

    public function showTickets($admin_id) {
        if (!$this->isAdmin($admin_id)) return;

        $tickets = $this->ticketModel->getOpenTickets(50);

        if (empty($tickets)) {
            $this->telegram->sendMessage($admin_id, "✅ هیچ تیکت در انتظار پاسخی وجود ندارد.");
            return;
        }

        $message = "🎫 <b>تیکت‌های در انتظار پاسخ</b>\n\n";
        foreach ($tickets as $ticket) {
            $message .= "<b>تیکت #{$ticket['id']}</b>\n";
            $message .= "👤 کاربر: @{$ticket['username']}\n";
            $message .= "📝 موضوع: {$ticket['subject']}\n";
            $message .= "📅 تاریخ: " . date('Y/m/d H:i', strtotime($ticket['created_at'])) . "\n";
            $message .= "———————————\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📞 پاسخ به تیکت', 'callback_data' => 'admin_reply_ticket']
                ]
            ]
        ];

        $this->telegram->sendMessage($admin_id, $message, $keyboard);
    }

    public function addDiscountCode($admin_id) {
        if (!$this->isAdmin($admin_id)) return;

        $_SESSION['admin_discount'] = true;
        $this->telegram->sendMessage($admin_id, "🎁 لطفاً اطلاعات کد تخفیف را به فرمت زیر وارد کنید:\n\n<code>CODE|AMOUNT|MAX_USES|DAYS</code>\n\nمثال:\n<code>WELCOME10|10000|50|30</code>\n\n(برای لغو /cancel را بفرستید)");
    }

    public function processAddDiscount($admin_id, $input) {
        if (!$this->isAdmin($admin_id)) return;

        $parts = explode('|', $input);
        if (count($parts) != 4) {
            $this->telegram->sendMessage($admin_id, "❌ فرمت نامعتبر. لطفاً دوباره تلاش کنید:\n\n<code>CODE|AMOUNT|MAX_USES|DAYS</code>");
            return;
        }

        list($code, $amount, $maxUses, $days) = $parts;
        $code = trim(strtoupper($code));
        $amount = (int)$amount;
        $maxUses = (int)$maxUses;
        $days = (int)$days;

        if (empty($code) || $amount <= 0 || $maxUses <= 0 || $days <= 0) {
            $this->telegram->sendMessage($admin_id, "❌ مقادیر وارد شده نامعتبر هستند.");
            return;
        }

        $discountService = new DiscountService($this->telegram, null);
        $result = $discountService->createDiscount($admin_id, $code, $amount, $maxUses, $days);

        if ($result) {
            unset($_SESSION['admin_discount']);
        }
    }
}