<?php
// pages/reports.php - รายงาน (เพิ่มการเลือกช่วงเวลาและงวด)

$current_user = current_user();
if (!can($current_user, 'reports.view')) {
    set_alert('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: ?page=dashboard');
    exit;
}

$db = DB::getInstance();

// รับพารามิเตอร์การกรอง
$filter_type = $_GET['filter_type'] ?? 'current'; // current, date_range, draws, all
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$selected_draws = $_GET['draws'] ?? [];

// กำหนดเงื่อนไขการดึงข้อมูล
$draw_ids = [];
$report_title = '';

switch ($filter_type) {
    case 'current':
        // งวดปัจจุบัน
        $current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();
        if ($current_draw_id) {
            $draw_ids = [$current_draw_id];
            $current_draw = get_draw($current_draw_id);
            $report_title = 'งวดปัจจุบัน - ' . escape($current_draw['name']);
        }
        break;
        
    case 'date_range':
        // ช่วงวันที่
        if ($date_from && $date_to) {
            $draws = $db->fetchAll(
                "SELECT id, name FROM draws WHERE draw_date BETWEEN ? AND ? ORDER BY draw_date",
                [$date_from, $date_to]
            );
            $draw_ids = array_column($draws, 'id');
            $report_title = 'ช่วงวันที่ ' . thai_date($date_from) . ' ถึง ' . thai_date($date_to);
        }
        break;
        
    case 'draws':
        // เลือกงวด
        if (!empty($selected_draws)) {
            $draw_ids = array_map('intval', $selected_draws);
            $report_title = 'งวดที่เลือก (' . count($draw_ids) . ' งวด)';
        }
        break;
        
    case 'all':
        // ทั้งหมด
        $draws = $db->fetchAll("SELECT id FROM draws");
        $draw_ids = array_column($draws, 'id');
        $report_title = 'รายงานทั้งหมด';
        break;
}

// ถ้าไม่มีงวดที่จะแสดง
if (empty($draw_ids)) {
    echo '<div class="alert alert-warning">กรุณาเลือกงวดหรือช่วงเวลาที่ต้องการดูรายงาน</div>';
    $draw_ids = [0]; // ป้องกัน SQL error
}

// สร้าง placeholder สำหรับ IN clause
$placeholders = str_repeat('?,', count($draw_ids) - 1) . '?';

