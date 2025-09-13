<?php
// pages/draws.php - จัดการงวด (เพิ่มคอลัมน์การเงิน + Pagination 10 รายการ/หน้า)

$current_user = current_user();
if (!can($current_user, 'draws.view')) {
    set_alert('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: ?page=dashboard');
    exit;
}

$db = DB::getInstance();

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // สร้างงวดใหม่
    if ($action === 'create' && can($current_user, 'draws.add')) {
        $name = trim($_POST['name'] ?? '');
        $draw_date = $_POST['draw_date'] ?? '';
        
        if ($name && $draw_date) {
            $draw_id = $db->insert('draws', [
                'name' => $name,
                'draw_date' => $draw_date,
                'status' => 'open'
            ]);
            
            set_alert('success', 'สร้างงวดใหม่สำเร็จ');
            header('Location: ?page=draws');
            exit;
        } else {
            set_alert('danger', 'กรุณากรอกข้อมูลให้ครบ');
        }
    }
    
    // แก้ไขงวด
    if ($action === 'edit' && can($current_user, 'draws.edit')) {
        $draw_id = intval($_POST['draw_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $draw_date = $_POST['draw_date'] ?? '';
        
        if ($draw_id && $name && $draw_date) {
            $db->update('draws', 
                ['name' => $name, 'draw_date' => $draw_date],
                'id = ?', [$draw_id]
            );
            
            set_alert('success', 'แก้ไขงวดสำเร็จ');
            header('Location: ?page=draws');
            exit;
        }
    }
    
    // เปลี่ยนสถานะงวด
    if ($action === 'toggle_status' && can($current_user, 'draws.edit')) {
        $draw_id = intval($_POST['draw_id'] ?? 0);
        $draw = get_draw($draw_id);
        
        if ($draw) {
            $new_status = $draw['status'] === 'open' ? 'closed' : 'open';
            $closed_at = $new_status === 'closed' ? date('Y-m-d H:i:s') : null;
            
            $db->update('draws',
                ['status' => $new_status, 'closed_at' => $closed_at],
                'id = ?', [$draw_id]
            );
            
            $status_text = $new_status === 'open' ? 'เปิด' : 'ปิด';
            set_alert('success', "$status_text งวดสำเร็จ");
            header('Location: ?page=draws');
            exit;
        }
    }
    
    // ลบงวด
    if ($action === 'delete' && can($current_user, 'draws.delete')) {
        $draw_id = intval($_POST['draw_id'] ?? 0);
        
        // เช็คว่ามีโพยหรือไม่
        $has_tickets = $db->fetch("SELECT id FROM tickets WHERE draw_id = ? LIMIT 1", [$draw_id]);
        
        if (!$has_tickets) {
            $db->delete('draws', 'id = ?', [$draw_id]);
            set_alert('success', 'ลบงวดสำเร็จ');
        } else {
            set_alert('danger', 'ไม่สามารถลบงวดที่มีข้อมูลแล้ว');
        }
        
        header('Location: ?page=draws');
        exit;
    }
}

// Pagination settings
$items_per_page = 10; // 10 รายการต่อหน้า
$current_page = max(1, intval($_GET['p'] ?? 1));

// นับจำนวนทั้งหมด
$total_result = $db->fetch("SELECT COUNT(DISTINCT id) as total FROM draws");
$total_items = $total_result['total'] ?? 0;
$total_pages = ceil($total_items / $items_per_page);
$offset = ($current_page - 1) * $items_per_page;

// ดึงรายการงวดแบบแบ่งหน้า พร้อมข้อมูลการเงิน
$draws = $db->fetchAll("
    SELECT DISTINCT d.id, d.name, d.draw_date, d.status, d.created_at, d.closed_at,
        (SELECT COUNT(id) FROM tickets WHERE draw_id = d.id) as ticket_count,
        (SELECT COALESCE(SUM(amount), 0) FROM ticket_lines tl 
         JOIN tickets t ON t.id = tl.ticket_id 
         WHERE t.draw_id = d.id) as total_sales,
        (SELECT draw_id FROM results WHERE draw_id = d.id) as has_result
    FROM draws d
    ORDER BY d.id DESC
    LIMIT ? OFFSET ?
", [$items_per_page, $offset]);

// คำนวณข้อมูลการเงินเพิ่มเติมสำหรับแต่ละงวด
foreach ($draws as &$draw) {
    // ถ้ามีผลรางวัลแล้ว ให้คำนวณยอดจ่ายรางวัล
    $draw['total_payout'] = 0;
    $draw['total_commission'] = 0;
    $draw['net_profit'] = 0;
    
    if ($draw['has_result']) {
        // ดึงผลรางวัล
        $result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$draw['id']]);
        
        if ($result) {
            $win_3top = substr($result['top6'], 0, 3);
            $win_2top = substr($result['top6'], 4, 2);
            $win_2bottom = $result['bottom2'];
            
            // คำนวณยอดจ่ายรางวัล
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
    
    // คำนวณค่าคอมมิชชั่นรวม
    $commission_data = $db->fetch("
        SELECT COALESCE(SUM((tl.amount * u.commission_pct / 100)), 0) as total_commission
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        JOIN users u ON u.id = t.user_id
        WHERE t.draw_id = ?
    ", [$draw['id']]);
    
    $draw['total_commission'] = $commission_data['total_commission'] ?? 0;
    
    // คำนวณกำไรสุทธิ
    $draw['net_profit'] = $draw['total_sales'] - $draw['total_payout'] - $draw['total_commission'];
}
?>

<h1 class="mb-3">📅 จัดการงวด</h1>

<?php if (can($current_user, 'draws.add')): ?>
<!-- ฟอร์มเพิ่มงวดใหม่ -->
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">➕ เพิ่มงวดใหม่</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="d-flex gap-2" style="flex-wrap: wrap;">
            <input type="hidden" name="action" value="create">
            
            <div style="flex: 1; min-width: 200px;">
                <input type="text" name="name" class="form-control" 
                       placeholder="ชื่องวด เช่น งวด 16 ม.ค. 68" required>
            </div>
            
            <div style="min-width: 150px;">
                <input type="date" name="draw_date" class="form-control" 
                       value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <button type="submit" class="btn btn-primary">
                เพิ่มงวด
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- รายการงวด -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-between align-center">
            <h3 class="card-title">📋 รายการงวดทั้งหมด</h3>
            <?php if ($total_pages > 1): ?>
            <div class="text-muted">
                หน้า <?= $current_page ?> / <?= $total_pages ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th width="60">รหัส</th>
                    <th>ชื่องวด</th>
                    <th>วันที่</th>
                    <th class="text-center">สถานะ</th>
                    <th class="text-center">โพย</th>
                    <th class="text-right">ยอดขาย</th>
                    <th class="text-right">ยอดถูกรางวัล</th>
                    <th class="text-right">ค่าคอม</th>
                    <th class="text-right">กำไร</th>
                    <th width="150">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                    $displayed_ids = [];
                    foreach ($draws as $draw): 
                        // ตรวจสอบว่าแสดงไปแล้วหรือยัง
                        if (in_array($draw['id'], $displayed_ids)) continue;
                        $displayed_ids[] = $draw['id'];
                    ?>
                <tr>
                    <td>#<?= $draw['id'] ?></td>
                    <td>
                        <strong><?= escape($draw['name']) ?></strong>
                        <?php if ($draw['has_result']): ?>
                            <span class="badge badge-warning" style="font-size: 0.7rem;">ออกผลแล้ว</span>
                        <?php endif; ?>
                    </td>
                    <td><?= thai_date($draw['draw_date']) ?></td>
                    <td class="text-center">
                        <?php if ($draw['status'] === 'open'): ?>
                            <span class="badge badge-success">เปิดรับ</span>
                        <?php else: ?>
                            <span class="badge badge-danger">ปิดแล้ว</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= format_number($draw['ticket_count'], 0) ?>
                    </td>
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
                    <td>
                        <div class="d-flex gap-1">
                            <?php if (can($current_user, 'draws.edit')): ?>
                                <!-- ปุ่มเปิด/ปิด -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="draw_id" value="<?= $draw['id'] ?>">
                                    <?php if ($draw['status'] === 'open'): ?>
                                        <button type="submit" class="btn btn-sm btn-warning"
                                                onclick="return confirm('ต้องการปิดงวดนี้?')">
                                            ปิด
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-sm btn-success">
                                            เปิด
                                        </button>
                                    <?php endif; ?>
                                </form>
                                
                                <!-- ปุ่มแก้ไข -->
                                <button type="button" class="btn btn-sm btn-primary"
                                        onclick="editDraw(<?= htmlspecialchars(json_encode($draw)) ?>)">
                                    แก้ไข
                                </button>
                            <?php endif; ?>
                            
                            <?php if (can($current_user, 'draws.delete') && $draw['ticket_count'] == 0): ?>
                                <!-- ปุ่มลบ -->
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="draw_id" value="<?= $draw['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('ต้องการลบงวดนี้? การลบจะไม่สามารถย้อนกลับได้')">
                                        ลบ
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($draws)): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted">
                        ยังไม่มีงวด
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination justify-content-center mb-0">
                <!-- Previous -->
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=draws&p=<?= $current_page - 1 ?>">
                        ก่อนหน้า
                    </a>
                </li>
                
                <!-- Page numbers -->
                <?php
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                if ($start_page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=draws&p=1">1</a>
                    </li>
                    <?php if ($start_page > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=draws&p=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php endif; ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=draws&p=<?= $total_pages ?>"><?= $total_pages ?></a>
                    </li>
                <?php endif; ?>
                
                <!-- Next -->
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=draws&p=<?= $current_page + 1 ?>">
                        ถัดไป
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<!-- Modal แก้ไขงวด -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">แก้ไขงวด</h3>
            <button type="button" class="modal-close" onclick="closeEditModal()">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="draw_id" id="edit_draw_id">
            
            <div class="form-group">
                <label class="form-label">ชื่องวด</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">วันที่</label>
                <input type="date" name="draw_date" id="edit_date" class="form-control" required>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">บันทึก</button>
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">ยกเลิก</button>
            </div>
        </form>
    </div>
</div>

<script>
function editDraw(draw) {
    document.getElementById('edit_draw_id').value = draw.id;
    document.getElementById('edit_name').value = draw.name;
    document.getElementById('edit_date').value = draw.draw_date;
    document.getElementById('editModal').classList.add('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
}
</script>

<style>
/* Pagination Styles */
.pagination {
    display: flex;
    list-style: none;
    padding: 0;
    margin: 0;
    gap: 0.25rem;
}

.page-item {
    display: inline-block;
}

.page-link {
    display: block;
    padding: 0.5rem 0.75rem;
    color: var(--primary);
    text-decoration: none;
    background-color: #fff;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    transition: all 0.15s ease-in-out;
}

.page-link:hover {
    background-color: #e9ecef;
    border-color: #dee2e6;
    color: var(--primary-dark);
}

.page-item.active .page-link {
    background-color: var(--primary);
    border-color: var(--primary);
    color: #fff;
}

.page-item.disabled .page-link {
    color: #6c757d;
    pointer-events: none;
    background-color: #fff;
    border-color: #dee2e6;
    opacity: 0.5;
}

.card-footer {
    background-color: #f8f9fa;
    border-top: 1px solid rgba(0,0,0,.125);
    padding: 1rem;
}

.justify-content-center {
    justify-content: center !important;
}
</style>