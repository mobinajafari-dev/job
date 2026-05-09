<?php
namespace Models;

use Core\Model;

class Job extends Model {
    protected $table = 'jobs';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REJECTED = 'rejected';

    public function createJob($user_id, $content, $contact_id) {
        $data = [
            'user_id' => $user_id,
            'content' => $content,
            'contact_id' => $contact_id,
            'status' => self::STATUS_PENDING,
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $this->insert($data, true);
    }

    public function findJob($id) {
        $sql = "SELECT j.*, u.username, u.telegram_id 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.id = :id";
        $result = $this->rawQuery($sql, ['id' => $id]);
        return $result['status'] && $result['details'] ? $result['details'][0] : null;
    }

    public function getUserJobs($user_id, $limit = 10, $offset = 0) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        $result = $this->rawQuery($sql, ['user_id' => $user_id, 'limit' => $limit, 'offset' => $offset]);
        return $result['status'] ? $result['details'] : [];
    }

    public function approve($job_id, $channel_message_id = null) {
        $data = ['status' => self::STATUS_ACTIVE, 'approved_at' => date('Y-m-d H:i:s')];
        if ($channel_message_id) {
            $data['channel_message_id'] = $channel_message_id;
        }
        return $this->update($data, ['id' => $job_id]);
    }

    public function reject($job_id) {
        return $this->update(['status' => self::STATUS_REJECTED], ['id' => $job_id]);
    }

    public function expire($job_id) {
        return $this->update(
            ['status' => self::STATUS_EXPIRED, 'expired_at' => date('Y-m-d H:i:s')],
            ['id' => $job_id, 'status' => self::STATUS_ACTIVE]
        );
    }

    public function getPendingJobs($limit = 20) {
        $sql = "SELECT j.*, u.username, u.telegram_id 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = :status 
                ORDER BY j.created_at ASC 
                LIMIT :limit";
        $result = $this->rawQuery($sql, ['status' => self::STATUS_PENDING, 'limit' => $limit]);
        return $result['status'] ? $result['details'] : [];
    }

    public function getActiveJobs($limit = 50) {
        $sql = "SELECT j.*, u.username 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = :status 
                ORDER BY j.approved_at DESC 
                LIMIT :limit";
        $result = $this->rawQuery($sql, ['status' => self::STATUS_ACTIVE, 'limit' => $limit]);
        return $result['status'] ? $result['details'] : [];
    }

    public function updateStatus($job_id, $user_id, $new_status) {
        $job = $this->findJob($job_id);
        if (!$job || $job['user_id'] != $user_id) {
            return false;
        }
        return $this->update(['status' => $new_status], ['id' => $job_id]);
    }

    public function getStats() {
        $sql = "SELECT 
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    COUNT(*) as total
                FROM {$this->table}";
        $result = $this->rawQuery($sql);
        return $result['status'] && $result['details'] ? $result['details'][0] : [];
    }

    public function search($keyword, $limit = 20) {
        $sql = "SELECT j.*, u.username 
                FROM {$this->table} j 
                JOIN users u ON j.user_id = u.id 
                WHERE j.status = :status 
                AND j.content LIKE :keyword 
                ORDER BY j.approved_at DESC 
                LIMIT :limit";
        $result = $this->rawQuery($sql, [
            'status' => self::STATUS_ACTIVE,
            'keyword' => "%{$keyword}%",
            'limit' => $limit
        ]);
        return $result['status'] ? $result['details'] : [];
    }
}