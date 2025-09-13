<?php
// public/index.php - จุดเข้าหลักของระบบ (Fixed for AJAX)

// ตรวจสอบ AJAX request ก่อนอื่นใด
$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
           (isset($_POST['action']) && !empty($_POST['action'])) ||
           (isset($_GET['action']) && !empty($_GET['action']));

// ถ้าเป็น AJAX และมี action ให้ข้ามการ buffer
if (!$is_ajax) {
    ob_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';

// กำหนดหน้าเริ่มต้น
$page = $_GET['page'] ?? 'dashboard';

// หน้าที่ไม่ต้อง login
$public_pages = ['login'];

// Process logout first
if ($page === 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// เช็คสิทธิ์การเข้าถึง (ยกเว้น AJAX จะเช็คในแต่ละ page)
if (!in_array($page, $public_pages) && !$is_ajax) {
    if (!current_user()) {
        header('Location: ?page=login');
        exit;
    }
}

// Map หน้ากับไฟล์
$page_files = [
    'login' => 'login.php',
    'dashboard' => 'dashboard.php',
    'tickets' => 'tickets.php',
    'orders' => 'orders.php',
    'numbers' => 'numbers.php',
    'reports' => 'reports.php',
    'credits' => 'credits.php',
    'limits' => 'limits.php',
    'draws' => 'draws.php',
    'results' => 'results.php',
    'users' => 'users.php',
    'roles' => 'roles.php',
    'profile' => 'profile.php'
];

// เช็คว่าไฟล์มีอยู่จริง
$file_to_load = $page_files[$page] ?? 'dashboard.php';
$file_path = __DIR__ . '/../pages/' . $file_to_load;

if (!file_exists($file_path)) {
    $file_to_load = 'dashboard.php';
    $file_path = __DIR__ . '/../pages/' . $file_to_load;
}

// ถ้าเป็น AJAX ให้โหลดไฟล์เลย (ไม่ต้องมี header/footer)
if ($is_ajax) {
    require $file_path;
    exit;
}

// === NORMAL PAGE LOAD ===
if ($page === 'login') {
    // หน้า login ไม่ต้องมี header/footer
    require $file_path;
} else {
    require __DIR__ . '/../partials/header.php';
    require __DIR__ . '/../partials/nav.php';
    
    echo '<div class="main-content">';
    echo '<div class="container">';
    
    // แสดง alert ถ้ามี
    $alert = get_alert();
    if ($alert) {
        echo '<div class="alert alert-' . escape($alert['type']) . '" id="page-alert">';
        echo escape($alert['message']);
        echo '</div>';
    }
    
    // Load page content
    require $file_path;
    
    echo '</div>';
    echo '</div>';
    
    require __DIR__ . '/../partials/footer.php';
}

// Final flush for normal pages
if (!$is_ajax) {
    ob_end_flush();
}
?>