<?php
// pages/dashboard.php - หน้า Dashboard (แก้ไขให้แสดงตามสิทธิ์)

$current_user = current_user();
$current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();
$current_draw = get_draw($current_draw_id);

if (!$current_draw) {
    echo '<div class="alert alert-warning">ไม่พบข้อมูลงวด กรุณาเลือกงวดใหม่</div>';
    exit;
}

$db = DB::getInstance();
$is_owner = is_owner();
$user_condition = !$is_owner ? "AND t.user_id = " . $current_user['id'] : "";
$user_name = $current_user['name'];


// Cache key สำหรับแต่ละ query
$cache_prefix = "dashboard_{$current_draw_id}_" . ($is_owner ? 'all' : $current_user['id']);
$cache_ttl = 60; // cache 1 นาที

// ดึงสถิติงวดปัจจุบัน (ใช้ cache)
$stats = cache_get($cache_prefix . '_stats', $cache_ttl);
if ($stats === null) {
    $stats = $db->fetch("
        SELECT 
            COUNT(DISTINCT t.id) as total_tickets,
            COUNT(DISTINCT t.user_id) as total_sellers,
            COALESCE(SUM(tl.amount), 0) as total_sales,
            COUNT(DISTINCT tl.id) as total_lines
        FROM tickets t
        LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
        WHERE t.draw_id = ? $user_condition
    ", [$current_draw_id]);
    cache_set($cache_prefix . '_stats', $stats, $cache_ttl);
}

// ยอดขายแยกตามประเภท (ใช้ cache)
$sales_by_type = cache_get($cache_prefix . '_sales_type', $cache_ttl);
if ($sales_by_type === null) {
    $sales_by_type = $db->fetchAll("
        SELECT 
            tl.type,
            COUNT(DISTINCT tl.number) as unique_numbers,
            COUNT(tl.id) as total_lines,
            COALESCE(SUM(tl.amount), 0) as total_amount
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? $user_condition
        GROUP BY tl.type
    ", [$current_draw_id]);
    cache_set($cache_prefix . '_sales_type', $sales_by_type, $cache_ttl);
}

// เลขที่ขายดี Top 5 (ใช้ cache)
$top_numbers = cache_get($cache_prefix . '_top5', $cache_ttl);
if ($top_numbers === null) {
    $top_numbers = $db->fetchAll("
        SELECT 
            tl.type,
            tl.number,
            SUM(tl.amount) as total_amount,
            COUNT(tl.id) as count
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? $user_condition
        GROUP BY tl.type, tl.number
        ORDER BY total_amount DESC
        LIMIT 5
    ", [$current_draw_id]);
    cache_set($cache_prefix . '_top5', $top_numbers, $cache_ttl);
}

// สำหรับ Owner: แสดงยอดขายแยกตามผู้ขาย (ใช้ cache)
$sales_by_seller = [];
if ($is_owner) {
    $sales_by_seller = cache_get($cache_prefix . '_by_seller', $cache_ttl);
    if ($sales_by_seller === null) {
        $sales_by_seller = $db->fetchAll("
            SELECT 
                u.id,
                u.name,
                COUNT(DISTINCT t.id) as ticket_count,
                COALESCE(SUM(tl.amount), 0) as total_sales,
                COUNT(DISTINCT tl.number) as unique_numbers
            FROM users u
            LEFT JOIN tickets t ON t.user_id = u.id AND t.draw_id = ?
            LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
            WHERE t.id IS NOT NULL
            GROUP BY u.id, u.name
            ORDER BY total_sales DESC
        ", [$current_draw_id]);
        cache_set($cache_prefix . '_by_seller', $sales_by_seller, $cache_ttl);
    }
}

// ตรวจสอบสถานะงวด
$is_closed = $current_draw['status'] !== 'open';
$has_result = $db->fetch("SELECT draw_id FROM results WHERE draw_id = ?", [$current_draw_id]);
?>

<h1 class="mb-3">
    📊 Dashboard <?= !$is_owner ? '- ' . escape($user_name) : '(ทั้งหมด)' ?> - <?= escape($current_draw['name']) ?>
    <?php if ($is_closed): ?>
        <span class="badge badge-danger">ปิดรับแล้ว</span>
    <?php else: ?>
        <span class="badge badge-success">เปิดรับ</span>
    <?php endif; ?>
    <?php if ($has_result): ?>
        <span class="badge badge-warning">ออกผลแล้ว</span>
    <?php endif; ?>
</h1>

<!-- สถิติหลัก -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-value">฿ <?= format_number($stats['total_sales']) ?></div>
        <div class="stat-label">ยอดขาย<?= !$is_owner ? ' ' : 'รวม' ?></div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
        <div class="stat-value"><?= format_number($stats['total_tickets'], 0) ?></div>
        <div class="stat-label">จำนวนโพย<?= !$is_owner ? ' ' : '' ?></div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
        <div class="stat-value"><?= format_number($stats['total_lines'], 0) ?></div>
        <div class="stat-label">รายการ<?= !$is_owner ? ' ' : 'ทั้งหมด' ?></div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
        <div class="stat-value"><?= format_number($stats['total_sellers'], 0) ?></div>
        <div class="stat-label"><?= $is_owner ? 'ผู้ขาย' : 'สถานะ' ?></div>
        <?php if (!$is_owner): ?>
            <small style="color: rgba(255,255,255,0.8);">
                <?= $current_user['is_active'] ? 'ใช้งาน' : 'ปิด' ?>
            </small>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-2">
    <!-- ยอดขายแยกตามประเภท -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">💰 ยอดขายแยกตามประเภท</h3>
        </div>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ประเภท</th>
                    <th class="text-center">จำนวนเลข</th>
                    <th class="text-center">รายการ</th>
                    <th class="text-right">ยอดรวม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales_by_type as $row): ?>
                <tr>
                    <td>
                        <span class="badge badge-info">
                            <?= escape(LOTTERY_TYPES[$row['type']]['name'] ?? $row['type']) ?>
                        </span>
                    </td>
                    <td class="text-center"><?= format_number($row['unique_numbers'], 0) ?></td>
                    <td class="text-center"><?= format_number($row['total_lines'], 0) ?></td>
                    <td class="text-right">
                        <strong>฿ <?= format_number($row['total_amount']) ?></strong>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($sales_by_type)): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted">ยังไม่มีข้อมูล</td>
                </tr>
                <?php endif; ?>
                
                <!-- แสดงยอดรวม -->
                <?php if (!empty($sales_by_type)): ?>
                <tr class="bg-light">
                    <td colspan="3"><strong>รวมทั้งหมด</strong></td>
                    <td class="text-right">
                        <strong class="text-primary">
                            ฿ <?= format_number(array_sum(array_column($sales_by_type, 'total_amount'))) ?>
                        </strong>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- เลขที่ขายดี และ ยอดขายแยกตามผู้ขาย -->
    <?php if ($is_owner): ?>
        <!-- Owner เห็นทั้ง 2 ตาราง -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">👥 ยอดขายแยกตามผู้ขาย</h3>
            </div>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ผู้ขาย</th>
                        <th class="text-center">โพย</th>
                        <th class="text-center">เลข</th>
                        <th class="text-right">ยอดขาย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales_by_seller as $seller): ?>
                    <tr>
                        <td><?= escape($seller['name']) ?></td>
                        <td class="text-center"><?= $seller['ticket_count'] ?></td>
                        <td class="text-center"><?= $seller['unique_numbers'] ?></td>
                        <td class="text-right">
                            <strong>฿ <?= format_number($seller['total_sales']) ?></strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🔥 เลขขายดี Top 5 (ทั้งระบบ)</h3>
            </div>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>อันดับ</th>
                        <th>เลข</th>
                        <th>ประเภท</th>
                        <th class="text-right">ยอดขาย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_numbers as $index => $row): ?>
                    <tr>
                        <td>
                            <span class="badge badge-warning"><?= $index + 1 ?></span>
                        </td>
                        <td>
                            <strong style="font-size: 1.2em; color: var(--primary);">
                                <?= escape($row['number']) ?>
                            </strong>
                        </td>
                        <td>
                            <small><?= escape(LOTTERY_TYPES[$row['type']]['name'] ?? $row['type']) ?></small>
                        </td>
                        <td class="text-right">
                            <strong>฿ <?= format_number($row['total_amount']) ?></strong>
                            <br>
                            <small class="text-muted"><?= $row['count'] ?> รายการ</small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($top_numbers)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">ยังไม่มีข้อมูล</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <!-- Seller เห็นแค่เลขขายดีของตัวเอง -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🔥 เลขขายดี Top 5 - <?= escape($user_name) ?></h3>
            </div>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>อันดับ</th>
                        <th>เลข</th>
                        <th>ประเภท</th>
                        <th class="text-right">ยอดขาย</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_numbers as $index => $row): ?>
                    <tr>
                        <td>
                            <span class="badge badge-warning"><?= $index + 1 ?></span>
                        </td>
                        <td>
                            <strong style="font-size: 1.2em; color: var(--primary);">
                                <?= escape($row['number']) ?>
                            </strong>
                        </td>
                        <td>
                            <small><?= escape(LOTTERY_TYPES[$row['type']]['name'] ?? $row['type']) ?></small>
                        </td>
                        <td class="text-right">
                            <strong>฿ <?= format_number($row['total_amount']) ?></strong>
                            <br>
                            <small class="text-muted"><?= $row['count'] ?> รายการ</small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($top_numbers)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">ยังไม่มีข้อมูล</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    </div>

