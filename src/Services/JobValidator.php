<?php
namespace Services;

use Models\User;
use Models\Job;
use Services\TelegramService;

class JobValidator {
    private $telegramService;
    private $userModel;

    public function __construct() {
        $this->telegramService = new TelegramService(BOT_TOKEN);
        $this->userModel = new User();
    }

    /**
     * اعتبارسنجی کامل آگهی
     */
    public function validate($job_data, $user_id) {
        $errors = [];

        // اعتبارسنجی متن آگهی
        if (empty($job_data['content'])) {
            $errors[] = "متن آگهی نمی‌تواند خالی باشد";
        } elseif (strlen($job_data['content']) < 10) {
            $errors[] = "متن آگهی باید حداقل 10 کاراکتر باشد";
        } elseif (strlen($job_data['content']) > 1000) {
            $errors[] = "متن آگهی نباید بیشتر از 1000 کاراکتر باشد";
        }

        // اعتبارسنجی آیدی تلگرام
        if (empty($job_data['contact_id'])) {
            $errors[] = "آیدی تلگرام نمی‌تواند خالی باشد";
        } else {
            $contact_id = ltrim($job_data['contact_id'], '@');
            if (!$this->telegramService->checkUsernameValidity($contact_id)) {
                $errors[] = "آیدی تلگرام وارد شده معتبر نیست";
            }
        }

        // اعتبارسنجی محتوای اسپم
        if ($this->containsSpam($job_data['content'])) {
            $errors[] = "متن آگهی شما حاوی محتوای اسپم می‌باشد";
        }

        // محدودیت تعداد آگهی‌های در انتظار
        $pendingJobs = $this->countPendingJobs($user_id);
        if ($pendingJobs >= 3) {
            $errors[] = "شما 3 آگهی در انتظار تایید دارید. لطفاً منتظر بمانید";
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * بررسی محتوای اسپم
     */
    private function containsSpam($content) {
        $spamWords = ['شرکت هرمی', 'کریپتو', 'بیت‌کوین', 'ثروت آسان', 'درآمد میلیونی'];
        foreach ($spamWords as $word) {
            if (stripos($content, $word) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * تعداد آگهی‌های در انتظار کاربر
     */
    private function countPendingJobs($user_id) {
        $sql = "SELECT COUNT(*) as count FROM jobs WHERE user_id = :user_id AND status = 'pending'";
        $result = Database::query($sql, ['user_id' => $user_id]);
        return $result['count'];
    }

    /**
     * اعتبارسنجی کد تخفیف
     */
    public function validateDiscountCode($code) {
        $sql = "SELECT * FROM discounts WHERE code = :code AND expires_at > NOW() 
                AND (max_uses IS NULL OR used_count < max_uses)";
        $discount = Database::query($sql, ['code' => $code]);

        if (!$discount) {
            return ['is_valid' => false, 'message' => 'کد تخفیف معتبر نیست'];
        }

        return [
            'is_valid' => true,
            'amount' => $discount['amount'],
            'discount_id' => $discount['id']
        ];
    }

    /**
     * استفاده از کد تخفیف
     */
    public function useDiscountCode($code, $user_id) {
        $discount = $this->validateDiscountCode($code);

        if (!$discount['is_valid']) {
            return false;
        }

        // ثبت استفاده از کد تخفیف
        $sql = "UPDATE discounts SET used_count = used_count + 1 WHERE code = :code";
        Database::execute($sql, ['code' => $code]);

        // ثبت در جدول کاربران که از این کد استفاده کرده
        $sql = "INSERT INTO user_discounts (user_id, discount_id, used_at) 
                VALUES (:user_id, :discount_id, NOW())";
        Database::execute($sql, [
            'user_id' => $user_id,
            'discount_id' => $discount['discount_id']
        ]);

        return $discount['amount'];
    }
}