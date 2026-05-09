<?php
namespace Services;

use Models\User;
use Models\Job;
use Models\Wallet;
use Helpers\Validator;
use Helpers\Logger;

class JobService {
    private $userModel;
    private $jobModel;
    private $walletService;
    private $telegram;

    public function __construct($telegramService, $walletService) {
        $this->userModel = new User();
        $this->jobModel = new Job();
        $this->walletService = $walletService;
        $this->telegram = $telegramService;
    }

    public function startCreation($chat_id) {
        $_SESSION['user_state'][$chat_id] = 'waiting_job';
        $this->telegram->sendMessage($chat_id, "✏️ لطفاً متن آگهی خود را ارسال کنید:");
    }

    public function saveJobText($chat_id, $text) {
        if (!Validator::jobText($text)) {
            $this->telegram->sendMessage($chat_id, "❌ متن آگهی باید بین 10 تا 1000 کاراکتر باشد.\nلطفاً مجدداً ارسال کنید:");
            return false;
        }

        $_SESSION['temp_job'][$chat_id] = $text;
        $_SESSION['user_state'][$chat_id] = 'waiting_contact';
        $this->telegram->sendMessage($chat_id, "🆔 لطفاً آیدی تلگرام خود را وارد کنید:\n(مثال: @username)");
        return true;
    }

    public function saveContactAndSubmit($chat_id, $contact_id) {
        if (!Validator::username($contact_id)) {
            $this->telegram->sendMessage($chat_id, "❌ فرمت آیدی تلگرام صحیح نیست.\nلطفاً با @ شروع کنید و حداقل 5 کاراکتر وارد کنید.\nمثال: @username");
            return false;
        }

        $job_text = $_SESSION['temp_job'][$chat_id] ?? null;
        if (!$job_text) {
            $this->telegram->sendMessage($chat_id, "❌ خطا در ثبت آگهی. لطفاً دوباره تلاش کنید.");
            $this->startCreation($chat_id);
            return false;
        }

        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            $this->telegram->sendMessage($chat_id, "❌ کاربر یافت نشد. لطفاً /start را بزنید.");
            return false;
        }

        $balance = $this->walletService->getBalance($chat_id);

        if ($balance >= JOB_PRICE) {
            $deductResult = $this->walletService->deductJobFee($chat_id);

            if ($deductResult['success']) {
                $job_id = $this->jobModel->createJob($user['id'], $job_text, $contact_id);

                if ($job_id) {
                    $this->telegram->sendMessage($chat_id, "✅ آگهی شما ثبت شد و برای تایید به ادمین ارسال گردید.");

                    $keyboard = [
                        'inline_keyboard' => [[
                            ['text' => '✅ تایید آگهی', 'callback_data' => "approve_job_{$job_id}"],
                            ['text' => '❌ رد آگهی', 'callback_data' => "reject_job_{$job_id}"]
                        ]]
                    ];

                    $adminMessage = "📝 آگهی جدید از کاربر {$chat_id}:\n\n";
                    $adminMessage .= "متن آگهی:\n{$job_text}\n\n";
                    $adminMessage .= "📱 آیدی تماس: {$contact_id}";

                    $this->telegram->sendMessageToAdmins(ADMIN_IDS, $adminMessage, $keyboard);
                } else {
                    $this->walletService->increaseBalance($chat_id, JOB_PRICE, 'refund', 'برگشت هزینه - خطا در ثبت آگهی');
                    $this->telegram->sendMessage($chat_id, "❌ خطا در ثبت آگهی. لطفاً دوباره تلاش کنید.");
                }
            } else {
                $this->telegram->sendMessage($chat_id, "❌ خطا در کسر هزینه. لطفاً دوباره تلاش کنید.");
            }
        } else {
            $this->telegram->sendMessage($chat_id, "❌ موجودی کیف پول شما کافی نیست!\n💰 موجودی: " . number_format($balance) . " تومان\nلطفاً کیف پول خود را شارژ کنید.");
        }

