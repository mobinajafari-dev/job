<?php
namespace Models;

use Core\Model;

class Ticket extends Model {
    protected $table = 'tickets';

    const STATUS_OPEN = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_CLOSED = 'closed';
    const STATUS_RESOLVED = 'resolved';

    public function createTicket($user_id, $subject, $message, $category = 'general') {
        $data = [
            'user_id' => $user_id,
            'subject' => $subject,
            'message' => $message,
            'category' => $category,
            'status' => self::STATUS_OPEN,
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $this->insert($data, true);
    }

    public function findTicket($id) {
        $sql = "SELECT t.*, u.username, u.telegram_id, u.phone 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.id = :id";
        $result = $this->rawQuery($sql, ['id' => $id]);
        return $result['status'] && $result['details'] ? $result['details'][0] : null;
    }

    public function getUserTickets($user_id, $limit = 20, $offset = 0) {
        $sql = "SELECT * FROM {$this->table} 
                WHERE user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        $result = $this->rawQuery($sql, ['user_id' => $user_id, 'limit' => $limit, 'offset' => $offset]);
        return $result['status'] ? $result['details'] : [];
    }

    public function reply($ticket_id, $admin_id, $response) {
        $data = [
            'admin_response' => $response,
            'admin_id' => $admin_id,
            'status' => self::STATUS_IN_PROGRESS,
            'responded_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        return $this->update($data, ['id' => $ticket_id]);
    }

    public function close($ticket_id, $resolve_note = null) {
        $data = [
            'status' => self::STATUS_CLOSED,
            'closed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($resolve_note) {
            $data['resolve_note'] = $resolve_note;
        }
        return $this->update($data, ['id' => $ticket_id]);
    }

    public function getOpenTickets($limit = 50) {
        $sql = "SELECT t.*, u.username, u.telegram_id 
                FROM {$this->table} t 
                JOIN users u ON t.user_id = u.id 
                WHERE t.status IN (:open, :in_progress) 
                ORDER BY t.created_at ASC 
                LIMIT :limit";
        $result = $this->rawQuery($sql, [
            'open' => self::STATUS_OPEN,
            'in_progress' => self::STATUS_IN_PROGRESS,
            'limit' => $limit
        ]);
        return $result['status'] ? $result['details'] : [];
    }

    public function getStats() {
        $sql = "SELECT 
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                    COUNT(*) as total
                FROM {$this->table}";
        $result = $this->rawQuery($sql);
        return $result['status'] && $result['details'] ? $result['details'][0] : [];
    }

    public function getAverageResponseTime() {
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, responded_at)) as avg_hours 
                FROM {$this->table} 
                WHERE responded_at IS NOT NULL";
        $result = $this->rawQuery($sql);
        return $result['status'] && $result['details'] ? round($result['details'][0]['avg_hours'] ?? 0, 2) : 0;
    }

    public function getCategoryStats() {
        $sql = "SELECT 
                    category, 
                    COUNT(*) as count,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count
                FROM {$this->table} 
                GROUP BY category";
        $result = $this->rawQuery($sql);
        return $result['status'] ? $result['details'] : [];
    }
}