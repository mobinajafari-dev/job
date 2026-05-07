<?php
// webhook.php - نسخه نهایی با معماری MVC

error_reporting(E_ALL);
ini_set('display_errors', 1);

// بارگذاری اتولودر ساده
spl_autoload_register(function ($class) {
    $prefixes = [
        'Controllers\\' => __DIR__ . '/src/Controllers/',
        'Models\\' => __DIR__ . '/src/Models/',
        'Services\\' => __DIR__ . '/src/Services/',
        'Helpers\\' => __DIR__ . '/src/Helpers/',
        'Core\\' => __DIR__ . '/src/Core/',
    ];
    
    foreach ($prefixes as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// لود کردن دات انوی (اگر داری)
if(file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach($lines as $line) {
        if(strpos(trim($line), '#') === 0) continue;
        if(strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
            $_ENV[trim($key)] = trim($value);
        }
    }
}

// تعریف ثابت‌ها
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '');
define('ADMIN_IDS', explode(',', getenv('ADMIN_IDS') ?: ''));
define('CHANNEL_ID', getenv('CHANNEL_ID') ?: '@your_channel');
define('JOB_PRICE', getenv('JOB_PRICE') ?: 10000);
define('REFERRAL_BONUS', getenv('REFERRAL_BONUS') ?: 2000);

use Controllers\BotController;

// دریافت اطلاعات از تلگرام
$update = json_decode(file_get_contents('php://input'), true);

if(!$update) {
    http_response_code(200);
    exit;
}

// ایجاد کنترلر و پردازش
$bot = new BotController();
$bot->handle($update);

http_response_code(200);
echo "ok";