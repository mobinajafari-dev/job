<?php
namespace Helpers;

class Logger {
    private static $logFile = __DIR__ . '/../../logs/app.log';

    public static function info($message) {
        self::write('INFO', $message);
    }

    public static function error($message) {
        self::write('ERROR', $message);
    }

    public static function warning($message) {
        self::write('WARNING', $message);
    }

    private static function write($level, $message) {
        $log = date('Y-m-d H:i:s') . " [$level] " . $message . PHP_EOL;
        file_put_contents(self::$logFile, $log, FILE_APPEND);
    }
}