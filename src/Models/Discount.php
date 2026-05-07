<?php
namespace Models;

use Core\Model;
use Helpers\Database;

class Discount extends Model {
    protected $table = 'discounts';

    /**
     * ایجاد کد تخفیف جدید
     */
    public function create($code, $amount, $max_uses = 1, $expires_in_days = 30) {
        $sql = "INSERT INTO {$this->table} (code, amount, max_uses, expires_at, created_at) 
                VALUES (:code, :amount, :max_uses, DATE_ADD(NOW(), INTERVAL :expires DAY), NOW())";

        return Database::execute($sql, [
            'code' => $code,
            'amount' => $amount,
            'max_uses' => $max_uses,
            'expires' => $expires_in_days
        ]);
    }

    /**
     * اعتبارسنجی کد تخفیف
     */
    public function validate($code, $user_id = null) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE code = :code 
                AND expires_at > NOW() 
                AND (max_uses IS NULL OR used_count < max_uses)";

        $discount = Database::query($sql, ['code' => $code]);

        if (!$discount) {
            return ['valid' => false, 'message' => 'کد تخفیف معتبر نیست'];
        }

        // بررسی استفاده کاربر از این کد
        if ($user_id) {
            $sql = "SELECT COUNT(*) as count FROM user_discounts 
                    WHERE user_id = :user_id AND discount_id = :discount_id";
            $used = Database::query($sql, [
                'user_id' => $user_id,
                'discount_id' => $discount['id']
            ]);

            if ($used['count'] > 0) {
                return ['valid' => false, 'message' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
            }
        }

        return [
            'valid' => true,
            'discount' => $discount
        ];
    }

    /**
     * استفاده از کد تخفیف
     */
    public function use($code, $user_id) {
        $validation = $this->validate($code, $user_id);

        if (!$validation['valid']) {
            return $validation;
        }

        $discount = $validation['discount'];

        // شروع تراکنش
        Database::getConnection()->beginTransaction();

        try {
            // افزایش تعداد استفاده
            $sql = "UPDATE {$this->table} SET used_count = used_count + 1 WHERE id = :id";
            Database::execute($sql, ['id' => $discount['id']]);

            // ثبت استفاده کاربر
            $sql = "INSERT INTO user_discounts (user_id, discount_id, used_at) 
                    VALUES (:user_id, :discount_id, NOW())";
            Database::execute($sql, [
                'user_id' => $user_id,
                'discount_id' => $discount['id']
            ]);

            Database::getConnection()->commit();

            return [
                'valid' => true,
                'amount' => $discount['amount'],
                'discount_id' => $discount['id']
            ];

        } catch (\Exception $e) {
            Database::getConnection()->rollBack();
            return ['valid' => false, 'message' => 'خطا در اعمال کد تخفیف'];
        }
    }

    /**
     * دریافت همه کدهای تخفیف فعال
     */
    public function getAllActive() {
        $sql = "SELECT * FROM {$this->table} 
                WHERE expires_at > NOW() 
                AND (max_uses IS NULL OR used_count < max_uses)
                ORDER BY created_at DESC";

        return Database::queryAll($sql);
    }

    /**
     * غیرفعال کردن کد تخفیف
     */
    public function deactivate($code) {
        $sql = "UPDATE {$this->table} SET expires_at = NOW() WHERE code = :code";
        return Database::execute($sql, ['code' => $code]);
    }

    /**
     * حذف کدهای تخفیف منقضی شده
     */
    public function deleteExpired() {
        $sql = "DELETE FROM {$this->table} WHERE expires_at < NOW()";
        return Database::execute($sql);
    }

    /**
     * دریافت کد تخفیف توسط کد
     */
    public function findByCode($code) {
        $sql = "SELECT * FROM {$this->table} WHERE code = :code";
        return Database::query($sql, ['code' => $code]);
    }

    /**
     * بررسی استفاده کاربر از کد تخفیف
     */
    public function checkUserUsed($user_id, $discount_id) {
        $sql = "SELECT COUNT(*) as count FROM user_discounts 
                WHERE user_id = :user_id AND discount_id = :discount_id";
        $result = Database::query($sql, [
            'user_id' => $user_id,
            'discount_id' => $discount_id
        ]);

        return $result['count'] > 0;
    }
}