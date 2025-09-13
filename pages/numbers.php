<?php
// pages/numbers.php - ยอดตามเลข

$current_user = current_user();
if (!can($current_user, 'numbers.view')) {
    set_alert('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: ?page=dashboard');
    exit;
}

$db = DB::getInstance();
$current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();
$current_draw = get_draw($current_draw_id);

if (!$current_draw) {
    echo '<div class="alert alert-warning">ไม่พบข้อมูลงวด</div>';
    exit;
}

// Get result if exists
$result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$current_draw_id]);
$winning_numbers = [];

if ($result) {
    // Extract winning numbers
    if ($result['top6']) {
        $winning_numbers['number3_top'] = substr($result['top6'], 3, 3); // 3 ตัวบน
        $winning_numbers['number2_top'] = substr($result['top6'], 4, 2); // 2 ตัวบน (หลัก 5-6)
    }
    if ($result['bottom2']) {
        $winning_numbers['number2_bottom'] = $result['bottom2']; // 2 ตัวล่าง
    }
}

// Filter by type
$selected_type = $_GET['type'] ?? 'all';

// Get numbers summary
$sql = "
    SELECT 
        tl.type,
        tl.number,
        COUNT(DISTINCT tl.ticket_id) as ticket_count,
        COUNT(tl.id) as line_count,
        SUM(tl.amount) as total_amount,
        AVG(tl.rate) as avg_rate
    FROM ticket_lines tl
    JOIN tickets t ON t.id = tl.ticket_id
    WHERE t.draw_id = ?
";

$params = [$current_draw_id];

if ($selected_type !== 'all' && isset(LOTTERY_TYPES[$selected_type])) {
    $sql .= " AND tl.type = ?";
    $params[] = $selected_type;
}

$sql .= " GROUP BY tl.type, tl.number
         ORDER BY tl.type, total_amount DESC";

$numbers = $db->fetchAll($sql, $params);

// Group by type for display
$grouped_numbers = [];
foreach ($numbers as $num) {
    $grouped_numbers[$num['type']][] = $num;
}

