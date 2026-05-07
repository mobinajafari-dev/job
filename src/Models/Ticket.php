<?php
namespace Models;

use Core\Model;
use Helpers\Database;

class Ticket extends Model {
    protected $table = 'tickets';

    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_CLOSED = 'closed';
    const STATUS_RESOLVED = 'resolved';

    /**
     * ایجاد تیکت جدید
     */
    public function create($user_id, $subject, $message, $category = 'general') {
        $sql = "INSERT INTO {$this->table} (user_id, subject, message, category, status, created_at) 
                VALUES (:user_id, :subject, :message, :category, :status, NOW())";

        $result = Database::execute($sql, [
            'user_id' => $user_id,
            'subject' => $subject,
            'message' => $message,
            'category' => $category,
            'status' => self::STATUS_OPEN
        ]);

        if ($result) {
            return Database::lastInsertId();
        }

        return false;
    }

    /**
     * دریافت تیکت توسط ID
     */
    public function find($id) {
        $sql = "SELECT t.*, u.username, u.telegram_id, u.phone 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = :id";

        return Database::query($sql, ['id' => $id]);
    }

    /**
     * دریافت تیکت‌های کاربر
     */
    public function getUserTickets($user_id, $limit = 20, $offset = 0) {
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
     * پاسخ به تیکت توسط ادمین
     */
    public function reply($ticket_id, $admin_id, $response) {
        $sql = "UPDATE {$this->table} SET 
                admin_response = :response, 
                admin_id = :admin_id,
                status = :status,
                responded_at = NOW(),
                updated_at = NOW()
                WHERE id = :ticket_id";

        return Database::execute($sql, [
            'response' => $response,
            'admin_id' => $admin_id,
            'status' => self::STATUS_IN_PROGRESS,
            'ticket_id' => $ticket_id
        ]);
    }

    /**
     * بستن تیکت
     */
    public function close($ticket_id, $resolve_note = null) {
        $sql = "UPDATE {$this->table} SET 
                status = :status, 
                closed_at = NOW(),
                resolve_note = :note,
                updated_at = NOW()
                WHERE id = :ticket_id";

        return Database::execute($sql, [
            'status' => self::STATUS_CLOSED,
            'note' => $resolve_note,
            'ticket_id' => $ticket_id
        ]);
    }

    /**
     * دریافت تیکت‌های باز
     */
    public function getOpenTickets($limit = 50) {
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.status IN (:open, :in_progress) 
                ORDER BY t.created_at ASC 
                LIMIT :limit";

        return Database::queryAll($sql, [
            'open' => self::STATUS_OPEN,
            'in_progress' => self::STATUS_IN_PROGRESS,
            'limit' => $limit
        ]);
    }

    /**
     * آمار تیکت‌ها
     */
    public function getStats() {
        $sql = "SELECT 
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                    COUNT(*) as total
                FROM {$this->table}";

        return Database::query($sql);
    }

    /**
     * میانگین زمان پاسخگویی
     */
    public function getAverageResponseTime() {
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, responded_at)) as avg_hours 
                FROM {$this->table} 
                WHERE responded_at IS NOT NULL";
        $result = Database::query($sql);

        return round($result['avg_hours'] ?? 0, 2);
    }

    /**
     * دسته‌بندی تیکت‌ها
     */
    public function getCategoryStats() {
        $sql = "SELECT 
                    category, 
                    COUNT(*) as count,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count
                FROM {$this->table} 
                GROUP BY category";

        return Database::queryAll($sql);
    }

    /**
     * جستجوی تیکت‌ها
     */
    public function search($keyword, $status = null, $limit = 20) {
        $sql = "SELECT t.*, u.username 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE (t.subject LIKE :keyword OR t.message LIKE :keyword)";

        $params = ['keyword' => "%{$keyword}%"];

        if ($status) {
            $sql .= " AND t.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY t.created_at DESC LIMIT :limit";
        $params['limit'] = $limit;

        return Database::queryAll($sql, $params);
    }

    /**
     * ارسال نوتیفیکیشن به کاربر برای پاسخ جدید
     */
    public function notifyUser($ticket_id) {
        $ticket = $this->find($ticket_id);

        if ($ticket && $ticket['admin_response']) {
            // این متد توسط سرویس تلگرام استفاده می‌شود
            return [
                'user_id' => $ticket['user_id'],
                'telegram_id' => $ticket['telegram_id'],
                'ticket_id' => $ticket['id'],
                'response' => $ticket['admin_response']
            ];
        }

        return null;
    }

    /**
     * حذف تیکت‌های قدیمی بسته شده
     */
    public function deleteOldClosedTickets($days = 30) {
        $sql = "DELETE FROM {$this->table} 
                WHERE status IN (:closed, :resolved) 
                AND closed_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        return Database::execute($sql, [
            'closed' => self::STATUS_CLOSED,
            'resolved' => self::STATUS_RESOLVED,
            'days' => $days
        ]);
    }
}