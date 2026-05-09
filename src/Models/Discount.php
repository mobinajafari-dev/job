<?php
namespace Models;

use Core\Model;

class Discount extends Model {
    protected $table = 'discounts';

    public function createDiscount($code, $amount, $max_uses = 1, $expires_in_days = 30) {
        $data = [
            'code' => $code,
            'amount' => $amount,
            'max_uses' => $max_uses,
            'used_count' => 0,
            'expires_at' => date('Y-m-d H:i:s', strtotime("+{$expires_in_days} days")),
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $this->insert($data);
    }

    public function validateDiscount($code, $user_id = null) {
        $result = $this->select(
            ['*'],
            ['code' => $code],
            false,
            'AND expires_at > NOW() AND (max_uses IS NULL OR used_count < max_uses)'
        );

        if (!$result['status'] || !$result['details']) {
            return ['valid' => false, 'message' => 'کد تخفیف معتبر نیست'];
        }

        $discount = $result['details'];

        if ($user_id) {
            $sql = "SELECT COUNT(*) as count FROM user_discounts 
                    WHERE user_id = :user_id AND discount_id = :discount_id";
            $usedResult = $this->rawQuery($sql, ['user_id' => $user_id, 'discount_id' => $discount['id']]);

            if ($usedResult['status'] && $usedResult['details'][0]['count'] > 0) {
                return ['valid' => false, 'message' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'];
            }
        }

        return [
            'valid' => true,
            'discount' => $discount
        ];
    }

    public function useDiscount($code, $user_id) {
        $validation = $this->validateDiscount($code, $user_id);

        if (!$validation['valid']) {
            return $validation;
        }

        $discount = $validation['discount'];

        $this->db->beginTransaction();

        try {
            $this->update(['used_count' => $discount['used_count'] + 1], ['id' => $discount['id']]);

            $sql = "INSERT INTO user_discounts (user_id, discount_id, used_at) VALUES (:user_id, :discount_id, NOW())";
            $this->rawQuery($sql, ['user_id' => $user_id, 'discount_id' => $discount['id']]);

            $this->db->commit();

            return [
                'valid' => true,
                'amount' => $discount['amount'],
                'discount_id' => $discount['id']
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['valid' => false, 'message' => 'خطا در اعمال کد تخفیف'];
        }
    }

    public function getAllActive() {
        $result = $this->select(
            ['*'],
            [],
            true,
            'WHERE expires_at > NOW() AND (max_uses IS NULL OR used_count < max_uses) ORDER BY created_at DESC'
        );
        return $result['status'] ? $result['details'] : [];
    }

    public function deactivate($code) {
        return $this->update(['expires_at' => date('Y-m-d H:i:s')], ['code' => $code]);
    }

    public function deleteExpired() {
        $sql = "DELETE FROM {$this->table} WHERE expires_at < NOW()";
        $result = $this->rawQuery($sql);
        return $result['status'];
    }

    public function findByCode($code) {
        $result = $this->select(['*'], ['code' => $code], false);
        return $result['status'] ? $result['details'] : null;
    }
}