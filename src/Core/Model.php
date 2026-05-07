<?php
namespace Core;

use Helpers\Database;

abstract class Model {
    protected $table;
    protected $db;
    
    public function __construct() {
        $this->db = Database::getConnection();
    }
    
    public function query($sql, $params = []) {
        return Database::query($sql, $params);
    }
    
    public function queryAll($sql, $params = []) {
        return Database::queryAll($sql, $params);
    }
    
    public function execute($sql, $params = []) {
        return Database::execute($sql, $params);
    }
    
    public function lastInsertId() {
        return Database::lastInsertId();
    }
}