// Get overall stats
$overall = $db->fetch("
    SELECT 
        COUNT(DISTINCT t.draw_id) as total_draws,
        COUNT(DISTINCT t.id) as total_tickets,
        COUNT(DISTINCT t.user_id) as total_sellers,
        COALESCE(SUM(tl.amount), 0) as total_sales,
        COUNT(DISTINCT CONCAT(tl.type, '-', tl.number)) as unique_numbers
    FROM tickets t
    LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
    WHERE t.draw_id IN ($placeholders)
", $draw_ids);

// Sales by type
$sales_by_type = $db->fetchAll("
    SELECT 
        tl.type,
        COUNT(DISTINCT CONCAT(t.draw_id, '-', tl.number)) as unique_numbers,
        COUNT(tl.id) as total_lines,
        SUM(tl.amount) as total_amount
    FROM ticket_lines tl
    JOIN tickets t ON t.id = tl.ticket_id
    WHERE t.draw_id IN ($placeholders)
    GROUP BY tl.type
    ORDER BY total_amount DESC
", $draw_ids);

// Sales by seller
$sales_by_seller = $db->fetchAll("
    SELECT 
        u.id,
        u.name,
        u.commission_pct,
        COUNT(DISTINCT t.id) as ticket_count,
        COALESCE(SUM(tl.amount), 0) as total_sales
    FROM users u
    LEFT JOIN tickets t ON t.user_id = u.id AND t.draw_id IN ($placeholders)
    LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
    GROUP BY u.id, u.name, u.commission_pct
    HAVING total_sales > 0
    ORDER BY total_sales DESC
", $draw_ids);

// Sales by draw (ถ้ามีหลายงวด)
$sales_by_draw = [];
if (count($draw_ids) > 1) {
    $sales_by_draw = $db->fetchAll("
        SELECT 
            d.id,
            d.name,
            d.draw_date,
            COUNT(DISTINCT t.id) as ticket_count,
            COALESCE(SUM(tl.amount), 0) as total_sales,
            (SELECT draw_id FROM results WHERE draw_id = d.id) as has_result
        FROM draws d
        LEFT JOIN tickets t ON t.draw_id = d.id
        LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
        WHERE d.id IN ($placeholders)
        GROUP BY d.id, d.name, d.draw_date
        ORDER BY d.draw_date DESC
    ", $draw_ids);
    
    // คำนวณข้อมูลเพิ่มเติมสำหรับแต่ละงวด
    foreach ($sales_by_draw as &$draw) {
        $draw['total_payout'] = 0;
        $draw['total_commission'] = 0;
        $draw['net_profit'] = 0;
        
        // คำนวณค่าคอมมิชชั่น
        $commission_data = $db->fetch("
            SELECT COALESCE(SUM((tl.amount * u.commission_pct / 100)), 0) as total_commission
            FROM ticket_lines tl
            JOIN tickets t ON t.id = tl.ticket_id
            JOIN users u ON u.id = t.user_id
            WHERE t.draw_id = ?
        ", [$draw['id']]);
        
        $draw['total_commission'] = $commission_data['total_commission'] ?? 0;
        
        // ถ้ามีผลรางวัล คำนวณยอดจ่าย
        if ($draw['has_result']) {
            $result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$draw['id']]);
            
            if ($result) {
                $win_3top = substr($result['top6'], 0, 3);
                $win_2top = substr($result['top6'], 4, 2);
                $win_2bottom = $result['bottom2'];
                
                $payouts = $db->fetch("
                    SELECT 
                        COALESCE(SUM(CASE 
                            WHEN tl.type = 'number3_top' AND tl.number = ? 
                            THEN tl.amount * tl.rate 
                            ELSE 0 
                        END), 0) as payout_3top,
                        COALESCE(SUM(CASE 
                            WHEN tl.type = 'number2_top' AND tl.number = ? 
                            THEN tl.amount * tl.rate 
                            ELSE 0 
                        END), 0) as payout_2top,
                        COALESCE(SUM(CASE 
                            WHEN tl.type = 'number2_bottom' AND tl.number = ? 
                            THEN tl.amount * tl.rate 
                            ELSE 0 
                        END), 0) as payout_2bottom
                    FROM ticket_lines tl
                    JOIN tickets t ON t.id = tl.ticket_id
                    WHERE t.draw_id = ?
                ", [$win_3top, $win_2top, $win_2bottom, $draw['id']]);
                
                $draw['total_payout'] = ($payouts['payout_3top'] ?? 0) + 
                                       ($payouts['payout_2top'] ?? 0) + 
                                       ($payouts['payout_2bottom'] ?? 0);
            }
        }
        
        // คำนวณกำไรสุทธิ
        $draw['net_profit'] = $draw['total_sales'] - $draw['total_payout'] - $draw['total_commission'];
    }
}

// Calculate profit/loss for all selected draws
$profit_data = null;
$total_payout = 0;
$total_commission = 0;

// คำนวณค่าคอมมิชชั่นรวม
foreach ($sales_by_seller as $seller) {
    $total_commission += $seller['total_sales'] * ($seller['commission_pct'] / 100);
}

// คำนวณยอดจ่ายรางวัลรวม (เฉพาะงวดที่ออกผลแล้ว)
$results = $db->fetchAll("
    SELECT r.*, d.id as draw_id 
    FROM results r 
    JOIN draws d ON d.id = r.draw_id 
    WHERE r.draw_id IN ($placeholders)
", $draw_ids);

foreach ($results as $result) {
    $win_3top = substr($result['top6'], 0, 3);
    $win_2top = substr($result['top6'], 4, 2);
    $win_2bottom = $result['bottom2'];
    
    $payouts = $db->fetch("
        SELECT 
            COALESCE(SUM(CASE 
                WHEN tl.type = 'number3_top' AND tl.number = ? 
                THEN tl.amount * tl.rate 
                ELSE 0 
            END), 0) as payout_3top,
            COALESCE(SUM(CASE 
                WHEN tl.type = 'number2_top' AND tl.number = ? 
                THEN tl.amount * tl.rate 
                ELSE 0 
            END), 0) as payout_2top,
            COALESCE(SUM(CASE 
                WHEN tl.type = 'number2_bottom' AND tl.number = ? 
                THEN tl.amount * tl.rate 
                ELSE 0 
            END), 0) as payout_2bottom
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ?
    ", [$win_3top, $win_2top, $win_2bottom, $result['draw_id']]);
    
    $total_payout += ($payouts['payout_3top'] ?? 0) + 
                     ($payouts['payout_2top'] ?? 0) + 
                     ($payouts['payout_2bottom'] ?? 0);
}

if ($overall['total_sales'] > 0) {
    $profit_data = [
        'income' => $overall['total_sales'],
        'payout' => $total_payout,
        'commission' => $total_commission,
        'net_profit' => $overall['total_sales'] - $total_payout - $total_commission
    ];
}

// Export CSV
if (isset($_GET['export']) && can($current_user, 'reports.export')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8
    
    // Headers
    fputcsv($output, ['รายงาน: ' . strip_tags($report_title)]);
    fputcsv($output, ['วันที่พิมพ์: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    // Overall stats
    fputcsv($output, ['สรุปภาพรวม']);
    fputcsv($output, ['รายการ', 'จำนวน']);
    fputcsv($output, ['จำนวนงวด', $overall['total_draws']]);
    fputcsv($output, ['ยอดขายรวม', $overall['total_sales']]);
    fputcsv($output, ['จำนวนโพย', $overall['total_tickets']]);
    fputcsv($output, ['จำนวนผู้ขาย', $overall['total_sellers']]);
    fputcsv($output, []);
    
    // By type
    fputcsv($output, ['แยกตามประเภท']);
    fputcsv($output, ['ประเภท', 'จำนวนเลข', 'จำนวนรายการ', 'ยอดรวม']);
    foreach ($sales_by_type as $row) {
        fputcsv($output, [
            LOTTERY_TYPES[$row['type']]['name'] ?? $row['type'],
            $row['unique_numbers'],
            $row['total_lines'],
            $row['total_amount']
        ]);
    }
    fputcsv($output, []);
    
    // By seller
    fputcsv($output, ['แยกตามผู้ขาย']);
    fputcsv($output, ['ผู้ขาย', 'จำนวนโพย', 'ยอดขาย', 'ค่าคอม%', 'ค่าคอม']);
    foreach ($sales_by_seller as $seller) {
        fputcsv($output, [
            $seller['name'],
            $seller['ticket_count'],
            $seller['total_sales'],
            $seller['commission_pct'],
            $seller['total_sales'] * ($seller['commission_pct'] / 100)
        ]);
    }
    
    // By draw if multiple
    if (!empty($sales_by_draw)) {
        fputcsv($output, []);
        fputcsv($output, ['แยกตามงวด']);
        fputcsv($output, ['งวด', 'วันที่', 'จำนวนโพย', 'ยอดขาย', 'ยอดถูกรางวัล', 'ค่าคอม', 'กำไร']);
        foreach ($sales_by_draw as $draw) {
            fputcsv($output, [
                $draw['name'],
                $draw['draw_date'],
                $draw['ticket_count'],
                $draw['total_sales'],
                $draw['total_payout'],
                $draw['total_commission'],
                $draw['net_profit']
            ]);
        }
        // แถวสรุมรวม
        fputcsv($output, [
            'รวมทั้งหมด',
            '',
            array_sum(array_column($sales_by_draw, 'ticket_count')),
            array_sum(array_column($sales_by_draw, 'total_sales')),
            array_sum(array_column($sales_by_draw, 'total_payout')),
            array_sum(array_column($sales_by_draw, 'total_commission')),
            array_sum(array_column($sales_by_draw, 'net_profit'))
        ]);
    }
    
    // Profit if available
    if ($profit_data) {
        fputcsv($output, []);
        fputcsv($output, ['สรุปกำไร/ขาดทุน']);
        fputcsv($output, ['รายการ', 'จำนวนเงิน']);
        fputcsv($output, ['รายรับ', $profit_data['income']]);
        fputcsv($output, ['จ่ายรางวัล', $profit_data['payout']]);
        fputcsv($output, ['ค่าคอมมิชชั่น', $profit_data['commission']]);
        fputcsv($output, ['กำไรสุทธิ', $profit_data['net_profit']]);
    }
    
    fclose($output);
    exit;
}

// ดึงรายการงวดทั้งหมดสำหรับ dropdown
$all_draws = $db->fetchAll("SELECT id, name, draw_date FROM draws ORDER BY id DESC LIMIT 100");
?>

<h1 class="mb-3">📊 รายงาน</h1>

<!-- ฟอร์มเลือกการแสดงผล -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">🔍 เลือกข้อมูลที่ต้องการดู</h3>
    </div>
    <div class="card-body">
        <div class="d-flex gap-2 mb-3" style="flex-wrap: wrap;">
            <button class="btn <?= $filter_type === 'current' ? 'btn-primary' : 'btn-outline' ?>"
                    onclick="setFilterType('current')">
                งวดปัจจุบัน
            </button>
            <button class="btn <?= $filter_type === 'date_range' ? 'btn-primary' : 'btn-outline' ?>"
                    onclick="setFilterType('date_range')">
                เลือกช่วงวันที่
            </button>
            <button class="btn <?= $filter_type === 'draws' ? 'btn-primary' : 'btn-outline' ?>"
                    onclick="setFilterType('draws')">
                เลือกงวด
            </button>
            <button class="btn <?= $filter_type === 'all' ? 'btn-primary' : 'btn-outline' ?>"
                    onclick="setFilterType('all')">
                ทั้งหมด
            </button>
        </div>
        
        <form method="GET" id="filterForm">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="filter_type" id="filter_type" value="<?= escape($filter_type) ?>">
            
            <!-- ฟอร์มเลือกช่วงวันที่ -->
            <div id="date_range_form" style="display: <?= $filter_type === 'date_range' ? 'block' : 'none' ?>;">
                <div class="d-flex gap-2" style="flex-wrap: wrap;">
                    <div>
                        <label class="form-label">วันที่เริ่มต้น</label>
                        <input type="date" name="date_from" class="form-control" 
                               value="<?= escape($date_from) ?>">
                    </div>
                    <div>
                        <label class="form-label">วันที่สิ้นสุด</label>
                        <input type="date" name="date_to" class="form-control" 
                               value="<?= escape($date_to) ?>">
                    </div>
                    <div style="align-self: flex-end;">
                        <button type="submit" class="btn btn-primary">ดูรายงาน</button>
                    </div>
                </div>
            </div>
            
            <!-- ฟอร์มเลือกงวด -->
            <div id="draws_form" style="display: <?= $filter_type === 'draws' ? 'block' : 'none' ?>;">
                <div class="mb-2">
                    <label class="form-label">เลือกงวด (เลือกได้หลายงวด)</label>
                    <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.25rem; padding: 0.5rem;">
                        <?php foreach ($all_draws as $draw): ?>
                        <div class="form-check">
                            <input type="checkbox" name="draws[]" value="<?= $draw['id'] ?>" 
                                   id="draw_<?= $draw['id'] ?>" class="form-check-input"
                                   <?= in_array($draw['id'], $selected_draws) ? 'checked' : '' ?>>
                            <label for="draw_<?= $draw['id'] ?>" class="form-check-label">
                                <?= escape($draw['name']) ?> - <?= thai_date($draw['draw_date']) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">ดูรายงาน</button>
            </div>
            
            <!-- ปุ่มดูทั้งหมด -->
            <div id="all_form" style="display: <?= $filter_type === 'all' ? 'block' : 'none' ?>;">
                <button type="submit" class="btn btn-primary">ดูรายงานทั้งหมด</button>
            </div>
        </form>
    </div>
</div>

<!-- แสดงชื่อรายงาน -->
<?php if (!empty($report_title)): ?>
<div class="alert alert-info">
    <strong>📋 <?= $report_title ?></strong>
    <?php if ($overall['total_draws'] > 1): ?>
        <span class="badge badge-warning">รวม <?= $overall['total_draws'] ?> งวด</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-value">฿ <?= format_number($overall['total_sales']) ?></div>
        <div class="stat-label">ยอดขายรวม</div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);">
        <div class="stat-value"><?= format_number($overall['total_tickets'], 0) ?></div>
        <div class="stat-label">จำนวนโพย</div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
        <div class="stat-value"><?= format_number($overall['unique_numbers'], 0) ?></div>
        <div class="stat-label">เลขที่ขาย</div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
        <div class="stat-value"><?= format_number($overall['total_sellers'], 0) ?></div>
        <div class="stat-label">ผู้ขาย</div>
    </div>
</div>

<?php if ($profit_data): ?>
<!-- Profit/Loss Summary -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">💰 สรุปกำไร/ขาดทุน</h3>
    </div>
    
    <div class="grid grid-4 p-3">
        <div class="text-center">
            <small class="text-muted">รายรับ</small>
            <div class="text-success" style="font-size: 1.5rem; font-weight: bold;">
                +฿ <?= format_number($profit_data['income']) ?>
            </div>
        </div>
        
        <div class="text-center">
            <small class="text-muted">จ่ายรางวัล</small>
            <div class="text-danger" style="font-size: 1.5rem; font-weight: bold;">
                -฿ <?= format_number($profit_data['payout']) ?>
            </div>
        </div>
        
        <div class="text-center">
            <small class="text-muted">ค่าคอมมิชชั่น</small>
            <div class="text-warning" style="font-size: 1.5rem; font-weight: bold;">
                -฿ <?= format_number($profit_data['commission']) ?>
            </div>
        </div>
        
        <div class="text-center">
            <small class="text-muted">กำไรสุทธิ</small>
            <div class="<?= $profit_data['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>" 
                 style="font-size: 1.8rem; font-weight: bold;">
                ฿ <?= format_number($profit_data['net_profit']) ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-2">
    <!-- Sales by Type -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">📈 ยอดขายแยกตามประเภท</h3>
        </div>
        
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ประเภท</th>
                    <th class="text-center">เลข</th>
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
                    <td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td>
                </tr>
                <?php else: ?>
                <tr class="bg-light">
                    <td colspan="3"><strong>รวม</strong></td>
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
    
    <!-- Sales by Seller -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">👥 ยอดขายแยกตามผู้ขาย</h3>
        </div>
        
        <div style="max-height: 400px; overflow-y: auto;">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ผู้ขาย</th>
                        <th class="text-center">โพย</th>
                        <th class="text-right">ยอดขาย</th>
                        <th class="text-right">คอม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales_by_seller as $seller): ?>
                    <?php $commission = $seller['total_sales'] * ($seller['commission_pct'] / 100); ?>
                    <tr>
                        <td>
                            <?= escape($seller['name']) ?>
                            <br>
                            <small class="text-muted"><?= $seller['commission_pct'] ?>%</small>
                        </td>
                        <td class="text-center"><?= $seller['ticket_count'] ?></td>
                        <td class="text-right">
                            <strong>฿ <?= format_number($seller['total_sales']) ?></strong>
                        </td>
                        <td class="text-right">
                            <small class="text-success">฿ <?= format_number($commission) ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($sales_by_seller)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">ไม่มีข้อมูล</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Sales by Draw (ถ้ามีหลายงวด) -->
<?php if (!empty($sales_by_draw)): ?>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">📅 ยอดขายแยกตามงวด</h3>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>งวด</th>
                    <th>วันที่</th>
                    <th class="text-center">จำนวนโพย</th>
                    <th class="text-right">ยอดขาย</th>
                    <th class="text-right">ยอดถูกรางวัล</th>
                    <th class="text-right">ค่าคอม</th>
                    <th class="text-right">กำไร</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sum_sales = 0;
                $sum_payout = 0;
                $sum_commission = 0;
                $sum_profit = 0;
                
                foreach ($sales_by_draw as $draw): 
                    $sum_sales += $draw['total_sales'];
                    $sum_payout += $draw['total_payout'];
                    $sum_commission += $draw['total_commission'];
                    $sum_profit += $draw['net_profit'];
                ?>
                <tr>
                    <td>
                        <?= escape($draw['name']) ?>
                        <?php if ($draw['has_result']): ?>
                            <span class="badge badge-warning" style="font-size: 0.7rem;">ออกผล</span>
                        <?php endif; ?>
                    </td>
                    <td><?= thai_date($draw['draw_date']) ?></td>
                    <td class="text-center"><?= format_number($draw['ticket_count'], 0) ?></td>
                    <td class="text-right">
                        <strong class="text-primary">฿ <?= format_number($draw['total_sales']) ?></strong>
                    </td>
                    <td class="text-right">
                        <?php if ($draw['has_result']): ?>
                            <span class="text-danger">฿ <?= format_number($draw['total_payout']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ($draw['total_commission'] > 0): ?>
                            <span class="text-warning">฿ <?= format_number($draw['total_commission']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right">
                        <?php if ($draw['has_result']): ?>
                            <strong class="<?= $draw['net_profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                ฿ <?= format_number($draw['net_profit']) ?>
                            </strong>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <!-- แถวสรุปรวม -->
                <tr class="bg-light">
                    <td colspan="3"><strong>รวมทั้งหมด</strong></td>
                    <td class="text-right">
                        <strong class="text-primary">฿ <?= format_number($sum_sales) ?></strong>
                    </td>
                    <td class="text-right">
                        <strong class="text-danger">฿ <?= format_number($sum_payout) ?></strong>
                    </td>
                    <td class="text-right">
                        <strong class="text-warning">฿ <?= format_number($sum_commission) ?></strong>
                    </td>
                    <td class="text-right">
                        <strong class="<?= $sum_profit >= 0 ? 'text-success' : 'text-danger' ?>">
                            ฿ <?= format_number($sum_profit) ?>
                        </strong>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Export Button -->
<div class="mt-3">
    <?php if (can($current_user, 'reports.export')): ?>
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 1])) ?>" class="btn btn-success">
        📥 Export CSV
    </a>
    <?php endif; ?>
    
    <button class="btn btn-outline" onclick="window.print()">
        🖨️ พิมพ์รายงาน
    </button>
</div>

<script>
function setFilterType(type) {
    // ซ่อนฟอร์มทั้งหมด
    document.getElementById('date_range_form').style.display = 'none';
    document.getElementById('draws_form').style.display = 'none';
    document.getElementById('all_form').style.display = 'none';
    
    // แสดงฟอร์มที่เลือก
    if (type === 'date_range') {
        document.getElementById('date_range_form').style.display = 'block';
    } else if (type === 'draws') {
        document.getElementById('draws_form').style.display = 'block';
    } else if (type === 'all') {
        document.getElementById('all_form').style.display = 'block';
    } else if (type === 'current') {
        // Submit ทันทีสำหรับงวดปัจจุบัน
        document.getElementById('filter_type').value = 'current';
        document.getElementById('filterForm').submit();
    }
    
    // อัปเดต hidden field
    document.getElementById('filter_type').value = type;
}

// เลือก/ยกเลิกทั้งหมด สำหรับ checkbox
function toggleAllDraws() {
    const checkboxes = document.querySelectorAll('input[name="draws[]"]');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });
}

// เพิ่มปุ่มเลือกทั้งหมด
document.addEventListener('DOMContentLoaded', function() {
    const drawsForm = document.getElementById('draws_form');
    if (drawsForm) {
        const selectAllBtn = document.createElement('button');
        selectAllBtn.type = 'button';
        selectAllBtn.className = 'btn btn-sm btn-secondary mb-2';
        selectAllBtn.textContent = 'เลือก/ยกเลิกทั้งหมด';
        selectAllBtn.onclick = toggleAllDraws;
        
        const labelElement = drawsForm.querySelector('.form-label');
        if (labelElement) {
            labelElement.parentNode.insertBefore(selectAllBtn, labelElement.nextSibling);
        }
    }
});
</script>

<style>
@media print {
    .card-header, .btn, form, .alert-info {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>