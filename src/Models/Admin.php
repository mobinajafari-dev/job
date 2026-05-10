<?php
namespace Models;

use Core\Model;

class Admin extends Model {
    protected $table = 'admins';
    
    public function isAdmin($user_id) {
        $result = $this->select(['id'], ['user_id' => $user_id], false);
        return $result['status'] && $result['details'];
    }
    
    public function addAdmin($user_id, $level = 1, $permissions = null, $created_by = null) {
        $data = [
            'user_id' => $user_id,
            'level' => $level,
            'permissions' => $permissions ? json_encode($permissions) : null,
            'created_by' => $created_by,
            'created_at' => date('Y-m-d H:i:s')
        ];
        return $this->insert($data);
    }
    
    public function getAllAdmins() {
        $sql = "SELECT a.*, u.username, u.telegram_id, u.phone 
                FROM admins a 
                JOIN users u ON a.user_id = u.id 
                ORDER BY a.level DESC, a.created_at ASC";
        $result = $this->rawQuery($sql);
        return $result['status'] ? $result['details'] : [];
    }
    
    public function removeAdmin($user_id) {
        return $this->delete(['user_id' => $user_id]);
    }
}