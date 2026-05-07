<?php
namespace Controllers;

use Models\Job;
use Models\User;
use Services\TelegramService;
use Helpers\Logger;

class AdminController {
    private $telegram;
    private $jobModel;
    private $userModel;

    public function __construct() {
        $this->telegram = new TelegramService(BOT_TOKEN);
        $this->jobModel = new Job();
        $this->userModel = new User();
    }

    public function handle($update) {
        $chat_id = $update['message']['chat']['id'];

        // بررسی ادمین بودن
        if (!in_array($chat_id, ADMIN_IDS)) {
            $this->telegram->sendMessage($chat_id, "⛔ شما دسترسی به این بخش ندارید!");
            return;
        }

        $text = $update['message']['text'] ?? '';

        if (strpos($text, '/admin') === 0) {
            $command = trim(str_replace('/admin', '', $text));
            $this->handleAdminCommand($chat_id, $command);
        }
    }

    private function handleAdminCommand($chat_id, $command) {
        switch ($command) {
            case 'stats':
                $this->showStats($chat_id);
                break;
            case 'pending':
                $this->showPendingJobs($chat_id);
                break;
            case 'users':
                $this->showUsers($chat_id);
                break;
            default:
                $this->showAdminMenu($chat_id);
        }
    }

    private function showAdminMenu($chat_id) {
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
                ]
            ]
        ];

        $message = "👨‍💼 پنل مدیریت\n\n";
        $message .= "از منوی زیر گزینه مورد نظر را انتخاب کنید:";

        $this->telegram->sendMessage($chat_id, $message, $keyboard);
    }

    private function showStats($chat_id) {
        // آمار کاربران
        $sql = "SELECT COUNT(*) as total_users FROM users";
        $total_users = Database::query($sql)['total_users'];

        $sql = "SELECT COUNT(*) as today_users FROM users WHERE DATE(created_at) = CURDATE()";
        $today_users = Database::query($sql)['today_users'];

        // آمار آگهی‌ها
        $sql = "SELECT 
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
                FROM jobs";
        $jobs_stats = Database::query($sql);

        // آمار مالی
        $sql = "SELECT SUM(amount) as total_income FROM transactions 
                WHERE type = 'deposit' AND status = 'completed'";
        $income = Database::query($sql);

        $message = "📊 <b>آمار کلی ربات</b>\n\n";
        $message .= "👥 <b>کاربران:</b>\n";
        $message .= "• کل کاربران: {$total_users}\n";
        $message .= "• کاربران امروز: {$today_users}\n\n";
        $message .= "📝 <b>آگهی‌ها:</b>\n";
        $message .= "• در انتظار تایید: {$jobs_stats['pending']}\n";
        $message .= "• فعال: {$jobs_stats['active']}\n";
        $message .= "• منقضی شده: {$jobs_stats['expired']}\n\n";
        $message .= "💰 <b>مالی:</b>\n";
        $message .= "• کل درآمد: " . number_format($income['total_income'] ?? 0) . " تومان";

        $this->telegram->sendMessage($chat_id, $message);
    }

    private function showPendingJobs($chat_id) {
        $sql = "SELECT j.*, u.username, u.telegram_id 
                FROM jobs j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = 'pending' 
                ORDER BY j.created_at DESC 
                LIMIT 10";

        $jobs = Database::queryAll($sql);

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
                    ],
                    [
                        ['text' => '✏️ ویرایش', 'callback_data' => "edit_job_{$job['id']}"]
                    ]
                ]
            ];

            $message = "📝 <b>آگهی جدید</b>\n\n";
            $message .= "<b>شناسه:</b> {$job['id']}\n";
            $message .= "<b>کاربر:</b> @{$job['username']}\n";
            $message .= "<b>زمان ثبت:</b> {$job['created_at']}\n\n";
            $message .= "<b>متن آگهی:</b>\n{$job['content']}\n\n";
            $message .= "<b>آیدی تماس:</b> {$job['contact_id']}";

            $this->telegram->sendMessage($chat_id, $message, $keyboard);
        }
    }

    public function approveJob($chat_id, $job_id) {
        // تایید آگهی
        $this->jobModel->approve($job_id);

        // دریافت اطلاعات آگهی
        $sql = "SELECT j.*, u.telegram_id FROM jobs j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.id = :job_id";
        $job = Database::query($sql, ['job_id' => $job_id]);

        if ($job) {
            // ارسال به کانال
            $result = $this->telegram->sendJobToChannel($job['content'], $job_id, $job['contact_id']);

            if ($result && isset($result['result']['message_id'])) {
                // ذخیره message_id کانال
                $sql = "UPDATE jobs SET channel_message_id = :msg_id WHERE id = :job_id";
                Database::execute($sql, [
                    'msg_id' => $result['result']['message_id'],
                    'job_id' => $job_id
                ]);
            }

            // اطلاع به کاربر
            $this->telegram->sendMessage($job['telegram_id'],
                "✅ آگهی شما تایید و در کانال منتشر شد.\n\n"
            );
        }

        $this->telegram->sendMessage($chat_id, "✅ آگهی شماره {$job_id} با موفقیت تایید شد.");
    }

    public function rejectJob($chat_id, $job_id, $reason = null) {
        $sql = "SELECT user_id, content FROM jobs WHERE id = :job_id";
        $job = Database::query($sql, ['job_id' => $job_id]);

        if ($job) {
            // دریافت اطلاعات کاربر
            $sql = "SELECT telegram_id FROM users WHERE id = :user_id";
            $user = Database::query($sql, ['user_id' => $job['user_id']]);

            // اطلاع به کاربر
            $message = "❌ متاسفانه آگهی شما تایید نشد.\n\n";
            if ($reason) {
                $message .= "دلیل: {$reason}\n\n";
            }
            $message .= "لطفاً آگهی خود را ویرایش کرده و مجدداً ارسال کنید.";

            $this->telegram->sendMessage($user['telegram_id'], $message);

            // حذف آگهی
            $sql = "DELETE FROM jobs WHERE id = :job_id";
            Database::execute($sql, ['job_id' => $job_id]);
        }

        $this->telegram->sendMessage($chat_id, "❌ آگهی شماره {$job_id} رد شد.");
    }
}