<?php
namespace Models;

use Core\Model;

class Wallet extends Model {
    
    /**
     * دریافت کیف پول کاربر
     */
    public function getWallet($user_id) {
        $sql = "SELECT * FROM wallets WHERE user_id = :user_id";
        return $this->query($sql, ['user_id' => $user_id]);
    }
    
    /**
     * ایجاد کیف پول برای کاربر جدید
     */
    public function createWallet($user_id) {
        $sql = "INSERT INTO wallets (user_id, balance) VALUES (:user_id, 0)";
        return $this->execute($sql, ['user_id' => $user_id]);
    }
    
    /**
     * دریافت موجودی فعلی
     */
    public function getBalance($user_id) {
        $sql = "SELECT balance FROM wallets WHERE user_id = :user_id";
        $result = $this->query($sql, ['user_id' => $user_id]);
        return $result ? (float) $result['balance'] : 0;
    }
    
    /**
     * ثبت تراکنش در جدول transactions
     */
    public function recordTransaction($user_id, $amount, $type, $description = null, $reference_id = null) {
        $sql = "INSERT INTO transactions (user_id, amount, type, description, reference_id, status, created_at) 
                VALUES (:user_id, :amount, :type, :description, :reference_id, 'completed', NOW())";
        
        return $this->execute($sql, [
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'reference_id' => $reference_id
        ]);
    }
    
    /**
     * دریافت تاریخچه تراکنش‌ها
     */
    public function getTransactions($user_id, $limit = 20, $offset = 0) {
        $sql = "SELECT * FROM transactions 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        
        return $this->queryAll($sql, [
            'user_id' => $user_id,
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
}