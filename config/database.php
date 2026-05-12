<?php
return [
    'host' => getenv('DB_HOST'),
    'name' => getenv('DB_NAME'),
    'user' => getenv('DB_USER') ,
    'password' => getenv('DB_PASS'),
    'charset' => 'utf8mb4'
];