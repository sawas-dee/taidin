<?php
// lib/functions.php - ฟังก์ชันช่วยต่างๆ

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

// ============ Safe Escape Function ============
function escape($v) {
    // รองรับ null และค่าว่าง
    if ($v === null || $v === false) {
        return '';
    }
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// ============ Draw Functions ============
function draw_current_id() {
    $db = DB::getInstance();
    $draw = $db->fetch("SELECT id FROM draws WHERE status = 'open' ORDER BY id DESC LIMIT 1");
    return $draw ? $draw['id'] : null;
}

function draw_list($status = null) {
    $db = DB::getInstance();
    $sql = "SELECT * FROM draws";
    $params = [];
    
    if ($status) {
        $sql .= " WHERE status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY id DESC";
    return $db->fetchAll($sql, $params);
}

function get_draw($id) {
    if (!$id) return null;
    $db = DB::getInstance();
    return $db->fetch("SELECT * FROM draws WHERE id = ?", [$id]);
}

// ============ Rate & Limit Functions ============
function default_rate($type) {
    $db = DB::getInstance();
    $key = 'rate_' . str_replace('number', '', $type);
    $setting = $db->fetch("SELECT val FROM settings WHERE key = ?", [$key]);
    return $setting ? floatval($setting['val']) : 100;
}

function rate_for($type, $drawId, $number) {
    if (!$drawId) return default_rate($type);
    
    $db = DB::getInstance();
    
    // 1. เช็คเรทเฉพาะเลข
    if ($number) {
        $specific = $db->fetch(
            "SELECT rate_override FROM limits_num WHERE draw_id = ? AND type = ? AND number = ?",
            [$drawId, $type, $number]
        );
        if ($specific && $specific['rate_override'] !== null) {
            return floatval($specific['rate_override']);
        }
    }
    
    // 2. เช็คเรทมาตรฐานของชนิด
    $standard = $db->fetch(
        "SELECT rate_override FROM limits_std WHERE draw_id = ? AND type = ?",
        [$drawId, $type]
    );
    if ($standard && $standard['rate_override'] !== null) {
        return floatval($standard['rate_override']);
    }
    
    // 3. ใช้เรท default
    return default_rate($type);
}

function max_for($type, $drawId, $number) {
    if (!$drawId) return PHP_FLOAT_MAX;
    
    $db = DB::getInstance();
    
    // 1. เช็คลิมิตเฉพาะเลข
    if ($number) {
        $specific = $db->fetch(
            "SELECT max_total FROM limits_num WHERE draw_id = ? AND type = ? AND number = ?",
            [$drawId, $type, $number]
        );
        if ($specific && $specific['max_total'] !== null) {
            return floatval($specific['max_total']);
        }
    }
    
    // 2. เช็คลิมิตมาตรฐานของชนิด
    $standard = $db->fetch(
        "SELECT max_total FROM limits_std WHERE draw_id = ? AND type = ?",
        [$drawId, $type]
    );
    if ($standard && $standard['max_total'] !== null) {
        return floatval($standard['max_total']);
    }
    
    // 3. ไม่จำกัด
    return PHP_FLOAT_MAX;
}

function current_sold($drawId, $type, $number) {
    if (!$drawId) return 0;
    
    $db = DB::getInstance();
    $result = $db->fetch(
        "SELECT COALESCE(SUM(tl.amount), 0) as total 
         FROM ticket_lines tl 
         JOIN tickets t ON t.id = tl.ticket_id 
         WHERE t.draw_id = ? AND tl.type = ? AND tl.number = ?",
        [$drawId, $type, $number]
    );
    return floatval($result['total']);
}

function check_limit($drawId, $type, $number, $amount) {
    if (!$drawId) {
        return [
            'can_sell' => false,
            'available' => 0,
            'max' => 0,
            'sold' => 0
        ];
    }
    
    $max = max_for($type, $drawId, $number);
    $sold = current_sold($drawId, $type, $number);
    $available = $max - $sold;
    
    return [
        'can_sell' => $amount <= $available,
        'available' => $available,
        'max' => $max,
        'sold' => $sold
    ];
}

// ============ Auth Functions ============
function current_user() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    $db = DB::getInstance();
    return $db->fetch(
        "SELECT u.*, r.name as role_name, r.permissions_json 
         FROM users u 
         JOIN roles r ON r.id = u.role_id 
         WHERE u.id = ? AND u.is_active = 1",
        [$_SESSION['user_id']]
    );
}

function auth_required() {
    if (!current_user()) {
        header('Location: /public/index.php?page=login');
        exit;
    }
}

function can($user, $permission) {
    if (!$user || !isset($user['permissions_json'])) {
        return false;
    }
    
    $permissions = json_decode($user['permissions_json'], true);
    return $permissions && in_array($permission, $permissions);
}

function is_owner() {
    $user = current_user();
    return $user && $user['role_name'] === 'Owner';
}

// ============ Password Functions ============
function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function check_password($password, $hash) {
    return password_verify($password, $hash);
}

// ============ Number Functions ============
function reverse_number($number) {
    return strrev($number);
}

function permutate_number($number) {
    $digits = str_split($number);
    $perms = [];
    
    $generate = function($arr, $temp = '') use (&$generate, &$perms) {
        if (empty($arr)) {
            $perms[] = $temp;
            return;
        }
        
        for ($i = 0; $i < count($arr); $i++) {
            $newArr = $arr;
            $removed = array_splice($newArr, $i, 1);
            $generate($newArr, $temp . $removed[0]);
        }
    };
    
    $generate($digits);
    return array_unique($perms);
}

// ============ Format Functions ============
function format_number($number, $decimals = 2) {
    if ($number === null || $number === '') return '0';
    return number_format(floatval($number), $decimals);
}

function format_date($date, $format = 'd/m/Y H:i') {
    if (!$date) return '';
    return date($format, strtotime($date));
}

function thai_date($date) {
    if (!$date) return '';
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 
               'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $d = date('j', strtotime($date));
    $m = $months[intval(date('n', strtotime($date)))];
    $y = date('Y', strtotime($date)) + 543;
    return "$d $m $y";
}

// ============ Settings Functions ============
function get_setting($key, $default = null) {
    $db = DB::getInstance();
    $setting = $db->fetch("SELECT val FROM settings WHERE key = ?", [$key]);
    return $setting ? $setting['val'] : $default;
}

function set_setting($key, $value) {
    $db = DB::getInstance();
    $exists = $db->fetch("SELECT key FROM settings WHERE key = ?", [$key]);
    
    if ($exists) {
        $db->update('settings', 
            ['val' => $value, 'updated_at' => date('Y-m-d H:i:s')],
            'key = ?', [$key]
        );
    } else {
        $db->insert('settings', ['key' => $key, 'val' => $value]);
    }
}

// ============ Alert Functions ============
function set_alert($type, $message) {
    $_SESSION['alert'] = [
        'type' => $type,
        'message' => $message
    ];
}

function get_alert() {
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        unset($_SESSION['alert']);
        return $alert;
    }
    return null;
}

