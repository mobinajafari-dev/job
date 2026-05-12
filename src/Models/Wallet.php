<?php
namespace Models;

use Core\Model;

class Wallet extends Model {
    protected $table = 'wallets';

    public function getWallet($user_id) {
        $result = $this->select(['*'], ['user_id' => $user_id], false);
        return $result['status'] ? $result['details'] : null;
    }

    public function createWallet($user_id, $initial_balance = 10000) {
        $data = [
            'user_id' => $user_id,
            'balance' => $initial_balance,  // تغییر از 0 به متغیر
            'created_at' => date('Y-m-d H:i:s'),
            'frozen_balance' => 0
        ];
        return $this->insert($data);
    }

    public function getBalance($user_id) {
        $result = $this->select(['balance'], ['user_id' => $user_id], false);
        if ($result['status'] && $result['details']) {
            return (float) $result['details']['balance'];
        }
        return 0;
    }

    public function increaseBalance($user_id, $amount) {
        $sql = "UPDATE {$this->table} SET balance = balance + :amount WHERE user_id = :user_id";
        $result = $this->rawQuery($sql, ['amount' => $amount, 'user_id' => $user_id]);
        return $result['status'];
    }

    public function decreaseBalance($user_id, $amount) {
        $sql = "UPDATE {$this->table} SET balance = balance - :amount WHERE user_id = :user_id AND balance >= :amount";
        $result = $this->rawQuery($sql, ['amount' => $amount, 'user_id' => $user_id]);
        return $result['status'];
    }

    public function recordTransaction($user_id, $amount, $type, $description = null, $reference_id = null) {
        $data = [
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => $type,
            'description' => $description,
            'reference_id' => $reference_id,
            'status' => 'completed',
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $this->insert($data);
    }

    public function getTransactions($user_id, $limit = 20) {
        $sql = "SELECT * FROM transactions WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit";
        $result = $this->rawQuery($sql, ['user_id' => $user_id, 'limit' => $limit]);
        return $result['status'] ? $result['details'] : [];
    }

    public function getTotalBalance() {
        $result = $this->select(['SUM(balance) as total'], [], false);
        return $result['status'] && $result['details'] ? (float) $result['details']['total'] : 0;
    }
}