<?php
namespace Services;

use Models\User;
use Models\Job;
use Helpers\Logger;

class BroadcastService {
    private $userModel;
    private $jobModel;
    private $telegram;

    public function __construct($telegramService) {
        $this->userModel = new User();
        $this->jobModel = new Job();
        $this->telegram = $telegramService;
    }

    public function startBroadcast($admin_id) {
        $_SESSION['admin_broadcast'] = true;
        $_SESSION['broadcast_step'] = 'message';

        $this->telegram->sendMessage($admin_id,
            "📢 <b>ارسال پیام همگانی</b>\n\n"
            . "لطفاً پیامی که می‌خواهید برای همه کاربران ارسال شود را وارد کنید:\n\n"
            . "📝 می‌توانید از HTML استفاده کنید (مثال: <b>متن پررنگ</b>, <i>متن کج</i>)\n\n"
            . "⏱️ این عملیات ممکن است چند دقیقه طول بکشد.\n\n"
            . "❌ برای لغو، /cancel را بفرستید."
        );
    }

    public function setBroadcastMessage($admin_id, $message) {
        if (strlen($message) < 5) {
            $this->telegram->sendMessage($admin_id, "❌ پیام باید حداقل 5 کاراکتر باشد. لطفاً دوباره وارد کنید:");
            return false;
        }

        $_SESSION['broadcast_message'] = $message;
        $_SESSION['broadcast_step'] = 'confirm';

        $preview = mb_substr($message, 0, 200);
        if (mb_strlen($message) > 200) {
            $preview .= "...";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، ارسال شود', 'callback_data' => 'broadcast_confirm'],
                    ['text' => '❌ خیر، لغو شود', 'callback_data' => 'broadcast_cancel']
                ]
            ]
        ];

        $this->telegram->sendMessage($admin_id,
            "📋 <b>پیش‌نمایش پیام:</b>\n\n"
            . "———————————\n"
            . "{$preview}\n"
            . "———————————\n\n"
            . "⚠️ این پیام برای <b>همه کاربران</b> ارسال خواهد شد.\n"
            . "آیا مطمئن هستید؟",
            $keyboard
        );

        return true;
    }

    public function sendBroadcastToAll($admin_id, $message) {
        $startTime = microtime(true);

        $sql = "SELECT telegram_id FROM users WHERE status = 1";
        $result = $this->userModel->rawQuery($sql);

        if (!$result['status'] || empty($result['details'])) {
            $this->telegram->sendMessage($admin_id, "❌ هیچ کاربر فعالی برای ارسال پیام وجود ندارد.");
            $this->clearBroadcastSession();
            return;
        }

        $users = $result['details'];
        $totalUsers = count($users);
        $successCount = 0;
        $failCount = 0;
        $failedUsers = [];

        $statusMessage = $this->telegram->sendMessage($admin_id,
            "⏳ در حال ارسال پیام همگانی...\n\n"
            . "👥 کل کاربران: {$totalUsers}\n"
            . "✅ ارسال موفق: 0\n"
            . "❌ ارسال ناموفق: 0"
        );

        for ($i = 0; $i < $totalUsers; $i++) {
            $user = $users[$i];
            $response = $this->telegram->sendMessage($user['telegram_id'], $message);

            if (isset($response['ok']) && $response['ok']) {
                $successCount++;
            } else {
                $failCount++;
                $failedUsers[] = $user['telegram_id'];
            }

            if (($i + 1) % 10 == 0 || ($i + 1) == $totalUsers) {
                $this->telegram->editMessageText(
                    $admin_id,
                    $statusMessage['result']['message_id'],
                    "⏳ در حال ارسال پیام همگانی...\n\n"
                    . "👥 کل کاربران: {$totalUsers}\n"
                    . "✅ ارسال موفق: {$successCount}\n"
                    . "❌ ارسال ناموفق: {$failCount}\n"
                    . "📊 پیشرفت: " . round(($i + 1) / $totalUsers * 100, 1) . "%"
                );
            }

            usleep(50000);
        }

        $elapsedTime = round(microtime(true) - $startTime, 2);

        $finalMessage = "✅ <b>ارسال پیام همگانی کامل شد!</b>\n\n"
            . "📊 <b>آمار ارسال:</b>\n"
            . "• کل کاربران: {$totalUsers}\n"
            . "• ارسال موفق: {$successCount}\n"
            . "• ارسال ناموفق: {$failCount}\n"
            . "• زمان اجرا: {$elapsedTime} ثانیه\n\n";

        if (!empty($failedUsers) && count($failedUsers) <= 10) {
            $finalMessage .= "❌ <b>کاربران ناموفق:</b>\n";
            foreach ($failedUsers as $id) {
                $finalMessage .= "• {$id}\n";
            }
        } elseif (!empty($failedUsers)) {
            $finalMessage .= "❌ تعداد کاربران ناموفق: " . count($failedUsers) . " نفر";
        }

        $this->telegram->sendMessage($admin_id, $finalMessage);

        Logger::info("Broadcast completed: total={$totalUsers}, success={$successCount}, fail={$failCount}, time={$elapsedTime}s");

        $this->clearBroadcastSession();

        return ['success' => $successCount, 'fail' => $failCount];
    }

    public function sendBroadcastToActiveUsers($admin_id, $message) {
        $sql = "SELECT telegram_id FROM users WHERE status = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $result = $this->userModel->rawQuery($sql);

        if (!$result['status'] || empty($result['details'])) {
            $this->telegram->sendMessage($admin_id, "❌ هیچ کاربر فعالی در 30 روز اخیر وجود ندارد.");
            return;
        }

        $users = $result['details'];
        $successCount = 0;

        foreach ($users as $user) {
            $response = $this->telegram->sendMessage($user['telegram_id'], $message);
            if (isset($response['ok']) && $response['ok']) {
                $successCount++;
            }
            usleep(50000);
        }

        $this->telegram->sendMessage($admin_id,
            "✅ پیام به کاربران فعال ارسال شد.\n\n"
            . "👥 تعداد: {$successCount} نفر"
        );

        return $successCount;
    }

    public function sendBroadcastToAdmins($admin_id, $message) {
        $successCount = 0;

        foreach (ADMIN_IDS as $admin) {
            if ($admin && $admin != $admin_id) {
                $response = $this->telegram->sendMessage($admin, $message);
                if (isset($response['ok']) && $response['ok']) {
                    $successCount++;
                }
                usleep(50000);
            }
        }

        $this->telegram->sendMessage($admin_id,
            "✅ پیام به سایر ادمین‌ها ارسال شد.\n\n"
            . "👥 تعداد: {$successCount} نفر"
        );

        return $successCount;
    }

    public function sendTestBroadcast($admin_id, $testUserId, $message) {
        $user = $this->userModel->findByTelegramId($testUserId);

        if (!$user) {
            $this->telegram->sendMessage($admin_id, "❌ کاربر با آیدی {$testUserId} یافت نشد.");
            return false;
        }

        $response = $this->telegram->sendMessage($testUserId, $message);

        if (isset($response['ok']) && $response['ok']) {
            $this->telegram->sendMessage($admin_id, "✅ پیام تست به کاربر @{$user['username']} ارسال شد.");
            return true;
        } else {
            $this->telegram->sendMessage($admin_id, "❌ ارسال پیام تست ناموفق بود.");
            return false;
        }
    }

    public function scheduleBroadcast($admin_id, $message, $scheduleTime) {
        $this->telegram->sendMessage($admin_id,
            "⏰ زمان‌بندی ارسال پیام همگانی\n\n"
            . "📝 پیام: " . mb_substr($message, 0, 100) . "...\n"
            . "⏱️ زمان: {$scheduleTime}\n\n"
            . "⚠️ این قابلیت در حال توسعه است. به زودی اضافه خواهد شد."
        );

        Logger::info("Broadcast scheduled by admin {$admin_id} for {$scheduleTime}");
        return false;
    }

    public function cancelBroadcast($admin_id) {
        $this->clearBroadcastSession();
        $this->telegram->sendMessage($admin_id, "❌ عملیات ارسال پیام همگانی لغو شد.");
    }

    public function getBroadcastStatus($admin_id) {
        $step = $_SESSION['broadcast_step'] ?? null;
        $message = $_SESSION['broadcast_message'] ?? null;

        if (!$step) {
            $this->telegram->sendMessage($admin_id, "📭 هیچ عملیات ارسال پیام همگانی فعالی وجود ندارد.");
            return;
        }

        $statusMessage = "📊 <b>وضعیت عملیات ارسال پیام همگانی</b>\n\n";

        if ($step == 'message') {
            $statusMessage .= "📍 مرحله: انتظار برای وارد کردن پیام\n";
            $statusMessage .= "💡 لطفاً پیام خود را وارد کنید.";
        } elseif ($step == 'confirm') {
            $statusMessage .= "📍 مرحله: انتظار برای تایید\n";
            $statusMessage .= "📝 پیش‌نمایش پیام:\n———————————\n";
            $statusMessage .= mb_substr($message, 0, 150);
            if (mb_strlen($message) > 150) $statusMessage .= "...";
            $statusMessage .= "\n———————————\n";
            $statusMessage .= "💡 برای تایید ارسال، روی دکمه مورد نظر کلیک کنید.";
        }

        $this->telegram->sendMessage($admin_id, $statusMessage);
    }

    private function clearBroadcastSession() {
        unset($_SESSION['admin_broadcast']);
        unset($_SESSION['broadcast_step']);
        unset($_SESSION['broadcast_message']);
    }

    public function isBroadcasting($admin_id) {
        return isset($_SESSION['admin_broadcast']) && $_SESSION['admin_broadcast'] === true;
    }

    public function getBroadcastStep() {
        return $_SESSION['broadcast_step'] ?? null;
    }

    public function getBroadcastMessage() {
        return $_SESSION['broadcast_message'] ?? null;
    }
}