// Safe redirect function
function safe_redirect($url) {
    // Clean any output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if (!headers_sent($file, $line)) {
        header("Location: $url");
        exit;
    } else {
        // If headers sent, use JavaScript redirect
        echo "<script>window.location.href='$url';</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=$url'></noscript>";
        exit;
    }
}

// ============ Security Functions ============
function csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============ Validation Functions ============
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validate_number($value, $min = null, $max = null) {
    if (!is_numeric($value)) return false;
    if ($min !== null && $value < $min) return false;
    if ($max !== null && $value > $max) return false;
    return true;
}

function validate_lottery_number($number, $type) {
    if (!$number || !$type) return false;
    
    $config = LOTTERY_TYPES[$type] ?? null;
    if (!$config) return false;
    
    $digits = $config['digits'];
    return preg_match('/^\d{' . $digits . '}$/', $number);
}

// ============ Cache Functions (ปรับปรุง) ============
function cache_get($key, $ttl = 300) {
    $cache_dir = BASE_PATH . '/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0777, true); // เพิ่ม @ เพื่อป้องกัน warning
    }
    
    $cache_file = $cache_dir . '/' . md5($key) . '.cache';
    
    if (file_exists($cache_file)) {
        $content = @file_get_contents($cache_file);
        if ($content !== false) {
            $data = @unserialize($content);
            if ($data && isset($data['expires']) && $data['expires'] > time()) {
                return $data['value'];
            }
        }
        // ลบ cache หมดอายุ
        @unlink($cache_file);
    }
    return null;
}