// Calculate totals by type
$type_totals = [];
foreach (LOTTERY_TYPES as $type => $config) {
    $total = $db->fetch("
        SELECT 
            COUNT(DISTINCT tl.number) as unique_numbers,
            SUM(tl.amount) as total_amount
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? AND tl.type = ?
    ", [$current_draw_id, $type]);
    
    $type_totals[$type] = $total;
}
?>

<h1 class="mb-3">
    🔢 ยอดตามเลข - <?= escape($current_draw['name']) ?>
    <?php if ($result): ?>
        <span class="badge badge-warning">ออกผลแล้ว</span>
    <?php endif; ?>
</h1>

<!-- Filter tabs -->
<div class="card mb-3">
    <div class="d-flex gap-2 p-3">
        <a href="?page=numbers&type=all" 
           class="btn <?= $selected_type === 'all' ? 'btn-primary' : 'btn-outline' ?>">
            ทั้งหมด
        </a>
        <?php foreach (LOTTERY_TYPES as $type => $config): ?>
            <a href="?page=numbers&type=<?= $type ?>" 
               class="btn <?= $selected_type === $type ? 'btn-primary' : 'btn-outline' ?>">
                <?= escape($config['name']) ?>
                <?php if (isset($type_totals[$type])): ?>
                    <span class="badge badge-info">
                        <?= $type_totals[$type]['unique_numbers'] ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Summary stats -->
<div class="grid grid-3 mb-3">
    <?php foreach (LOTTERY_TYPES as $type => $config): ?>
        <?php if ($selected_type === 'all' || $selected_type === $type): ?>
        <div class="stat-card" style="background: linear-gradient(135deg, #<?= substr(md5($type), 0, 6) ?> 0%, #<?= substr(md5($type.'2'), 0, 6) ?> 100%);">
            <div class="stat-label"><?= escape($config['name']) ?></div>
            <div class="stat-value">
                ฿ <?= format_number($type_totals[$type]['total_amount'] ?? 0) ?>
            </div>
            <div class="stat-label">
                <?= $type_totals[$type]['unique_numbers'] ?? 0 ?> เลข
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<!-- Numbers grid -->
<?php foreach (LOTTERY_TYPES as $type => $config): ?>
    <?php if (($selected_type === 'all' || $selected_type === $type) && isset($grouped_numbers[$type])): ?>
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">
                <?= escape($config['name']) ?>
                <small class="text-muted">(เรทมาตรฐาน: <?= rate_for($type, $current_draw_id, '') ?>)</small>
            </h3>
        </div>
        
        <div class="number-grid p-3">
            <?php foreach ($grouped_numbers[$type] as $num): ?>
                <?php
                // Check if winning number
                $is_winner = isset($winning_numbers[$type]) && $winning_numbers[$type] === $num['number'];
                
                // Get limit info
                $max = max_for($type, $current_draw_id, $num['number']);
                $remaining = $max - $num['total_amount'];
                $percent_used = $max < PHP_FLOAT_MAX ? ($num['total_amount'] / $max * 100) : 0;
                
                // Determine card style
                $card_class = 'number-card';
                if ($is_winner) {
                    $card_class .= ' winner';
                } elseif ($percent_used >= 100) {
                    $card_class .= ' selected'; // Full
                }
                ?>
                
                <div class="<?= $card_class ?>" 
                     title="<?= $num['line_count'] ?> รายการจาก <?= $num['ticket_count'] ?> โพย">
                    <?php if ($is_winner): ?>
                        <div style="position: absolute; top: -10px; right: -10px; font-size: 1.5em;">
                            🏆
                        </div>
                    <?php endif; ?>
                    
                    <div class="number-display">
                        <?= escape($num['number']) ?>
                    </div>
                    
                    <div class="number-amount">
                        ฿ <?= format_number($num['total_amount']) ?>
                    </div>
                    
                    <?php if ($num['avg_rate'] != rate_for($type, $current_draw_id, $num['number'])): ?>
                        <div style="font-size: 0.75rem; color: #f59e0b;">
                            เรท x<?= format_number($num['avg_rate'], 0) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($max < PHP_FLOAT_MAX): ?>
                        <div style="margin-top: 0.5rem;">
                            <div style="background: #e5e7eb; height: 4px; border-radius: 2px; overflow: hidden;">
                                <div style="background: <?= $percent_used >= 100 ? '#ef4444' : ($percent_used >= 80 ? '#f59e0b' : '#10b981') ?>; 
                                           width: <?= min(100, $percent_used) ?>%; height: 100%;"></div>
                            </div>
                            <div style="font-size: 0.7rem; margin-top: 0.25rem; color: <?= $remaining <= 0 ? '#ef4444' : '#6b7280' ?>;">
                                <?php if ($remaining <= 0): ?>
                                    เต็ม
                                <?php else: ?>
                                    เหลือ ฿<?= format_number($remaining, 0) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($result): ?>
                        <?php
                        // Calculate payout
                        $payout = $num['total_amount'] * $num['avg_rate'];
                        ?>
                        <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
                            <?php if ($is_winner): ?>
                                <strong style="color: #22c55e;">จ่าย ฿<?= format_number($payout) ?></strong>
                            <?php else: ?>
                                คาดจ่าย ฿<?= format_number($payout, 0) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Summary for this type -->
        <div style="border-top: 1px solid var(--border); padding: 1rem;">
            <div class="d-flex justify-between">
                <div>
                    <strong>รวม <?= count($grouped_numbers[$type]) ?> เลข</strong>
                </div>
                <div>
                    <strong class="text-primary">
                        ฿ <?= format_number(array_sum(array_column($grouped_numbers[$type], 'total_amount'))) ?>
                    </strong>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if (empty($numbers)): ?>
<div class="card">
    <div class="card-body text-center text-muted p-5">
        <h3>ยังไม่มีข้อมูล</h3>
        <p>ยังไม่มีการขายในงวดนี้</p>
    </div>
</div>
<?php endif; ?>

<!-- Export button -->
<?php if (!empty($numbers)): ?>
<div class="mt-3">
    <button class="btn btn-outline" onclick="exportToCSV()">
        📥 Export CSV
    </button>
    
    <button class="btn btn-outline" onclick="window.print()">
        🖨️ พิมพ์รายงาน
    </button>
</div>
<?php endif; ?>

<script>
function exportToCSV() {
    const data = <?= json_encode($numbers) ?>;
    
    // Create CSV content
    let csv = 'ประเภท,เลข,จำนวนโพย,จำนวนรายการ,ยอดรวม,เรทเฉลี่ย\n';
    
    data.forEach(row => {
        const type_name = {
            'number2_top': '2 ตัวบน',
            'number2_bottom': '2 ตัวล่าง',
            'number3_top': '3 ตัวบน'
        }[row.type] || row.type;
        
        csv += `${type_name},${row.number},${row.ticket_count},${row.line_count},${row.total_amount},${row.avg_rate}\n`;
    });
    
    // Download CSV
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'numbers_<?= date('Y-m-d') ?>.csv';
    link.click();
}

// Print styles
const printStyles = `
    @media print {
        .navbar, .nav-link, button, .btn { display: none !important; }
        .card { break-inside: avoid; }
        .number-grid { grid-template-columns: repeat(6, 1fr) !important; }
        .number-card { page-break-inside: avoid; }
    }
`;

// Add print styles
const style = document.createElement('style');
style.textContent = printStyles;
document.head.appendChild(style);
</script>