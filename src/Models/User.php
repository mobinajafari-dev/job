<?php
namespace Models;

use Core\Model;
use Exception;

class User extends Model {
    protected $table = 'users';
    
    /**
     * پیدا کردن کاربر با telegram_id
     */
    public function findByTelegramId($telegram_id) {
        $sql = "SELECT * FROM {$this->table} WHERE telegram_id = :telegram_id";
        return $this->query($sql, ['telegram_id' => $telegram_id]);
    }
    
    /**
     * پیدا کردن کاربر با id
     */
    public function findById($id) {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        return $this->query($sql, ['id' => $id]);
    }
    
    /**
     * ایجاد کاربر جدید با کیف پول و تراکنش هدیه
     */
    public function create($telegram_id, $username, $phone) {
        // شروع تراکنش
        $this->db->beginTransaction();
        
        try {
            // 1. ثبت کاربر
            $sql = "INSERT INTO users (telegram_id, username, phone, created_at) 
                    VALUES (:telegram_id, :username, :phone, NOW())";
            
            $result = $this->execute($sql, [
                'telegram_id' => $telegram_id,
                'username' => $username,
                'phone' => $phone
            ]);
            
            if(!$result) throw new Exception("User insert failed");
            
            $user_id = $this->lastInsertId();
            
            // 2. ایجاد کیف پول با 10000 تومان هدیه
            $sql = "INSERT INTO wallets (user_id, balance) VALUES (:user_id, 10000)";
            $this->execute($sql, ['user_id' => $user_id]);
            
            // 3. ثبت تراکنش هدیه
            $sql = "INSERT INTO transactions (user_id, amount, type, description, status, created_at) 
                    VALUES (:user_id, 10000, 'welcome_bonus', 'هدیه ثبت‌نام', 'completed', NOW())";
            $this->execute($sql, ['user_id' => $user_id]);
            
            $this->db->commit();
            return true;
            
        } catch(Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }
    
    /**
     * بروزرسانی کاربر
     */
    public function update($telegram_id, $data) {
        $fields = [];
        $params = ['telegram_id' => $telegram_id];
        
        $allowedFields = ['username', 'phone', 'referral_code', 'referred_by'];
        
        foreach($data as $key => $value) {
            if(in_array($key, $allowedFields)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $value;
            }
        }
        
        if(empty($fields)) return false;
        
        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE telegram_id = :telegram_id";
        return $this->execute($sql, $params);
    }
    
    /**
     * بررسی وجود کاربر
     */
    public function exists($telegram_id) {
        $sql = "SELECT 1 FROM {$this->table} WHERE telegram_id = :telegram_id LIMIT 1";
        return (bool) $this->query($sql, ['telegram_id' => $telegram_id]);
    }
    
    /**
     * دریافت موجودی کیف پول از جدول wallets
     */
    public function getBalance($telegram_id) {
        $user = $this->findByTelegramId($telegram_id);
        if(!$user) return 0;
        
        $sql = "SELECT balance FROM wallets WHERE user_id = :user_id";
        $result = $this->query($sql, ['user_id' => $user['id']]);
        return $result ? (float) $result['balance'] : 0;
    }
    
    /**
     * افزایش موجودی کیف پول
     */
    public function increaseBalance($telegram_id, $amount) {
        $user = $this->findByTelegramId($telegram_id);
        if(!$user) return false;
        
        $sql = "UPDATE wallets SET balance = balance + :amount WHERE user_id = :user_id";
        return $this->execute($sql, [
            'amount' => $amount,
            'user_id' => $user['id']
        ]);
    }
    
    /**
     * کاهش موجودی کیف پول
     */
    public function decreaseBalance($telegram_id, $amount) {
        $user = $this->findByTelegramId($telegram_id);
        if(!$user) return false;
        
        // بررسی موجودی کافی
        $currentBalance = $this->getBalance($telegram_id);
        if($currentBalance < $amount) return false;
        
        $sql = "UPDATE wallets SET balance = balance - :amount WHERE user_id = :user_id AND balance >= :amount";
        return $this->execute($sql, [
            'amount' => $amount,
            'user_id' => $user['id']
        ]);
    }
}