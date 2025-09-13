<?php
// config.php - การตั้งค่าระบบ
session_start();

// กำหนด path หลัก
define('BASE_PATH', __DIR__);
define('DB_PATH', BASE_PATH . '/database.db');

// ตั้งค่า timezone
date_default_timezone_set('Asia/Bangkok');

// ตั้งค่าการแสดง error (ปิดในโปรดักชั่น)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ประเภทหวย
define('LOTTERY_TYPES', [
    'number2_top' => ['name' => '2 ตัวบน', 'default_rate' => 70, 'digits' => 2],
    'number2_bottom' => ['name' => '2 ตัวล่าง', 'default_rate' => 80, 'digits' => 2],
    'number3_top' => ['name' => '3 ตัวบน', 'default_rate' => 600, 'digits' => 3]
]);

// สิทธิ์ที่มีในระบบ
define('PERMISSIONS', [
    'dashboard' => ['view'],
    'tickets' => ['view', 'add', 'edit', 'delete'],
    'orders' => ['view', 'edit', 'delete', 'view_all'],
    'numbers' => ['view'],
    'reports' => ['view', 'export'],
    'credits' => ['view', 'add', 'edit', 'view_all'],
    'limits' => ['view', 'add', 'edit', 'delete'],
    'draws' => ['view', 'add', 'edit', 'delete'],
    'results' => ['view', 'add', 'edit'],
    'users' => ['view', 'add', 'edit', 'delete'],
    'roles' => ['view', 'add', 'edit', 'delete'],
    'profile' => ['view', 'edit']
]);

// ฟังก์ชันโหลด class อัตโนมัติ
spl_autoload_register(function ($class) {
    $file = BASE_PATH . '/lib/' . strtolower($class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});