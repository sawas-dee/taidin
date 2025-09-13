<?php
// ============ pages/credits.php - เครดิต ============

$current_user = current_user();
if (!can($current_user, 'credits.view')) {
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

// Process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can($current_user, 'credits.add')) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $type = $_POST['type'] ?? 'payment';
    $note = trim($_POST['note'] ?? '');
    
    if ($user_id && $amount != 0) {
        $db->insert('payments', [
            'draw_id' => $current_draw_id,
            'user_id' => $user_id,
            'amount' => $amount,
            'type' => $type,
            'note' => $note,
            'created_by' => $current_user['id']
        ]);
        
        set_alert('success', 'บันทึกรายการสำเร็จ');
        header('Location: ?page=credits');
        exit;
    }
}

// Get result for payout calculation
$result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$current_draw_id]);

// Get credits summary
$sql = "
    SELECT 
        u.id,
        u.name,
        u.commission_pct,
        COALESCE(sales.total_sales, 0) as total_sales,
        COALESCE(sales.ticket_count, 0) as ticket_count,
        COALESCE(wins.total_win, 0) as total_win,
        COALESCE(payments.total_paid, 0) as total_paid
    FROM users u
    LEFT JOIN (
        SELECT 
            t.user_id,
            COUNT(DISTINCT t.id) as ticket_count,
            SUM(tl.amount) as total_sales
        FROM tickets t
        JOIN ticket_lines tl ON tl.ticket_id = t.id
        WHERE t.draw_id = ?
        GROUP BY t.user_id
    ) sales ON sales.user_id = u.id
