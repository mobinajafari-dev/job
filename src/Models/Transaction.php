<?php
namespace Models;

use Core\Model;
use Helpers\Database;

class Transaction extends Model {
    protected $table = 'transactions';

    const TYPE_DEPOSIT = 'deposit';
    const TYPE_WITHDRAW = 'withdraw';
    const TYPE_REFERRAL = 'referral';
    const TYPE_JOB_PAYMENT = 'job_payment';
    const TYPE_DISCOUNT = 'discount';

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * ایجاد تراکنش جدید
     */
    public function create($data) {
        $sql = "INSERT INTO {$this->table} (user_id, amount, type, status, reference_id, description, created_at) 
                VALUES (:user_id, :amount, :type, :status, :reference_id, :description, NOW())";

        $params = [
            'user_id' => $data['user_id'],
            'amount' => $data['amount'],
            'type' => $data['type'],
            'status' => $data['status'] ?? self::STATUS_PENDING,
            'reference_id' => $data['reference_id'] ?? null,
            'description' => $data['description'] ?? null
        ];

        $result = Database::execute($sql, $params);

        if ($result) {
            return Database::lastInsertId();
        }

        return false;
    }

    /**
     * بروزرسانی تراکنش
     */
    public function update($id, $data) {
        $fields = [];
        $params = ['id' => $id];

        $allowed_fields = ['status', 'reference_id', 'description', 'ref_id'];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
        return Database::execute($sql, $params);
    }

    /**
     * دریافت تراکنش توسط ID
     */
    public function find($id) {
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = :id";

        return Database::query($sql, ['id' => $id]);
    }

    /**
     * دریافت تراکنش‌های کاربر
     */
    public function getUserTransactions($user_id, $limit = 20, $offset = 0) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";

        return Database::queryAll($sql, [
            'user_id' => $user_id,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    /**
     * تایید تراکنش
     */
    public function complete($id, $ref_id = null) {
        $data = ['status' => self::STATUS_COMPLETED];

        if ($ref_id) {
            $data['ref_id'] = $ref_id;
        }

        return $this->update($id, $data);
    }

    /**
     * لغو تراکنش
     */
    public function cancel($id) {
        return $this->update($id, ['status' => self::STATUS_CANCELLED]);
    }

    /**
     * دریافت آمار مالی
     */
    public function getFinancialStats($start_date = null, $end_date = null) {
        $sql = "SELECT 
                    SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
                    SUM(CASE WHEN type = 'withdraw' AND status = 'completed' THEN amount ELSE 0 END) as total_withdraws,
                    SUM(CASE WHEN type = 'job_payment' AND status = 'completed' THEN amount ELSE 0 END) as total_job_payments,
                    SUM(CASE WHEN type = 'referral' AND status = 'completed' THEN amount ELSE 0 END) as total_referral_bonus,
                    COUNT(CASE WHEN type = 'deposit' AND status = 'completed' THEN 1 END) as deposit_count,
                    COUNT(*) as total_transactions
                FROM {$this->table}";

        $params = [];

        if ($start_date && $end_date) {
            $sql .= " WHERE created_at BETWEEN :start_date AND :end_date";
            $params = ['start_date' => $start_date, 'end_date' => $end_date];
        }

        return Database::query($sql, $params);
    }

    /**
     * دریافت تراکنش‌های در انتظار
     */
    public function getPendingTransactions($limit = 50) {
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.status = :status 
                ORDER BY t.created_at ASC 
                LIMIT :limit";

        return Database::queryAll($sql, [
            'status' => self::STATUS_PENDING,
            'limit' => $limit
        ]);
    }
}