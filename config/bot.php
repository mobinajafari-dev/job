<?php
return [
    'token' => getenv('BOT_TOKEN'),
    'admin_ids' => explode(',', getenv('ADMIN_IDS')),
    'channel_id' => getenv('CHANNEL_ID'),
    'job_price' => 10000, // قیمت هر آگهی به تومان
    'referral_bonus' => 2000 // پاداش معرف
];