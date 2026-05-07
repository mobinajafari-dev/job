<?php
// autoload.php - نسخه ساده بدون composer

spl_autoload_register(function ($class) {
    // تبدیل کلاس به مسیر فایل
    $path = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    
    // مسیرهای ممکن رو چک کن
    $possiblePaths = [
        __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php',
        __DIR__ . '/' . str_replace('\\', '/', $class) . '.php',
        __DIR__ . '/controllers/' . str_replace('Controllers\\', '', $class) . '.php',
        __DIR__ . '/models/' . str_replace('Models\\', '', $class) . '.php',
        __DIR__ . '/services/' . str_replace('Services\\', '', $class) . '.php',
        __DIR__ . '/helpers/' . str_replace('Helpers\\', '', $class) . '.php',
    ];
    
    foreach($possiblePaths as $path) {
        if(file_exists($path)) {
            require_once $path;
            return;
        }
    }
});