<!-- Quick Actions -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">⚡ ทางลัด</h3>
    </div>
    <div class="d-flex gap-2" style="flex-wrap: wrap;">
        <?php if (can($current_user, 'tickets.add') && !$is_closed): ?>
            <a href="?page=tickets" class="btn btn-primary btn-lg">
                📝 คีย์โพยใหม่
            </a>
        <?php endif; ?>
        
        <?php if (can($current_user, 'orders.view')): ?>
            <a href="?page=orders" class="btn btn-secondary btn-lg">
                📋 ดูโพย<?= !$is_owner ? ' ' : 'ทั้งหมด' ?>
            </a>
        <?php endif; ?>
        
        <?php if (can($current_user, 'numbers.view')): ?>
            <a href="?page=numbers" class="btn btn-outline btn-lg">
                🔢 ดูยอดตามเลข
            </a>
        <?php endif; ?>
        
        <?php if (can($current_user, 'reports.view')): ?>
            <a href="?page=reports" class="btn btn-outline btn-lg">
                📊 ดูรายงาน
            </a>
        <?php endif; ?>
        
        <?php if (can($current_user, 'results.add') && $is_closed && !$has_result): ?>
            <a href="?page=results" class="btn btn-warning btn-lg">
                🎯 บันทึกผลรางวัล
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
// Smart auto-refresh
let lastActivity = Date.now();
let refreshInterval = 30000; // 30 วินาที

// Track user activity
['mousemove', 'keypress', 'click', 'scroll'].forEach(event => {
    document.addEventListener(event, () => {
        lastActivity = Date.now();
    });
});

// Refresh only if active
setInterval(() => {
    const idleTime = Date.now() - lastActivity;
    
    // Refresh ถ้า active ในช่วง 2 นาทีที่ผ่านมา
    if (idleTime < 120000) {
        location.reload();
    }
}, refreshInterval);
</script>