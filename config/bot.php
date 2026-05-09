<?php
return [
    'token' => getenv('BOT_TOKEN'),
    'admin_ids' => array_filter(explode(',', getenv('ADMIN_IDS') ?: '')),
    'channel_id' => getenv('CHANNEL_ID'),

    // تنظیمات سکه (مقادیر پیش‌فرض - مقدار واقعی از دیتابیس می‌آید)
    'coin_value' => 1000,      // هر سکه = 1000 تومان
    'job_price_coins' => 10,   // قیمت آگهی = 10 سکه
    'referral_bonus_coins' => 2, // پاداش دعوت = 2 سکه
    'welcome_bonus_coins' => 10, // هدیه ثبت‌نام = 10 سکه
];