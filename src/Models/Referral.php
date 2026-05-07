<?php
namespace Models;

use Core\Model;
use Helpers\Database;

class Referral extends Model {
    protected $table = 'referrals';

    /**
     * ثبت معرفی جدید
     */
    public function create($referrer_id, $referred_id, $code_used) {
        $sql = "INSERT INTO {$this->table} (referrer_id, referred_id, code_used, status, created_at) 
                VALUES (:referrer_id, :referred_id, :code_used, 'pending', NOW())";

        return Database::execute($sql, [
            'referrer_id' => $referrer_id,
            'referred_id' => $referred_id,
            'code_used' => $code_used
        ]);
    }

    /**
     * تایید معرفی (بعد از اولین اقدام کاربر معرفی شده)
     */
    public function confirm($referred_id) {
        $sql = "UPDATE {$this->table} SET 
                status = 'completed', 
                confirmed_at = NOW() 
                WHERE referred_id = :referred_id AND status = 'pending'";

        $result = Database::execute($sql, ['referred_id' => $referred_id]);

        if ($result) {
            // دریافت اطلاعات معرف
            $sql = "SELECT referrer_id FROM {$this->table} WHERE referred_id = :referred_id";
            $referral = Database::query($sql, ['referred_id' => $referred_id]);

            if ($referral) {
                return $referral['referrer_id'];
            }
        }

        return false;
    }

    /**
     * دریافت لیست معرفی‌های یک کاربر
     */
    public function getReferrals($user_id, $status = null, $limit = 20) {
        $sql = "SELECT r.*, u.username, u.phone, u.created_at as user_joined_at 
                FROM {$this->table} r 
                JOIN users u ON r.referred_id = u.id 
                WHERE r.referrer_id = :user_id";

        $params = ['user_id' => $user_id];

        if ($status) {
            $sql .= " AND r.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY r.created_at DESC LIMIT :limit";
        $params['limit'] = $limit;

        return Database::queryAll($sql, $params);
    }

    /**
     * تعداد معرفی‌های موفق یک کاربر
     */
    public function getSuccessfulReferralsCount($user_id) {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE referrer_id = :user_id AND status = 'completed'";
        $result = Database::query($sql, ['user_id' => $user_id]);

        return $result['count'];
    }

    /**
     * میزان پاداش دریافتی از معرفی
     */
    public function getTotalBonus($user_id) {
        $sql = "SELECT SUM(amount) as total FROM transactions 
                WHERE user_id = :user_id AND type = 'referral' AND status = 'completed'";
        $result = Database::query($sql, ['user_id' => $user_id]);

        return $result['total'] ?? 0;
    }

    /**
     * دریافت درخت معرفی (تا 5 سطح)
     */
    public function getReferralTree($user_id, $depth = 1) {
        if ($depth > 5) return [];

        $sql = "SELECT u.id, u.username, u.phone, r.status, r.created_at 
                FROM {$this->table} r 
                JOIN users u ON r.referred_id = u.id 
                WHERE r.referrer_id = :user_id AND r.status = 'completed'";

        $referrals = Database::queryAll($sql, ['user_id' => $user_id]);

        foreach ($referrals as &$referral) {
            $referral['children'] = $this->getReferralTree($referral['id'], $depth + 1);
        }

        return $referrals;
    }

    /**
     * آمار کلی سیستم معرف
     */
    public function getOverallStats() {
        $sql = "SELECT 
                    COUNT(*) as total_referrals,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_referrals,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_referrals,
                    COUNT(DISTINCT referrer_id) as active_referrers
                FROM {$this->table}";

        return Database::query($sql);
    }

    /**
     * برترین معرف‌ها
     */
    public function getTopReferrers($limit = 10) {
        $sql = "SELECT u.id, u.username, COUNT(r.id) as referral_count 
                FROM users u 
                JOIN {$this->table} r ON u.id = r.referrer_id 
                WHERE r.status = 'completed' 
                GROUP BY u.id 
                ORDER BY referral_count DESC 
                LIMIT :limit";

        return Database::queryAll($sql, ['limit' => $limit]);
    }

    /**
     * حذف معرفی‌های قدیمی
     */
    public function deleteOldReferrals($days = 90) {
        $sql = "DELETE FROM {$this->table} 
                WHERE status = 'pending' 
                AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        return Database::execute($sql, ['days' => $days]);
    }
}