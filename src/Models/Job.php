<?php
namespace Models;

use Core\Model;
use Helpers\Database;

class Job extends Model {
    protected $table = 'jobs';
    protected $fillable = ['user_id', 'content', 'contact_id', 'status', 'channel_message_id', 'approved_at', 'expired_at'];

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REJECTED = 'rejected';

    /**
     * ایجاد آگهی جدید
     */
    public function create($user_id, $content, $contact_id) {
        $sql = "INSERT INTO {$this->table} (user_id, content, contact_id, status, created_at) 
                VALUES (:user_id, :content, :contact_id, :status, NOW())";

        $result = Database::execute($sql, [
            'user_id' => $user_id,
            'content' => $content,
            'contact_id' => $contact_id,
            'status' => self::STATUS_PENDING
        ]);

        if ($result) {
            return Database::lastInsertId();
        }

        return false;
    }

    /**
     * دریافت آگهی توسط ID
     */
    public function find($id) {
        $sql = "SELECT j.*, u.username, u.telegram_id 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.id = :id";

        return Database::query($sql, ['id' => $id]);
    }

    /**
     * دریافت آگهی‌های کاربر
     */
    public function getUserJobs($user_id, $limit = 10, $offset = 0) {
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
     * تایید آگهی توسط ادمین
     */
    public function approve($job_id, $channel_message_id = null) {
        $sql = "UPDATE {$this->table} SET 
                status = :status, 
                approved_at = NOW()" .
            ($channel_message_id ? ", channel_message_id = :msg_id" : "") . "
                WHERE id = :job_id";

        $params = [
            'status' => self::STATUS_ACTIVE,
            'job_id' => $job_id
        ];

        if ($channel_message_id) {
            $params['msg_id'] = $channel_message_id;
        }

        return Database::execute($sql, $params);
    }

    /**
     * رد آگهی توسط ادمین
     */
    public function reject($job_id) {
        $sql = "UPDATE {$this->table} SET status = :status WHERE id = :job_id";
        return Database::execute($sql, [
            'status' => self::STATUS_REJECTED,
            'job_id' => $job_id
        ]);
    }

    /**
     * منقضی کردن آگهی
     */
    public function expire($job_id) {
        $sql = "UPDATE {$this->table} SET 
                status = :status, 
                expired_at = NOW() 
                WHERE id = :job_id AND status = :active_status";

        return Database::execute($sql, [
            'status' => self::STATUS_EXPIRED,
            'active_status' => self::STATUS_ACTIVE,
            'job_id' => $job_id
        ]);
    }

    /**
     * دریافت آگهی‌های در انتظار تایید
     */
    public function getPendingJobs($limit = 20) {
        $sql = "SELECT j.*, u.username, u.telegram_id 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = :status 
                ORDER BY j.created_at ASC 
                LIMIT :limit";

        return Database::queryAll($sql, [
            'status' => self::STATUS_PENDING,
            'limit' => $limit
        ]);
    }

    /**
     * دریافت آگهی‌های فعال
     */
    public function getActiveJobs($limit = 50) {
        $sql = "SELECT j.*, u.username 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = :status 
                ORDER BY j.approved_at DESC 
                LIMIT :limit";

        return Database::queryAll($sql, [
            'status' => self::STATUS_ACTIVE,
            'limit' => $limit
        ]);
    }

    /**
     * بروزرسانی وضعیت آگهی توسط کاربر
     */
    public function updateStatus($job_id, $user_id, $new_status) {
        // بررسی مالکیت آگهی
        $sql = "SELECT id FROM {$this->table} WHERE id = :job_id AND user_id = :user_id";
        $job = Database::query($sql, [
            'job_id' => $job_id,
            'user_id' => $user_id
        ]);

        if (!$job) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET status = :status WHERE id = :job_id";
        return Database::execute($sql, [
            'status' => $new_status,
            'job_id' => $job_id
        ]);
    }

    /**
     * حذف آگهی‌های قدیمی
     */
    public function deleteOldJobs($days = 30) {
        $sql = "DELETE FROM {$this->table} 
                WHERE status IN (:expired, :rejected) 
                AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        return Database::execute($sql, [
            'expired' => self::STATUS_EXPIRED,
            'rejected' => self::STATUS_REJECTED,
            'days' => $days
        ]);
    }

    /**
     * آمار آگهی‌ها
     */
    public function getStats() {
        $sql = "SELECT 
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    COUNT(*) as total
                FROM {$this->table}";

        return Database::query($sql);
    }

    /**
     * جستجوی آگهی‌ها
     */
    public function search($keyword, $limit = 20) {
        $sql = "SELECT j.*, u.username 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = :status 
                AND j.content LIKE :keyword 
                ORDER BY j.approved_at DESC 
                LIMIT :limit";

        return Database::queryAll($sql, [
            'status' => self::STATUS_ACTIVE,
            'keyword' => "%{$keyword}%",
            'limit' => $limit
        ]);
    }
}