<?php
namespace Models;

use Core\Model;

class Transaction extends Model {
    protected $table = 'transactions';

    const TYPE_DEPOSIT = 'deposit';
    const TYPE_WITHDRAW = 'withdraw';
    const TYPE_REFERRAL = 'referral';
    const TYPE_JOB_PAYMENT = 'job_payment';
    const TYPE_WELCOME_BONUS = 'welcome_bonus';
    const TYPE_DISCOUNT = 'discount';

    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    public function createTransaction($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        if (!isset($data['status'])) {
            $data['status'] = self::STATUS_PENDING;
        }
        return $this->insert($data, true);
    }

    public function updateTransaction($id, $data) {
        return $this->update($data, ['id' => $id]);
    }

    public function findTransaction($id) {
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = :id";
        $result = $this->rawQuery($sql, ['id' => $id]);
        return $result['status'] && $result['details'] ? $result['details'][0] : null;
    }

    public function getUserTransactions($user_id, $limit = 20, $offset = 0) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        $result = $this->rawQuery($sql, ['user_id' => $user_id, 'limit' => $limit, 'offset' => $offset]);
        return $result['status'] ? $result['details'] : [];
    }

    public function complete($id, $ref_id = null) {
        $data = ['status' => self::STATUS_COMPLETED];
        if ($ref_id) {
            $data['ref_id'] = $ref_id;
        }
        return $this->update($data, ['id' => $id]);
    }

    public function cancel($id) {
        return $this->update(['status' => self::STATUS_CANCELLED], ['id' => $id]);
    }

    public function getFinancialStats($start_date = null, $end_date = null) {
        $sql = "SELECT 
                    SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
                    SUM(CASE WHEN type = 'job_payment' AND status = 'completed' THEN amount ELSE 0 END) as total_job_payments,
                    SUM(CASE WHEN type = 'referral' AND status = 'completed' THEN amount ELSE 0 END) as total_referral_bonus,
                    SUM(CASE WHEN type = 'welcome_bonus' AND status = 'completed' THEN amount ELSE 0 END) as total_welcome_bonus,
                    COUNT(*) as total_transactions
                FROM {$this->table}
                WHERE status = 'completed'";

        if ($start_date && $end_date) {
            $sql .= " AND created_at BETWEEN :start_date AND :end_date";
            $result = $this->rawQuery($sql, ['start_date' => $start_date, 'end_date' => $end_date]);
        } else {
            $result = $this->rawQuery($sql);
        }

        return $result['status'] && $result['details'] ? $result['details'][0] : [];
    }

    public function getPendingTransactions($limit = 50) {
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.status = :status 
                ORDER BY t.created_at ASC 
                LIMIT :limit";
        $result = $this->rawQuery($sql, ['status' => self::STATUS_PENDING, 'limit' => $limit]);
        return $result['status'] ? $result['details'] : [];
    }
}