<?php
namespace Helpers;

use PDO;
use PDOException;

class Database {
    private static $connection = null;

    public static function getConnection() {
        if (self::$connection === null) {
            try {
                $config = require __DIR__ . '/../../config/database.php';
                $dsn = "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}";
                self::$connection = new PDO($dsn, $config['user'], $config['password']);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                Logger::error("Database connection failed: " . $e->getMessage());
                throw new \Exception("Database connection failed");
            }
        }
        return self::$connection;
    }

    public static function query($sql, $params = []) {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    public static function queryAll($sql, $params = []) {
        $stmt = self::getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute($sql, $params = []) {
        $stmt = self::getConnection()->prepare($sql);
        return $stmt->execute($params);
    }

    public static function lastInsertId() {
        return self::getConnection()->lastInsertId();
    }
}