function cache_set($key, $value, $ttl = 300) {
    $cache_dir = BASE_PATH . '/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0777, true);
    }
    
    $cache_file = $cache_dir . '/' . md5($key) . '.cache';
    $data = [
        'expires' => time() + $ttl,
        'value' => $value
    ];
    
    return @file_put_contents($cache_file, serialize($data), LOCK_EX); // เพิ่ม LOCK_EX
}

function cache_clear($pattern = '*') {
    $cache_dir = BASE_PATH . '/cache';
    if (!is_dir($cache_dir)) return;
    
    if ($pattern === '*') {
        // ล้างทั้งหมด
        $files = glob($cache_dir . '/*.cache');
    } else {
        // ล้างตาม pattern
        $files = glob($cache_dir . '/' . md5($pattern) . '*.cache');
    }
    
    foreach ($files as $file) {
        @unlink($file);
    }
}

// เพิ่มฟังก์ชันใหม่ - ล้าง cache เมื่อมีการเปลี่ยนแปลงข้อมูล
function cache_invalidate_draw($draw_id) {
    cache_clear("dashboard_{$draw_id}_*");
    cache_clear("numbers_{$draw_id}_*");
    cache_clear("reports_{$draw_id}_*");
    cache_clear("stats_{$draw_id}_*");
}

// ============ Optimized Query Functions ============
function get_draw_stats($draw_id, $user_id = null) {
    $cache_key = "stats_{$draw_id}" . ($user_id ? "_{$user_id}" : "_all");
    
    // ดึงจาก cache ก่อน
    $stats = cache_get($cache_key, 60); // cache 1 นาที
    if ($stats !== null) {
        return $stats;
    }
    
    $db = DB::getInstance();
    $user_condition = $user_id ? "AND t.user_id = {$user_id}" : "";
    
    $stats = $db->fetch("
        SELECT 
            COUNT(DISTINCT t.id) as total_tickets,
            COUNT(DISTINCT t.user_id) as total_sellers,
            COALESCE(SUM(tl.amount), 0) as total_sales,
            COUNT(DISTINCT tl.id) as total_lines,
            SUM(CASE WHEN t.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN t.payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count
        FROM tickets t
        LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
        WHERE t.draw_id = ? {$user_condition}
    ", [$draw_id]);
    
    // บันทึก cache
    cache_set($cache_key, $stats, 60);
    
    return $stats;
}

// ฟังก์ชันสำหรับดึง top numbers
function get_top_numbers($draw_id, $user_id = null, $limit = 5) {
    $cache_key = "top_numbers_{$draw_id}" . ($user_id ? "_{$user_id}" : "_all") . "_{$limit}";
    
    $top_numbers = cache_get($cache_key, 120); // cache 2 นาที
    if ($top_numbers !== null) {
        return $top_numbers;
    }
    
    $db = DB::getInstance();
    $user_condition = $user_id ? "AND t.user_id = {$user_id}" : "";
    
    $top_numbers = $db->fetchAll("
        SELECT 
            tl.type,
            tl.number,
            SUM(tl.amount) as total_amount,
            COUNT(tl.id) as count
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? {$user_condition}
        GROUP BY tl.type, tl.number
        ORDER BY total_amount DESC
        LIMIT ?
    ", [$draw_id, $limit]);
    
    cache_set($cache_key, $top_numbers, 120);
    
    return $top_numbers;
}

function remove_duplicates($array, $key = 'id') {
    $temp_array = [];
    $key_array = [];
    
    foreach($array as $val) {
        if (!in_array($val[$key], $key_array)) {
            $key_array[] = $val[$key];
            $temp_array[] = $val;
        }
    }
    return $temp_array;
}
?>