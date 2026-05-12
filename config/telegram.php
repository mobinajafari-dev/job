<?php
// تنظیمات پیشرفته تلگرام
return [
    'token' => getenv('BOT_TOKEN'),
    'api_url' => 'https://api.telegram.org/bot',
    'file_url' => 'https://api.telegram.org/file/bot',

    // تنظیمات کیبورد
    'keyboards' => [
        'main' => [
            'keyboard' => [
                [['text' => '📝 ارسال آگهی'], ['text' => '💰 کیف پول من']],
                [['text' => '💳 شارژ کیف پول'], ['text' => '👥 دعوت از دوستان']],
                [['text' => '📞 پشتیبانی'], ['text' => 'ℹ️ راهنما']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ],
        'cancel' => [
            'keyboard' => [
                [['text' => '❌ انصراف']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ]
    ],

    // تنظیمات پیام‌ها (بدون استفاده از ثابت)
    'messages' => [
        'welcome' => "به ربات آگهی‌های کاری خوش آمدید! 👋\n\n",
        'need_phone' => "لطفاً شماره تلفن خود را ارسال کنید:",
        'insufficient_balance' => "موجودی کیف پول شما کافی نیست. لطفاً کیف پول خود را شارژ کنید."
    ]
];