        unset($_SESSION['user_state'][$chat_id]);
        unset($_SESSION['temp_job'][$chat_id]);

        return true;
    }

    public function approveJob($admin_id, $job_id) {
        $job = $this->jobModel->findJob($job_id);

        if (!$job) {
            $this->telegram->sendMessage($admin_id, "❌ آگهی یافت نشد.");
            return;
        }

        $result = $this->jobModel->approve($job_id);

        if ($result['status']) {
            $sendResult = $this->telegram->sendJobToChannel($job['content'], $job_id, $job['contact_id']);

            if ($sendResult && isset($sendResult['result']['message_id'])) {
                $this->jobModel->approve($job_id, $sendResult['result']['message_id']);
            }

            $this->telegram->sendMessage($job['telegram_id'], "✅ آگهی شما تایید و در کانال منتشر شد.");
            $this->telegram->sendMessage($admin_id, "✅ آگهی شماره {$job_id} با موفقیت تایید شد.");
        } else {
            $this->telegram->sendMessage($admin_id, "❌ خطا در تایید آگهی.");
        }
    }

    public function rejectJob($admin_id, $job_id, $reason = null) {
        $job = $this->jobModel->findJob($job_id);

        if (!$job) {
            $this->telegram->sendMessage($admin_id, "❌ آگهی یافت نشد.");
            return;
        }

        $result = $this->jobModel->reject($job_id);

        if ($result['status']) {
            $message = "❌ متاسفانه آگهی شما تایید نشد.\n\n";
            if ($reason) {
                $message .= "دلیل: {$reason}\n\n";
            }
            $message .= "لطفاً آگهی خود را ویرایش کرده و مجدداً ارسال کنید.";

            $this->telegram->sendMessage($job['telegram_id'], $message);

            $refundResult = $this->walletService->increaseBalance($job['telegram_id'], JOB_PRICE, 'refund', 'برگشت هزینه - رد آگهی');

            if ($refundResult['success']) {
                $this->telegram->sendMessage($job['telegram_id'], "💰 مبلغ " . number_format(JOB_PRICE) . " تومان به کیف پول شما برگشت داده شد.");
            }

            $this->telegram->sendMessage($admin_id, "❌ آگهی شماره {$job_id} رد شد.");
        } else {
            $this->telegram->sendMessage($admin_id, "❌ خطا در رد آگهی.");
        }
    }

    public function closeJob($user_id, $job_id) {
        $job = $this->jobModel->findJob($job_id);

        if (!$job || $job['user_id'] != $user_id) {
            return false;
        }

        if ($job['status'] != Job::STATUS_ACTIVE) {
            return false;
        }

        $result = $this->jobModel->expire($job_id);

        if ($result['status'] && $job['channel_message_id']) {
            $this->telegram->closeJobInChannel($job['channel_message_id'], $job['content'], $job_id, $job['contact_id']);
            $this->telegram->sendMessage($job['telegram_id'], "✅ آگهی شما با موفقیت بسته شد.");
        }

        return $result['status'];
    }

    public function getPendingJobs($admin_id) {
        $jobs = $this->jobModel->getPendingJobs(20);

        if (empty($jobs)) {
            $this->telegram->sendMessage($admin_id, "✅ هیچ آگهی در انتظار تاییدی وجود ندارد.");
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
            $message .= "<b>زمان ثبت:</b> {$job['created_at']}\n\n";
            $message .= "<b>متن آگهی:</b>\n{$job['content']}\n\n";
            $message .= "<b>آیدی تماس:</b> {$job['contact_id']}";

            $this->telegram->sendMessage($admin_id, $message, $keyboard);
        }
    }

    public function getUserJobs($chat_id, $limit = 10) {
        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            return [];
        }

        return $this->jobModel->getUserJobs($user['id'], $limit);
    }

    public function getJobStats() {
        return $this->jobModel->getStats();
    }

    public function searchJobs($keyword, $limit = 20) {
        if (mb_strlen($keyword) < 3) {
            return [];
        }

        return $this->jobModel->search($keyword, $limit);
    }
}