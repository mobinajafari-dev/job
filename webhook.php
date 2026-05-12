<?php
/**
 * webhook.php - نقطه ورود ربات تلگرام
 */

// ============================================
// دستورات use باید در بیرون (global scope) باشند
// ============================================
use Core\Router;
use Services\TelegramService;
use Helpers\Logger;

// ============================================
// شروع session
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// تنظیمات خطاها
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// بارگذاری اتولودر
// ============================================
spl_autoload_register(function ($class) {
    $prefixes = [
        'Controllers\\' => __DIR__ . '/src/Controllers/',
        'Models\\' => __DIR__ . '/src/Models/',
        'Services\\' => __DIR__ . '/src/Services/',
        'Helpers\\' => __DIR__ . '/src/Helpers/',
        'Core\\' => __DIR__ . '/src/Core/',
        'Middleware\\' => __DIR__ . '/src/Middleware/',
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

// ============================================
// بارگذاری .env
// ============================================
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

// ============================================
// تعریف ثابت‌ها
// ============================================
define('BOT_TOKEN', getenv('BOT_TOKEN'));
define('ADMIN_IDS', array_filter(explode(',', getenv('ADMIN_IDS') ?: '')));
define('BOT_USERNAME', getenv('BOT_USERNAME') ?: '');
define('CHANNEL_ID', getenv('CHANNEL_ID') ?: '');
define('JOB_PRICE', (int)(getenv('JOB_PRICE') ?: 10000));
define('REFERRAL_BONUS', (int)(getenv('REFERRAL_BONUS') ?: 2000));
// تنظیمات درگاه پرداخت
define('ZARINPAL_MERCHANT_ID', getenv('ZARINPAL_MERCHANT_ID') ?: '');
define('CALLBACK_URL', getenv('CALLBACK_URL') ?: '');

// ============================================
// بررسی وجود توکن
// ============================================
if (!BOT_TOKEN) {
    Logger::error("FATAL: BOT_TOKEN is not defined in .env file");
    http_response_code(500);
    echo "Configuration error";
    exit;
}

// ============================================
// دریافت اطلاعات از تلگرام
// ============================================
$input = file_get_contents('php://input');
$update = json_decode($input, true);

// لاگ برای دیباگ
if ($update && isset($update['message'])) {
    $chat_id = $update['message']['chat']['id'] ?? 'unknown';
    $text = $update['message']['text'] ?? '';
    Logger::info("[WEBHOOK] Chat: {$chat_id}, Text: " . substr($text, 0, 100));
} elseif ($update && isset($update['callback_query'])) {
    $chat_id = $update['callback_query']['message']['chat']['id'] ?? 'unknown';
    $data = $update['callback_query']['data'] ?? '';
    Logger::info("[WEBHOOK] Callback: {$chat_id}, Data: " . substr($data, 0, 100));
}

// پاسخ به درخواست‌های خالی (keep-alive)
if (!$update) {
    http_response_code(200);
    echo "ok";
    exit;
}

// ============================================
// پردازش درخواست با Router
// ============================================
try {
    $router = new Router();
    $router->process($update);
} catch (Exception $e) {
    Logger::error("[WEBHOOK ERROR] " . $e->getMessage());
    Logger::error("[WEBHOOK TRACE] " . $e->getTraceAsString());

    // ارسال خطا به ادمین
    if (defined('ADMIN_IDS') && !empty(ADMIN_IDS)) {
        try {
            $telegram = new TelegramService(BOT_TOKEN);
            $errorMessage = "⚠️ خطا در ربات:\n\n"
                . "Error: " . $e->getMessage() . "\n"
                . "Line: " . $e->getLine() . "\n"
                . "File: " . basename($e->getFile());

            foreach (ADMIN_IDS as $admin_id) {
                if ($admin_id) {
                    $telegram->sendMessage($admin_id, $errorMessage);
                }
            }
        } catch (Exception $e2) {
            Logger::error("[WEBHOOK] Failed to notify admin: " . $e2->getMessage());
        }
    }

    http_response_code(500);
    echo "error";
    exit;
}

http_response_code(200);
echo "ok";