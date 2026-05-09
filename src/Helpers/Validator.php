<?php
namespace Helpers;

class Validator {

    /**
     * اعتبارسنجی شماره تلفن ایران
     * مثال: 09123456789
     */
    public static function phone($phone) {
        // حذف فاصله و خط تیره
        $phone = preg_replace('/[-\s]/', '', $phone);

        // بررسی فرمت: با 09 شروع شود و 11 رقم باشد
        return preg_match('/^09[0-9]{9}$/', $phone);
    }

    /**
     * اعتبارسنجی مبلغ شارژ کیف پول
     * حداقل: 10,000 تومان
     * حداکثر: 5,000,000 تومان
     */
    public static function amount($amount) {
        return is_numeric($amount) && $amount >= 10000 && $amount <= 5000000;
    }

    /**
     * اعتبارسنجی آیدی تلگرام
     * مثال: @username
     */
    public static function username($username) {
        // حذف @ اول اگر وجود داشته باشد
        $username = ltrim($username, '@');

        // بررسی: فقط حروف انگلیسی، اعداد و زیرخط
        // حداقل 5 کاراکتر، حداکثر 32 کاراکتر
        return preg_match('/^[a-zA-Z0-9_]{5,32}$/', $username);
    }

    /**
     * اعتبارسنجی متن آگهی
     * حداقل: 10 کاراکتر
     * حداکثر: 1000 کاراکتر
     */
    public static function jobText($text) {
        $length = mb_strlen($text, 'UTF-8');
        return $length >= 10 && $length <= 1000;
    }

    /**
     * اعتبارسنجی کد تخفیف
     * فرمت: حروف بزرگ انگلیسی و اعداد
     * حداقل: 6 کاراکتر
     * حداکثر: 12 کاراکتر
     */
    public static function discountCode($code) {
        return preg_match('/^[A-Z0-9]{6,12}$/', $code);
    }

    /**
     * اعتبارسنجی اینکه مقدار خالی نباشد
     */
    public static function notEmpty($value) {
        return !empty(trim($value));
    }

    /**
     * اعتبارسنجی ایمیل (برای آینده)
     */
    public static function email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * اعتبارسنجی اینکه کاربر عدد فرستاده
     */
    public static function isNumeric($value, $min = null, $max = null) {
        if (!is_numeric($value)) return false;
        if ($min !== null && $value < $min) return false;
        if ($max !== null && $value > $max) return false;
        return true;
    }
}