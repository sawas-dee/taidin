<?php
// pages/orders.php - จัดการโพยทั้งหมด (Fixed Version)

$current_user = current_user();
if (!can($current_user, 'orders.view')) {
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

// Check if draw has results
$result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$current_draw_id]);
$has_result = $result ? true : false;
$is_locked = $current_draw['status'] !== 'open' || $has_result;

// Get winning numbers if has result
$winning_numbers = [];
if ($result) {
    $winning_numbers['number3_top'] = substr($result['top6'], 3, 3);
    $winning_numbers['number2_top'] = substr($result['top6'], 4, 2);
    $winning_numbers['number2_bottom'] = $result['bottom2'];
}

// Process POST actions - ต้องอยู่ก่อนดึงข้อมูล
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Check if can edit (allow override for owner)
    $can_modify = !$is_locked || (is_owner() && isset($_SESSION['override_lock']));
    
    // Update payment status - ไม่ต้องเช็ค lock
    if ($action === 'update_payment') {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $payment_status = $_POST['payment_status'] ?? 'unpaid';
        
        if ($ticket_id > 0) {
            // ดึงข้อมูลเก่า
            $old_ticket = $db->fetch("SELECT * FROM tickets WHERE id = ?", [$ticket_id]);
            
            if ($old_ticket && ($old_ticket['user_id'] == $current_user['id'] || is_owner())) {
                // อัพเดท
                $rows = $db->update('tickets',
                    [
                        'payment_status' => $payment_status,
                        'updated_at' => date('Y-m-d H:i:s'),
                        'updated_by' => $current_user['id']
                    ],
                    'id = ?', [$ticket_id]
                );
                
                // Log history
                $db->insert('ticket_history', [
                    'ticket_id' => $ticket_id,
                    'action' => 'status_change',
                    'details' => json_encode([
                        'old_status' => $old_ticket['payment_status'] ?? 'unpaid',
                        'new_status' => $payment_status
                    ]),
                    'created_by' => $current_user['id']
                ]);
                
                set_alert('success', 'อัพเดทสถานะเป็น ' . $payment_status . ' สำเร็จ');
            }
        }
        
        // Redirect พร้อม ticket_id เพื่อให้ refresh ข้อมูล
        header('Location: ?page=orders&ticket_id=' . $ticket_id);
        exit;
    }
    
    // Edit line amount
    if ($action === 'edit_line' && can($current_user, 'orders.edit')) {
        $line_id = intval($_POST['line_id'] ?? 0);
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        $new_amount = floatval($_POST['amount'] ?? 0);
        
        if ($line_id > 0 && $ticket_id > 0 && $new_amount > 0) {
            // ดึงข้อมูลเก่า
            $old_line = $db->fetch("
                SELECT tl.*, t.user_id 
                FROM ticket_lines tl
                JOIN tickets t ON t.id = tl.ticket_id
                WHERE tl.id = ? AND tl.ticket_id = ?", [$line_id, $ticket_id]);
            
            if ($old_line && ($old_line['user_id'] == $current_user['id'] || is_owner())) {
                // อัพเดท line
                $db->update('ticket_lines', 
                    ['amount' => $new_amount],
                    'id = ?', [$line_id]);
                
                // คำนวณยอดรวมใหม่
                $total_result = $db->fetch("
                    SELECT COALESCE(SUM(amount), 0) as total 
                    FROM ticket_lines 
                    WHERE ticket_id = ?", [$ticket_id]);
                
                $new_total = $total_result['total'] ?? 0;
                
                // อัพเดท ticket total
                $db->update('tickets',
                    [
                        'total_amount' => $new_total,
                        'updated_at' => date('Y-m-d H:i:s'),
                        'updated_by' => $current_user['id']
                    ],
                    'id = ?', [$ticket_id]
                );
                
                // Log history
                $db->insert('ticket_history', [
                    'ticket_id' => $ticket_id,
                    'action' => 'edit_line',
                    'details' => json_encode([
                        'number' => $old_line['number'],
                        'old_amount' => $old_line['amount'],
                        'new_amount' => $new_amount
                    ]),
                    'created_by' => $current_user['id']
                ]);
                
                set_alert('success', 'แก้ไขจำนวนเงินสำเร็จ ยอดรวมใหม่ ฿' . format_number($new_total));
            }
        }
        
        header('Location: ?page=orders&ticket_id=' . $ticket_id);
        exit;
    }
    
    // Delete line
    if ($action === 'delete_line' && can($current_user, 'orders.edit')) {
        $line_id = intval($_POST['line_id'] ?? 0);
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        
        if ($line_id > 0 && $ticket_id > 0) {
            // ดึงข้อมูลเก่า
            $old_line = $db->fetch("
                SELECT tl.*, t.user_id 
                FROM ticket_lines tl
                JOIN tickets t ON t.id = tl.ticket_id
                WHERE tl.id = ? AND tl.ticket_id = ?", [$line_id, $ticket_id]);
            
            if ($old_line && ($old_line['user_id'] == $current_user['id'] || is_owner())) {
                // ลบ line
                $db->delete('ticket_lines', 'id = ?', [$line_id]);
                
                // เช็คว่ายังมี line เหลือไหม
                $remaining = $db->fetch("SELECT COUNT(*) as cnt FROM ticket_lines WHERE ticket_id = ?", [$ticket_id]);
                
                if ($remaining && $remaining['cnt'] > 0) {
                    // คำนวณยอดรวมใหม่
                    $total_result = $db->fetch("
                        SELECT COALESCE(SUM(amount), 0) as total 
                        FROM ticket_lines 
                        WHERE ticket_id = ?", [$ticket_id]);
                    
                    $new_total = $total_result['total'] ?? 0;
                    
                    // อัพเดท ticket total
                    $db->update('tickets',
                        [
                            'total_amount' => $new_total,
                            'updated_at' => date('Y-m-d H:i:s'),
                            'updated_by' => $current_user['id']
                        ],
                        'id = ?', [$ticket_id]
                    );
                    
                    // Log history
                    $db->insert('ticket_history', [
                        'ticket_id' => $ticket_id,
                        'action' => 'delete_line',
                        'details' => json_encode([
                            'number' => $old_line['number'],
                            'type' => $old_line['type'],
                            'amount' => $old_line['amount']
                        ]),
                        'created_by' => $current_user['id']
                    ]);
                    
                    set_alert('success', 'ลบรายการสำเร็จ ยอดรวมใหม่ ฿' . format_number($new_total));
                    header('Location: ?page=orders&ticket_id=' . $ticket_id);
                } else {
                    // ไม่มี line เหลือ ลบ ticket ทั้งใบ
                    $db->delete('tickets', 'id = ?', [$ticket_id]);
                    set_alert('success', 'ลบโพยทั้งใบ (ไม่มีรายการเหลือ)');
                    header('Location: ?page=orders');
                }
                exit;
            }
        }
        
        header('Location: ?page=orders&ticket_id=' . $ticket_id);
        exit;
    }
    
    // Delete ticket
    if ($action === 'delete' && can($current_user, 'orders.delete') && $can_modify) {
        $ticket_id = intval($_POST['ticket_id'] ?? 0);
        
        // Check ownership or admin
        $ticket = $db->fetch("SELECT user_id FROM tickets WHERE id = ? AND draw_id = ?", 
            [$ticket_id, $current_draw_id]);
        
        if ($ticket && ($ticket['user_id'] == $current_user['id'] || is_owner())) {
            // Log history before delete
            $db->insert('ticket_history', [
                'ticket_id' => $ticket_id,
                'action' => 'delete_ticket',
                'details' => json_encode(['reason' => 'User deleted entire ticket']),
                'created_by' => $current_user['id']
            ]);
            
            $db->delete('ticket_lines', 'ticket_id = ?', [$ticket_id]);
            $db->delete('tickets', 'id = ?', [$ticket_id]);
            set_alert('success', 'ลบโพยสำเร็จ');
        } else {
            set_alert('danger', 'ไม่สามารถลบโพยนี้ได้');
        }
        
        header('Location: ?page=orders');
        exit;
    }
    
    // Update all payment status - ฟีเจอร์สำหรับ Owner
    if ($action === 'update_all_payment' && is_owner()) {
        $payment_status = $_POST['payment_status'] ?? 'unpaid';
        $selected_tickets = $_POST['selected_tickets'] ?? [];
        
        if (!empty($selected_tickets)) {
            foreach ($selected_tickets as $ticket_id) {
                $ticket = $db->fetch("SELECT * FROM tickets WHERE id = ? AND draw_id = ?", 
                    [$ticket_id, $current_draw_id]);
                
                if ($ticket) {
                    // Log history
                    $db->insert('ticket_history', [
                        'ticket_id' => $ticket_id,
                        'action' => 'status_change',
                        'details' => json_encode([
                            'old_status' => $ticket['payment_status'] ?? 'unpaid',
                            'new_status' => $payment_status,
                            'bulk_update' => true
                        ]),
                        'created_by' => $current_user['id']
                    ]);
                    
                    $db->update('tickets',
                        [
                            'payment_status' => $payment_status,
                            'updated_at' => date('Y-m-d H:i:s'),
                            'updated_by' => $current_user['id']
                        ],
                        'id = ?', [$ticket_id]
                    );
                }
            }
            
            set_alert('success', 'อัพเดทสถานะ ' . count($selected_tickets) . ' โพยสำเร็จ');
        } else {
            set_alert('warning', 'กรุณาเลือกโพยที่ต้องการเปลี่ยนสถานะ');
        }
        
        header('Location: ?page=orders');
        exit;
    }
    
    // Owner override
    if (is_owner() && isset($_POST['override_action'])) {
        $override_action = $_POST['override_action'];
        
        if ($override_action === 'unlock_edit') {
            $_SESSION['override_lock'] = true;
            set_alert('warning', 'Owner Override: สามารถแก้ไขได้แม้งวดปิดแล้ว');
            header('Location: ?page=orders');
            exit;
        }
    }
}

$can_edit = !$is_locked || (is_owner() && isset($_SESSION['override_lock']));

// Update ticket numbers สำหรับงวดนี้
$tickets_without_number = $db->fetchAll("
    SELECT id FROM tickets 
    WHERE draw_id = ? AND (ticket_number IS NULL OR ticket_number = 0)
    ORDER BY created_at, id", [$current_draw_id]);

if (!empty($tickets_without_number)) {
    $max_num = $db->fetch("
        SELECT MAX(CAST(ticket_number AS INTEGER)) as max_num 
        FROM tickets 
        WHERE draw_id = ? AND ticket_number IS NOT NULL AND ticket_number > 0", 
        [$current_draw_id]);
    
    $next_num = ($max_num && $max_num['max_num']) ? ($max_num['max_num'] + 1) : 1;
    
    foreach ($tickets_without_number as $t) {
        $db->update('tickets', 
            ['ticket_number' => $next_num],
            'id = ?', [$t['id']]);
        $next_num++;
    }
}

// Get tickets with per-user numbering
$sql = "
    SELECT t.*, u.name as seller_name,
        (SELECT COUNT(id) FROM ticket_lines WHERE ticket_id = t.id) as line_count,
        COALESCE(CAST(t.ticket_number AS INTEGER), 999999) as order_num
    FROM tickets t
    JOIN users u ON u.id = t.user_id
    WHERE t.draw_id = ?
";

$params = [$current_draw_id];

// Filter by user if not admin
if (!is_owner() && !can($current_user, 'orders.view_all')) {
    $sql .= " AND t.user_id = ?";
    $params[] = $current_user['id'];
}

$sql .= " ORDER BY t.user_id, t.created_at, t.id";
$tickets = $db->fetchAll($sql, $params);

// คำนวณ user_order_num ด้วย PHP (ส่วนนี้สำคัญ!)
$user_counters = [];
foreach ($tickets as &$ticket) {
    $user_id = $ticket['user_id'];
    if (!isset($user_counters[$user_id])) {
        $user_counters[$user_id] = 0;
    }
    $user_counters[$user_id]++;
    $ticket['user_order_num'] = $user_counters[$user_id];
}
unset($ticket); // ยกเลิก reference

// Calculate winners if has result
if ($has_result) {
    foreach ($tickets as &$ticket) {
        $winner_lines = $db->fetchAll("
            SELECT * FROM ticket_lines 
            WHERE ticket_id = ? 
            AND (
                (type = 'number3_top' AND number = ?) OR
                (type = 'number2_top' AND number = ?) OR
                (type = 'number2_bottom' AND number = ?)
            )", 
            [$ticket['id'], 
             $winning_numbers['number3_top'] ?? '', 
             $winning_numbers['number2_top'] ?? '',
             $winning_numbers['number2_bottom'] ?? '']
        );
        
        $ticket['is_winner'] = count($winner_lines) > 0;
        $ticket['win_amount'] = 0;
        foreach ($winner_lines as $line) {
            $ticket['win_amount'] += $line['amount'] * $line['rate'];
        }
    }
}

// Get selected ticket details
$selected_ticket = null;
$ticket_lines = [];
$ticket_history = [];

if (isset($_GET['ticket_id'])) {
    $ticket_id = intval($_GET['ticket_id']);
    
    // ดึงข้อมูล ticket ใหม่จาก database (เพื่อให้ได้ข้อมูลล่าสุด)
    $selected_ticket = $db->fetch("
        SELECT t.*, u.name as seller_name
        FROM tickets t
        JOIN users u ON u.id = t.user_id
        WHERE t.id = ? AND t.draw_id = ?", 
        [$ticket_id, $current_draw_id]);
    
    if ($selected_ticket) {
        // Calculate winner status
        if ($has_result) {
            $winner_lines = $db->fetchAll("
                SELECT * FROM ticket_lines 
                WHERE ticket_id = ? 
                AND (
                    (type = 'number3_top' AND number = ?) OR
                    (type = 'number2_top' AND number = ?) OR
                    (type = 'number2_bottom' AND number = ?)
                )", 
                [$ticket_id, 
                 $winning_numbers['number3_top'] ?? '', 
                 $winning_numbers['number2_top'] ?? '',
                 $winning_numbers['number2_bottom'] ?? '']
            );
            
            $selected_ticket['is_winner'] = count($winner_lines) > 0;
            $selected_ticket['win_amount'] = 0;
            foreach ($winner_lines as $line) {
                $selected_ticket['win_amount'] += $line['amount'] * $line['rate'];
            }
        }
        
        // Get ticket lines
        $ticket_lines = $db->fetchAll("
            SELECT * FROM ticket_lines 
            WHERE ticket_id = ? 
            ORDER BY type, number", [$ticket_id]);
        
        // Check winning lines
        if ($has_result) {
            foreach ($ticket_lines as &$line) {
                $line['is_winner'] = false;
                if ($line['type'] == 'number3_top' && $line['number'] == ($winning_numbers['number3_top'] ?? '')) {
                    $line['is_winner'] = true;
                } elseif ($line['type'] == 'number2_top' && $line['number'] == ($winning_numbers['number2_top'] ?? '')) {
                    $line['is_winner'] = true;
                } elseif ($line['type'] == 'number2_bottom' && $line['number'] == ($winning_numbers['number2_bottom'] ?? '')) {
                    $line['is_winner'] = true;
                }
            }
        }
        
        // Get history
        $ticket_history = $db->fetchAll("
            SELECT h.*, u.name as user_name
            FROM ticket_history h
            LEFT JOIN users u ON u.id = h.created_by
            WHERE h.ticket_id = ?
            ORDER BY h.created_at DESC
            LIMIT 20", [$ticket_id]);
    }
}
?>

<style>
.ticket-winner {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
    animation: pulse 2s infinite;
}
.line-winner {
    background: #fef3c7 !important;
    font-weight: bold;
}
.payment-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-weight: 600;
}
.payment-paid { background: #10b981; color: white; }
.payment-unpaid { background: #ef4444; color: white; }
.payment-partial { background: #f59e0b; color: white; }
.border-top-thick {
    border-top: 3px solid var(--primary) !important;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.9; }
}
.ticket-checkbox {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
</style>

<h1 class="mb-3">
    📋 โพยทั้งหมด - <?= escape($current_draw['name']) ?>
    <?php if ($is_locked): ?>
        <span class="badge badge-danger">งวดปิดแล้ว</span>
    <?php endif; ?>
    <?php if ($has_result): ?>
        <span class="badge badge-warning">ออกผลแล้ว</span>
    <?php endif; ?>
</h1>

<?php if ($is_locked && is_owner() && !isset($_SESSION['override_lock'])): ?>
<div class="alert alert-warning">
    งวดนี้ปิดแล้ว/ออกผลแล้ว การแก้ไขถูกล็อค
    <form method="POST" style="display: inline;">
        <input type="hidden" name="override_action" value="unlock_edit">
        <button type="submit" class="btn btn-sm btn-warning" style="margin-left: 1rem;">
            🔓 Owner Override
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Summary Stats -->
<div class="grid grid-4 mb-3">
    <div class="stat-card">
        <div class="stat-value"><?= count($tickets) ?></div>
        <div class="stat-label">โพยทั้งหมด (งวดนี้)</div>
    </div>
    
    <?php if ($has_result): ?>
    <div class="stat-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
        <div class="stat-value">
            <?= count(array_filter($tickets, function($t) { return $t['is_winner'] ?? false; })) ?>
        </div>
        <div class="stat-label">โพยที่ถูกรางวัล</div>
    </div>
    <?php endif; ?>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
        <div class="stat-value">
            <?= count(array_filter($tickets, function($t) { return ($t['payment_status'] ?? 'unpaid') == 'paid'; })) ?>
        </div>
        <div class="stat-label">ชำระแล้ว</div>
    </div>
    
    <div class="stat-card" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
        <div class="stat-value">
            <?= count(array_filter($tickets, function($t) { return ($t['payment_status'] ?? 'unpaid') == 'unpaid'; })) ?>
        </div>
        <div class="stat-label">ยังไม่ชำระ</div>
    </div>
</div>

<div class="grid grid-2" style="gap: 2rem;">
    <!-- รายการโพย -->
    <div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">รายการโพย</h3>
                <div>
                    จำนวน: <strong><?= count($tickets) ?></strong> ใบ
                </div>
            </div>
            
            <!-- Bulk update for Owner -->
            <?php if (is_owner() && count($tickets) > 0): ?>
            <div style="padding: 1rem; border-bottom: 1px solid var(--border);">
                <form method="POST" id="bulk-update-form" action="?page=orders">
                    <input type="hidden" name="action" value="update_all_payment">
                    <div class="d-flex gap-2 align-center">
                        <input type="checkbox" id="select-all" class="ticket-checkbox">
                        <label for="select-all">เลือกทั้งหมด</label>
                        <select name="payment_status" class="form-control form-control-sm" style="width: 150px;">
                            <option value="unpaid">ยังไม่ชำระ</option>
                            <option value="paid">ชำระแล้ว</option>
                            <option value="partial">ชำระบางส่วน</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-success">
                            อัพเดทที่เลือก
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <div style="max-height: 500px; overflow-y: auto;">
                <table class="table table-striped">
                    <thead style="position: sticky; top: 0; background: white; z-index: 10;">
                        <tr>
                            <?php if (is_owner()): ?>
                            <th width="40"></th>
                            <?php endif; ?>
                            <th width="80">ลำดับ</th>
                            <th>ผู้ขาย/ลูกค้า</th>
                            <th class="text-center">สถานะ</th>
                            <th class="text-right">ยอด</th>
                            <th width="100">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $prev_user_id = null;
                        foreach ($tickets as $ticket): 
                            // เปลี่ยนสีเมื่อเปลี่ยนผู้ใช้ (สำหรับ Owner)
                            $new_user = ($prev_user_id !== null && $prev_user_id !== $ticket['user_id']);
                            $prev_user_id = $ticket['user_id'];
                        ?>
                        <tr class="<?= isset($_GET['ticket_id']) && $_GET['ticket_id'] == $ticket['id'] ? 'bg-light' : '' ?> 
                                   <?= ($ticket['is_winner'] ?? false) ? 'ticket-winner' : '' ?>
                                   <?= $new_user && is_owner() ? 'border-top-thick' : '' ?>">
                            <?php if (is_owner()): ?>
                            <td>
                                <input type="checkbox" name="selected_tickets[]" 
                                       value="<?= $ticket['id'] ?>" 
                                       form="bulk-update-form"
                                       class="ticket-checkbox ticket-select">
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php if (is_owner()): ?>
                                    <small class="text-muted"><?= escape(explode(' ', $ticket['seller_name'])[0]) ?></small><br>
                                <?php endif; ?>
                                <strong>#<?= $ticket['user_order_num'] ?></strong>
                                <?php if ($ticket['is_winner'] ?? false): ?>
                                    <span title="ถูกรางวัล">🏆</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= escape($ticket['seller_name']) ?></small><br>
                                <small class="text-muted"><?= escape($ticket['customer_name'] ?: '-') ?></small>
                            </td>
                            <td class="text-center">
                                <?php 
                                $payment_status = $ticket['payment_status'] ?? 'unpaid';
                                $badge_class = 'payment-' . $payment_status;
                                $badge_text = [
                                    'paid' => 'จ่ายแล้ว',
                                    'unpaid' => 'ยังไม่จ่าย', 
                                    'partial' => 'จ่ายบางส่วน'
                                ][$payment_status] ?? $payment_status;
                                ?>
                                <span class="payment-badge <?= $badge_class ?>">
                                    <?= $badge_text ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <strong>฿ <?= format_number($ticket['total_amount']) ?></strong>
                                <?php if (($ticket['win_amount'] ?? 0) > 0): ?>
                                    <br><small class="text-success">ถูก ฿<?= format_number($ticket['win_amount']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="?page=orders&ticket_id=<?= $ticket['id'] ?>" 
                                       class="btn btn-sm btn-primary">
                                        ดู
                                    </a>
                                    
                                    <?php if ($can_edit && can($current_user, 'orders.delete')): ?>
                                        <?php if ($ticket['user_id'] == $current_user['id'] || is_owner()): ?>
                                        <form method="POST" action="?page=orders" style="display: inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('ลบโพย #<?= $ticket['user_order_num'] ?> ของ <?= escape($ticket['seller_name']) ?>?')">
                                                ลบ
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="<?= is_owner() ? 6 : 5 ?>" class="text-center text-muted">
                                ยังไม่มีโพย
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- รายละเอียดโพย -->
    <div>
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-between align-center">
                    <h3 class="card-title" style="margin: 0;">
                        <?php if ($selected_ticket): ?>
                            รายละเอียดโพย #<?= $selected_ticket['ticket_number'] ?? $selected_ticket['id'] ?>
                            <?php if ($selected_ticket['is_winner'] ?? false): ?>
                                <span class="badge badge-warning">🏆 ถูกรางวัล</span>
                            <?php endif; ?>
                        <?php else: ?>
                            รายละเอียดโพย
                        <?php endif; ?>
                    </h3>
                    <?php if ($selected_ticket): ?>
                    <button class="btn btn-sm btn-secondary" onclick="closeDetail()">
                        ✖ ปิด
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($selected_ticket): ?>
                <div class="mb-3">
                    <div class="grid grid-2">
                        <div>
                            <strong>ผู้ขาย:</strong> <?= escape($selected_ticket['seller_name']) ?>
                        </div>
                        <div>
                            <strong>ลูกค้า:</strong> <?= escape($selected_ticket['customer_name'] ?: '-') ?>
                        </div>
                        <div>
                            <strong>วันที่:</strong> <?= format_date($selected_ticket['created_at']) ?>
                        </div>
                        <div>
                            <strong>สถานะชำระ:</strong>
                            <form method="POST" action="?page=orders" style="display: inline;">
                                <input type="hidden" name="action" value="update_payment">
                                <input type="hidden" name="ticket_id" value="<?= $selected_ticket['id'] ?>">
                                <select name="payment_status" class="form-control form-control-sm" 
                                        style="width: auto; display: inline-block;"
                                        onchange="this.form.submit()">
                                    <option value="unpaid" <?= ($selected_ticket['payment_status'] ?? 'unpaid') == 'unpaid' ? 'selected' : '' ?>>ยังไม่ชำระ</option>
                                    <option value="paid" <?= ($selected_ticket['payment_status'] ?? 'unpaid') == 'paid' ? 'selected' : '' ?>>ชำระแล้ว</option>
                                    <option value="partial" <?= ($selected_ticket['payment_status'] ?? 'unpaid') == 'partial' ? 'selected' : '' ?>>ชำระบางส่วน</option>
                                </select>
                            </form>
                        </div>
                    </div>
                    
                    <?php if ($selected_ticket['updated_at']): ?>
                    <div class="mt-2 text-muted">
                        <small>
                            แก้ไขล่าสุด: <?= format_date($selected_ticket['updated_at']) ?>
                            <?php if ($selected_ticket['updated_by']): ?>
                                <?php 
                                $updater = $db->fetch("SELECT name FROM users WHERE id = ?", [$selected_ticket['updated_by']]);
                                ?>
                                โดย <?= escape($updater['name'] ?? 'Unknown') ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ประเภท</th>
                            <th>เลข</th>
                            <th>จำนวน</th>
                            <th>เรท</th>
                            <th>คาดจ่าย</th>
                            <?php if ($can_edit && can($current_user, 'orders.edit')): ?>
                            <th width="150">จัดการ</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ticket_lines as $line): ?>
                        <tr class="<?= ($line['is_winner'] ?? false) ? 'line-winner' : '' ?>">
                            <td>
                                <span class="badge badge-info">
                                    <?= escape(LOTTERY_TYPES[$line['type']]['name'] ?? $line['type']) ?>
                                </span>
                            </td>
                            <td>
                                <strong style="font-size: 1.2em;">
                                    <?= escape($line['number']) ?>
                                    <?php if ($line['is_winner'] ?? false): ?>
                                        <span title="ถูกรางวัล">✅</span>
                                    <?php endif; ?>
                                </strong>
                            </td>
                            <td>
                                <?php if ($can_edit && can($current_user, 'orders.edit')): ?>
                                    <form method="POST" action="?page=orders" class="d-flex gap-1">
                                        <input type="hidden" name="action" value="edit_line">
                                        <input type="hidden" name="line_id" value="<?= $line['id'] ?>">
                                        <input type="hidden" name="ticket_id" value="<?= $selected_ticket['id'] ?>">
                                        <input type="number" name="amount" value="<?= $line['amount'] ?>"
                                               class="form-control form-control-sm" style="width: 100px;"
                                               min="1" step="1">
                                        <button type="submit" class="btn btn-sm btn-success">บันทึก</button>
                                    </form>
                                <?php else: ?>
                                    ฿ <?= format_number($line['amount']) ?>
                                <?php endif; ?>
                            </td>
                            <td>x<?= format_number($line['rate'], 0) ?></td>
                            <td>
                                <span class="<?= ($line['is_winner'] ?? false) ? 'text-success' : 'text-muted' ?>">
                                    ฿ <?= format_number($line['amount'] * $line['rate']) ?>
                                </span>
                            </td>
                            <?php if ($can_edit && can($current_user, 'orders.edit')): ?>
                            <td>
                                <form method="POST" action="?page=orders" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_line">
                                    <input type="hidden" name="line_id" value="<?= $line['id'] ?>">
                                    <input type="hidden" name="ticket_id" value="<?= $selected_ticket['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('ลบเลข <?= $line['number'] ?>?')">
                                        ลบ
                                    </button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr class="bg-light">
                            <td colspan="2"><strong>รวม</strong></td>
                            <td>
                                <strong>฿ <?= format_number($selected_ticket['total_amount']) ?></strong>
                            </td>
                            <td colspan="<?= $can_edit ? 3 : 2 ?>">
                                <?php if (($selected_ticket['win_amount'] ?? 0) > 0): ?>
                                    <strong class="text-success">
                                        ถูกรางวัล ฿ <?= format_number($selected_ticket['win_amount']) ?>
                                    </strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- History -->
                <?php if (!empty($ticket_history)): ?>
                <div style="border-top: 2px solid var(--border); padding: 1rem;">
                    <h4>📝 ประวัติการแก้ไข</h4>
                    <div style="max-height: 200px; overflow-y: auto;">
                        <?php foreach ($ticket_history as $history): ?>
                        <?php $details = json_decode($history['details'], true) ?: []; ?>
                        <div style="border-bottom: 1px solid var(--border); padding: 0.5rem 0;">
                            <small class="text-muted">
                                <?= format_date($history['created_at']) ?> - 
                                <?= escape($history['user_name'] ?? 'Unknown') ?>
                            </small>
                            <br>
                            <?php if ($history['action'] == 'edit_line'): ?>
                                แก้ไขเลข <?= $details['number'] ?? '' ?> จาก ฿<?= $details['old_amount'] ?? 0 ?> เป็น ฿<?= $details['new_amount'] ?? 0 ?>
                            <?php elseif ($history['action'] == 'delete_line'): ?>
                                ลบเลข <?= $details['number'] ?? '' ?> (<?= $details['type'] ?? '' ?>) จำนวน ฿<?= $details['amount'] ?? 0 ?>
                            <?php elseif ($history['action'] == 'status_change'): ?>
                                เปลี่ยนสถานะจาก <?= $details['old_status'] ?? '' ?> เป็น <?= $details['new_status'] ?? '' ?>
                                <?= isset($details['bulk_update']) ? ' (อัพเดทแบบกลุ่ม)' : '' ?>
                            <?php elseif ($history['action'] == 'delete_ticket'): ?>
                                พยายามลบโพยทั้งใบ: <?= $details['reason'] ?? '' ?>
                            <?php else: ?>
                                <?= escape($history['action']) ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Print button -->
                <div class="mt-3">
                    <button class="btn btn-outline" onclick="printTicket(<?= $selected_ticket['id'] ?>)">
                        🖨️ พิมพ์โพย
                    </button>
                </div>
                
            <?php else: ?>
                <div class="text-center text-muted p-4">
                    เลือกโพยเพื่อดูรายละเอียด
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Select all checkbox
document.getElementById('select-all')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.ticket-select');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Close detail
function closeDetail() {
    window.location.href = '?page=orders';
}

function printTicket(ticketId) {
    const printWindow = window.open('', '_blank', 'width=400,height=600');
    
    const ticket = <?= json_encode($selected_ticket) ?>;
    const lines = <?= json_encode($ticket_lines) ?>;
    
    if (!ticket) return;
    
    let html = `
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>โพย #${ticket.ticket_number || ticketId}</title>
            <style>
                body { 
                    font-family: 'Sarabun', sans-serif; 
                    padding: 20px;
                    font-size: 14px;
                }
                h2 { text-align: center; margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 5px; border-bottom: 1px solid #ddd; }
                th { text-align: left; background: #f0f0f0; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .total { 
                    font-weight: bold; 
                    font-size: 1.2em; 
                    margin-top: 10px;
                    text-align: right;
                }
                .winner { background: #fffbeb; font-weight: bold; }
            </style>
        </head>
        <body>
            <h2><?= escape(get_setting('site_name', 'ระบบคีย์หวย')) ?></h2>
            <p><strong>โพย #${ticket.ticket_number || ticketId}</strong></p>
            <p>งวด: <?= escape($current_draw['name']) ?></p>
            <p>วันที่: ${new Date(ticket.created_at).toLocaleString('th-TH')}</p>
            <p>ผู้ขาย: ${ticket.seller_name}</p>
            ${ticket.customer_name ? `<p>ลูกค้า: ${ticket.customer_name}</p>` : ''}
            <hr>
            <table>
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th class="text-center">เลข</th>
                        <th class="text-right">จำนวน</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    lines.forEach(line => {
        const typeName = {
            'number3_top': '3 ตัวบน',
            'number2_top': '2 ตัวบน',
            'number2_bottom': '2 ตัวล่าง'
        }[line.type] || line.type;
        
        const isWinner = line.is_winner || false;
        
        html += `
            <tr ${isWinner ? 'class="winner"' : ''}>
                <td>${typeName}</td>
                <td class="text-center">
                    <strong>${line.number}</strong>
                    ${isWinner ? ' ✓' : ''}
                </td>
                <td class="text-right">${line.amount}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
            <hr>
            <div class="total">
                รวม: ฿ ${parseFloat(ticket.total_amount).toFixed(2)}
            </div>
            ${ticket.win_amount > 0 ? `<div class="total" style="color: green;">ถูกรางวัล: ฿ ${parseFloat(ticket.win_amount).toFixed(2)}</div>` : ''}
        </body>
        </html>
    `;
    
    printWindow.document.write(html);
    printWindow.document.close();
    
    setTimeout(() => {
        printWindow.print();
    }, 500);
}

// Clear override on page leave
window.addEventListener('beforeunload', function() {
    <?php unset($_SESSION['override_lock']); ?>
});
</script>