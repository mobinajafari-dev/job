<?php
namespace Core;

use Helpers\Database;

abstract class Model {
    protected $table;
    protected $db;

    public function __construct() {
        $config = require __DIR__ . '/../config/database.php';
        $this->db = new Database($config);
    }

    public function insert($data, $lastID = true) {
        return $this->db->insert($this->table, $data, $lastID);
    }

    public function select($fields = ['*'], $where = [], $fetchAll = true, $params = '') {
        return $this->db->select($this->table, $fields, $where, $fetchAll, $params);
    }

    public function update($data, $where) {
        return $this->db->update($this->table, $data, $where);
    }

    public function delete($where) {
        return $this->db->delete($this->table, $where);
    }

    public function rawQuery($sql, $params = []) {
        return $this->db->query($sql, $params);
    }

    public function beginTransaction() {
        return $this->db->beginTransaction();
    }

    public function commit() {
        return $this->db->commit();
    }

    public function rollBack() {
        return $this->db->rollBack();
    }
}