<?php

$business = json_decode(file_get_contents(__DIR__.'/business.json'), true, 512, JSON_THROW_ON_ERROR);

return [
    'support_email' => env('STORE_SUPPORT_EMAIL', 'franconia.finefragrances@outlook.com'),
    'business_name' => 'Franconia Fine Fragrances',
    'gstin' => '27CWAPP6473N1ZT',
    'business_address' => env('STORE_BUSINESS_ADDRESS') ?: $business['business_address'],
    'grievance_officer' => env('STORE_GRIEVANCE_OFFICER') ?: $business['grievance_officer'],
    'grievance_email' => env('STORE_GRIEVANCE_EMAIL') ?: $business['grievance_email'],
    'grievance_phone' => env('STORE_GRIEVANCE_PHONE') ?: $business['grievance_phone'],
    'reservation_minutes' => 30,
    'shipping_threshold' => 0,
    'shipping_fee' => 0,
    'gst_rate' => 0.18,
    'backup_directory' => env('STORE_BACKUP_DIR', ''),
    'mysqldump_binary' => env('STORE_MYSQLDUMP_BINARY', PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump'),
];
