<?php
namespace Services;

use Models\User;
use Models\Discount;
use Models\Wallet;
use Helpers\Validator;
use Helpers\Logger;

class DiscountService {
    private $discountModel;
    private $userModel;
    private $walletService;
    private $telegram;

    public function __construct($telegramService, $walletService) {
        $this->discountModel = new Discount();
        $this->userModel = new User();
        $this->walletService = $walletService;
        $this->telegram = $telegramService;
    }

    public function startDiscount($chat_id) {
        $_SESSION['user_state'][$chat_id] = 'waiting_discount';
        $this->telegram->sendMessage($chat_id, "🎁 کد تخفیف خود را وارد کنید:");
    }

    public function applyDiscount($chat_id, $code) {
        $code = trim(strtoupper($code));

        if (!Validator::discountCode($code)) {
            $this->telegram->sendMessage($chat_id, "❌ فرمت کد تخفیف نامعتبر است.\nکد باید شامل حروف بزرگ انگلیسی و اعداد باشد (6 تا 12 کاراکتر).");
            return ['success' => false, 'message' => 'Invalid format'];
        }

        $user = $this->userModel->findByTelegramId($chat_id);
        if (!$user) {
            $this->telegram->sendMessage($chat_id, "❌ کاربر یافت نشد. لطفاً /start را بزنید.");
            return ['success' => false, 'message' => 'User not found'];
        }

        $validation = $this->discountModel->validateDiscount($code, $user['id']);

        if (!$validation['valid']) {
            $this->telegram->sendMessage($chat_id, "❌ {$validation['message']}");
            return ['success' => false, 'message' => $validation['message']];
        }

        $result = $this->discountModel->useDiscount($code, $user['id']);

        if ($result['valid']) {
            $_SESSION['discount_amount'][$chat_id] = $result['amount'];

            $message = "✅ کد تخفیف با موفقیت اعمال شد!\n\n";
            $message .= "💰 مبلغ تخفیف: " . number_format($result['amount']) . " تومان\n\n";
            $message .= "این تخفیف در ثبت آگهی بعدی شما لحاظ خواهد شد.";

            $this->telegram->sendMessage($chat_id, $message);

            return [
                'success' => true,
                'amount' => $result['amount'],
                'discount_id' => $result['discount_id']
            ];
        } else {
            $this->telegram->sendMessage($chat_id, "❌ {$result['message']}");
            return ['success' => false, 'message' => $result['message']];
        }
    }

    public function getAppliedDiscount($chat_id) {
        $discount = $_SESSION['discount_amount'][$chat_id] ?? null;
        if ($discount) {
            unset($_SESSION['discount_amount'][$chat_id]);
        }
        return $discount;
    }

    public function createDiscount($admin_id, $code, $amount, $max_uses = 1, $expires_in_days = 30) {
        $code = trim(strtoupper($code));

        if (!Validator::discountCode($code)) {
            $this->telegram->sendMessage($admin_id, "❌ فرمت کد تخفیف نامعتبر است.");
            return false;
        }

        $existing = $this->discountModel->findByCode($code);
        if ($existing) {
            $this->telegram->sendMessage($admin_id, "❌ کد تخفیف تکراری است.");
            return false;
        }

        $result = $this->discountModel->createDiscount($code, $amount, $max_uses, $expires_in_days);

        if ($result['status']) {
            $message = "✅ کد تخفیف با موفقیت ایجاد شد!\n\n";
            $message .= "🔖 کد: <code>{$code}</code>\n";
            $message .= "💰 مبلغ تخفیف: " . number_format($amount) . " تومان\n";
            $message .= "📊 تعداد استفاده: {$max_uses} بار\n";
            $message .= "📅 اعتبار: {$expires_in_days} روز";
            $this->telegram->sendMessage($admin_id, $message);
            return true;
        } else {
            $this->telegram->sendMessage($admin_id, "❌ خطا در ایجاد کد تخفیف.");
            return false;
        }
    }

    public function deactivateDiscount($admin_id, $code) {
        $code = trim(strtoupper($code));

        $discount = $this->discountModel->findByCode($code);
        if (!$discount) {
            $this->telegram->sendMessage($admin_id, "❌ کد تخفیف یافت نشد.");
            return false;
        }

        $result = $this->discountModel->deactivate($code);

        if ($result['status']) {
            $this->telegram->sendMessage($admin_id, "✅ کد تخفیف <code>{$code}</code> غیرفعال شد.");
            return true;
        } else {
            $this->telegram->sendMessage($admin_id, "❌ خطا در غیرفعال کردن کد تخفیف.");
            return false;
        }
    }

    public function listActiveDiscounts($admin_id) {
        $discounts = $this->discountModel->getAllActive();

        if (empty($discounts)) {
            $this->telegram->sendMessage($admin_id, "📭 هیچ کد تخفیف فعالی وجود ندارد.");
            return;
        }

        $message = "🎁 <b>کدهای تخفیف فعال</b>\n\n";
        foreach ($discounts as $discount) {
            $message .= "🔖 <code>{$discount['code']}</code>\n";
            $message .= "💰 مبلغ: " . number_format($discount['amount']) . " تومان\n";
            $message .= "📊 استفاده شده: {$discount['used_count']} از {$discount['max_uses']}\n";
            $message .= "📅 انقضا: " . date('Y/m/d', strtotime($discount['expires_at'])) . "\n";
            $message .= "———————————\n";
        }

        $this->telegram->sendMessage($admin_id, $message);
    }

    public function clearUserDiscount($chat_id) {
        unset($_SESSION['discount_amount'][$chat_id]);
        unset($_SESSION['user_state'][$chat_id]);
    }
}