LEFT JOIN (
    SELECT 
        t.user_id,
        SUM(tl.amount * tl.rate) as total_win
    FROM tickets t
    JOIN ticket_lines tl ON tl.ticket_id = t.id
    WHERE t.draw_id = ? ";

// Get credits summary - แก้ไข SQL ให้ถูกต้อง
if ($result && $result['top6'] && $result['bottom2']) {
    // มีผลรางวัลแล้ว
    $win_3top = substr($result['top6'], 3, 3);
    $win_2top = substr($result['top6'], 4, 2);
    $win_2bottom = $result['bottom2'];
    
    $sql = "
        SELECT 
            u.id,
            u.name,
            u.commission_pct,
            COALESCE(sales.total_sales, 0) as total_sales,
            COALESCE(sales.ticket_count, 0) as ticket_count,
            COALESCE(wins.total_win, 0) as total_win,
            COALESCE(payments.total_paid, 0) as total_paid
        FROM users u
        LEFT JOIN (
            SELECT 
                t.user_id,
                COUNT(DISTINCT t.id) as ticket_count,
                SUM(tl.amount) as total_sales
            FROM tickets t
            JOIN ticket_lines tl ON tl.ticket_id = t.id
            WHERE t.draw_id = ?
            GROUP BY t.user_id
        ) sales ON sales.user_id = u.id
        LEFT JOIN (
            SELECT 
                t.user_id,
                SUM(tl.amount * tl.rate) as total_win
            FROM tickets t
            JOIN ticket_lines tl ON tl.ticket_id = t.id
            WHERE t.draw_id = ?
            AND (
                (tl.type = 'number3_top' AND tl.number = ?) OR
                (tl.type = 'number2_top' AND tl.number = ?) OR
                (tl.type = 'number2_bottom' AND tl.number = ?)
            )
            GROUP BY t.user_id
        ) wins ON wins.user_id = u.id
        LEFT JOIN (
            SELECT 
                user_id,
                SUM(amount) as total_paid
            FROM payments
            WHERE draw_id = ?
            GROUP BY user_id
        ) payments ON payments.user_id = u.id
        WHERE (sales.total_sales > 0 OR payments.total_paid > 0)
    ";
    
    // Parameters สำหรับ query
    $params = [$current_draw_id, $current_draw_id, $win_3top, $win_2top, $win_2bottom, $current_draw_id];
    
} else {
    // ยังไม่มีผลรางวัล
    $sql = "
        SELECT 
            u.id,
            u.name,
            u.commission_pct,
            COALESCE(sales.total_sales, 0) as total_sales,
            COALESCE(sales.ticket_count, 0) as ticket_count,
            0 as total_win,
            COALESCE(payments.total_paid, 0) as total_paid
        FROM users u
        LEFT JOIN (
            SELECT 
                t.user_id,
                COUNT(DISTINCT t.id) as ticket_count,
                SUM(tl.amount) as total_sales
            FROM tickets t
            JOIN ticket_lines tl ON tl.ticket_id = t.id
            WHERE t.draw_id = ?
            GROUP BY t.user_id
        ) sales ON sales.user_id = u.id
        LEFT JOIN (
            SELECT 
                user_id,
                SUM(amount) as total_paid
            FROM payments
            WHERE draw_id = ?
            GROUP BY user_id
        ) payments ON payments.user_id = u.id
        WHERE (sales.total_sales > 0 OR payments.total_paid > 0)
    ";
    
    // Parameters สำหรับ query
    $params = [$current_draw_id, $current_draw_id];
}

// Filter by user if not admin
if (!is_owner() && !can($current_user, 'credits.view_all')) {
    $sql .= " AND u.id = " . $current_user['id'];
}

$sql .= " ORDER BY u.name";

// Execute query
$credits = $db->fetchAll($sql, $params);

// Get payment history
$selected_user_id = $_GET['user_id'] ?? null;
$payment_history = [];

if ($selected_user_id) {
    $payment_history = $db->fetchAll("
        SELECT p.*, u.name as created_by_name
        FROM payments p
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.draw_id = ? AND p.user_id = ?
        ORDER BY p.created_at DESC
    ", [$current_draw_id, $selected_user_id]);
}
?>

<h1 class="mb-3">💰 เครดิต - <?= escape($current_draw['name']) ?></h1>

<!-- แสดงสถานะผลรางวัล -->
<div class="grid grid-2 mb-3">
    <div class="card">
        <div class="card-header">
            <h4>📊 สถานะงวด</h4>
        </div>
        <div>
            <strong>งวด:</strong> <?= escape($current_draw['name']) ?><br>
            <strong>สถานะ:</strong> 
            <?php if ($current_draw['status'] == 'open'): ?>
                <span class="badge badge-success">เปิดรับ</span>
            <?php else: ?>
                <span class="badge badge-danger">ปิดแล้ว</span>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h4>🎯 ผลรางวัล</h4>
        </div>
        <div>
            <?php if ($result && $result['top6'] && $result['bottom2']): ?>
                <strong>3 ตัวบน:</strong> <span class="badge badge-warning"><?= substr($result['top6'], 3, 3) ?></span><br>
                <strong>2 ตัวบน:</strong> <span class="badge badge-warning"><?= substr($result['top6'], 4, 2) ?></span><br>
                <strong>2 ตัวล่าง:</strong> <span class="badge badge-warning"><?= $result['bottom2'] ?></span>
            <?php else: ?>
                <span class="text-muted">ยังไม่ออกผล</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Summary Table -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📊 สรุปเครดิต</h3>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ผู้ขาย</th>
                    <th class="text-center">โพย</th>
                    <th class="text-right">ยอดขาย</th>
                    <th class="text-right">ค่าคอม</th>
                    <th class="text-right">ถูกรางวัล</th>
                    <th class="text-right">ชำระแล้ว</th>
                    <th class="text-right">คงเหลือ</th>
                    <th width="150">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($credits as $credit): ?>
                <?php 
                $commission = $credit['total_sales'] * ($credit['commission_pct'] / 100);
                $balance = $credit['total_sales'] - $commission - $credit['total_win'] - $credit['total_paid'];
                ?>
                <tr>
                    <td>
                        <strong><?= escape($credit['name']) ?></strong>
                        <br>
                        <small class="text-muted">คอม <?= $credit['commission_pct'] ?>%</small>
                    </td>
                    <td class="text-center"><?= $credit['ticket_count'] ?></td>
                    <td class="text-right">฿ <?= format_number($credit['total_sales']) ?></td>
                    <td class="text-right text-success">฿ <?= format_number($commission) ?></td>
                    <td class="text-right text-danger">฿ <?= format_number($credit['total_win']) ?></td>
                    <td class="text-right text-info">฿ <?= format_number($credit['total_paid']) ?></td>
                    <td class="text-right">
                        <strong class="<?= $balance >= 0 ? 'text-success' : 'text-danger' ?>">
                            ฿ <?= format_number($balance) ?>
                        </strong>
                    </td>
                    <td>
                        <a href="?page=credits&user_id=<?= $credit['id'] ?>" 
                           class="btn btn-sm btn-primary">
                            ดูรายการ
                        </a>
                        
                        <?php if (can($current_user, 'credits.add')): ?>
                        <button class="btn btn-sm btn-success"
                                onclick="addPayment(<?= $credit['id'] ?>, '<?= escape($credit['name']) ?>')">
                            ชำระ
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($credits)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        ไม่มีข้อมูล
                    </td>
                </tr>
                <?php endif; ?>
                <!-- เพิ่มแถวสรุปรวม -->
                <?php if (count($credits) > 0): ?>
                <?php
                $sum_sales = array_sum(array_column($credits, 'total_sales'));
                $sum_commission = 0;
                $sum_win = array_sum(array_column($credits, 'total_win'));
                $sum_paid = array_sum(array_column($credits, 'total_paid'));
                foreach ($credits as $c) {
                    $sum_commission += $c['total_sales'] * ($c['commission_pct'] / 100);
                }
                $sum_balance = $sum_sales - $sum_commission - $sum_win - $sum_paid;
                ?>
                <tr class="bg-light">
                    <td><strong>รวมทั้งหมด</strong></td>
                    <td class="text-center"><strong><?= array_sum(array_column($credits, 'ticket_count')) ?></strong></td>
                    <td class="text-right"><strong>฿ <?= format_number($sum_sales) ?></strong></td>
                    <td class="text-right text-success"><strong>฿ <?= format_number($sum_commission) ?></strong></td>
                    <td class="text-right text-danger"><strong>฿ <?= format_number($sum_win) ?></strong></td>
                    <td class="text-right text-info"><strong>฿ <?= format_number($sum_paid) ?></strong></td>
                    <td class="text-right">
                        <strong class="<?= $sum_balance >= 0 ? 'text-success' : 'text-danger' ?>">
                            ฿ <?= format_number($sum_balance) ?>
                        </strong>
                    </td>
                    <td></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payment History -->
<?php if ($selected_user_id && !empty($payment_history)): ?>
<?php 
$selected_user = null;
foreach ($credits as $c) {
    if ($c['id'] == $selected_user_id) {
        $selected_user = $c;
        break;
    }
}
?>

<?php if ($selected_user): ?>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">
            💸 ประวัติการชำระ - <?= escape($selected_user['name']) ?>
        </h3>
    </div>
    
    <table class="table table-striped">
        <thead>
            <tr>
                <th width="150">วันที่</th>
                <th>ประเภท</th>
                <th>จำนวนเงิน</th>
                <th>หมายเหตุ</th>
                <th>บันทึกโดย</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payment_history as $payment): ?>
            <tr>
                <td><?= format_date($payment['created_at']) ?></td>
                <td>
                    <?php if ($payment['type'] === 'payment'): ?>
                        <span class="badge badge-success">ชำระ</span>
                    <?php elseif ($payment['type'] === 'refund'): ?>
                        <span class="badge badge-warning">คืนเงิน</span>
                    <?php else: ?>
                        <span class="badge badge-info"><?= escape($payment['type']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="<?= $payment['amount'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= $payment['amount'] >= 0 ? '+' : '' ?>฿ <?= format_number($payment['amount']) ?>
                </td>
                <td><?= escape($payment['note'] ?: '-') ?></td>
                <td><?= escape($payment['created_by_name'] ?: '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Payment Modal -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">บันทึกการชำระ</h3>
            <button type="button" class="modal-close" onclick="closeModal('paymentModal')">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="user_id" id="payment_user_id">
            
            <p>ผู้ขาย: <strong id="payment_user_name"></strong></p>
            
            <div class="form-group">
                <label class="form-label">ประเภท</label>
                <select name="type" class="form-control">
                    <option value="payment">ชำระเงิน</option>
                    <option value="refund">คืนเงิน</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">จำนวนเงิน</label>
                <input type="number" name="amount" class="form-control" 
                       step="0.01" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">หมายเหตุ</label>
                <textarea name="note" class="form-control" rows="3"></textarea>
            </div>
            
            <button type="submit" class="btn btn-primary">บันทึก</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('paymentModal')">ยกเลิก</button>
        </form>
    </div>
</div>

<script>
function addPayment(userId, userName) {
    document.getElementById('payment_user_id').value = userId;
    document.getElementById('payment_user_name').textContent = userName;
    document.getElementById('paymentModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}
</script>