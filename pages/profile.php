<?php
// ============ pages/profile.php - โปรไฟล์ผู้ใช้ ============

$current_user = current_user();
if (!$current_user) {
    header('Location: ?page=login');
    exit;
}

$db = DB::getInstance();

// Process password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if ($new_password !== $confirm_password) {
        set_alert('danger', 'รหัสผ่านใหม่ไม่ตรงกัน');
    } elseif (strlen($new_password) < 6) {
        set_alert('danger', 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
    } elseif (!check_password($current_password, $current_user['password_hash'])) {
        set_alert('danger', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
    } else {
        // Get fresh user data
        $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$current_user['id']]);
        
        if ($user && check_password($current_password, $user['password_hash'])) {
            $db->update('users',
                ['password_hash' => hash_password($new_password)],
                'id = ?', [$current_user['id']]
            );
            
            set_alert('success', 'เปลี่ยนรหัสผ่านสำเร็จ');
            header('Location: ?page=profile');
            exit;
        } else {
            set_alert('danger', 'รหัสผ่านปัจจุบันไม่ถูกต้อง');
        }
    }
}

// Get user stats
$stats = $db->fetch("
    SELECT 
        COUNT(DISTINCT t.id) as total_tickets,
        COALESCE(SUM(tl.amount), 0) as total_sales,
        COUNT(DISTINCT t.draw_id) as total_draws
    FROM tickets t
    LEFT JOIN ticket_lines tl ON tl.ticket_id = t.id
    WHERE t.user_id = ?
", [$current_user['id']]);

// Parse permissions
$permissions = json_decode($current_user['permissions_json'], true) ?: [];
?>

<h1 class="mb-3">👤 โปรไฟล์ของฉัน</h1>

<div class="grid grid-2">
    <!-- ข้อมูลส่วนตัว -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">ข้อมูลส่วนตัว</h3>
        </div>
        
        <table class="table">
            <tr>
                <td width="120"><strong>รหัสผู้ใช้:</strong></td>
                <td>#<?= $current_user['id'] ?></td>
            </tr>
            <tr>
                <td><strong>ชื่อ:</strong></td>
                <td><?= escape($current_user['name']) ?></td>
            </tr>
            <tr>
                <td><strong>อีเมล:</strong></td>
                <td><?= escape($current_user['email']) ?></td>
            </tr>
            <tr>
                <td><strong>สิทธิ์:</strong></td>
                <td>
                    <span class="badge badge-warning"><?= escape($current_user['role_name']) ?></span>
                </td>
            </tr>
            <tr>
                <td><strong>ค่าคอม:</strong></td>
                <td><?= format_number($current_user['commission_pct'], 2) ?>%</td>
            </tr>
            <tr>
                <td><strong>สมัครเมื่อ:</strong></td>
                <td><?= format_date($current_user['created_at']) ?></td>
            </tr>
        </table>
        
        <!-- สถิติการใช้งาน -->
        <div style="border-top: 1px solid var(--border); padding-top: 1rem; margin-top: 1rem;">
            <h4>📊 สถิติการใช้งาน</h4>
            <div class="grid grid-3 mt-2">
                <div class="text-center">
                    <div class="text-primary" style="font-size: 1.5rem; font-weight: bold;">
                        <?= format_number($stats['total_tickets'], 0) ?>
                    </div>
                    <small class="text-muted">โพยทั้งหมด</small>
                </div>
                <div class="text-center">
                    <div class="text-success" style="font-size: 1.5rem; font-weight: bold;">
                        ฿<?= format_number($stats['total_sales'], 0) ?>
                    </div>
                    <small class="text-muted">ยอดขายรวม</small>
                </div>
                <div class="text-center">
                    <div class="text-warning" style="font-size: 1.5rem; font-weight: bold;">
                        <?= format_number($stats['total_draws'], 0) ?>
                    </div>
                    <small class="text-muted">งวดที่เล่น</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- เปลี่ยนรหัสผ่าน -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">🔐 เปลี่ยนรหัสผ่าน</h3>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">รหัสผ่านปัจจุบัน</label>
                <input type="password" name="current_password" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">รหัสผ่านใหม่</label>
                <input type="password" name="new_password" class="form-control" 
                       required minlength="6">
                <small class="text-muted">อย่างน้อย 6 ตัวอักษร</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            
            <button type="submit" name="change_password" class="btn btn-primary">
                เปลี่ยนรหัสผ่าน
            </button>
        </form>
    </div>
</div>

<!-- สิทธิ์ที่มี -->
<?php if ($current_user['role_name'] !== 'Owner'): ?>
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">🔑 สิทธิ์การใช้งานที่มี</h3>
    </div>
    
    <div class="grid grid-4 p-3">
        <?php foreach (PERMISSIONS as $menu => $actions): ?>
            <?php 
            $has_permission = false;
            foreach ($actions as $action) {
                if (in_array("$menu.$action", $permissions)) {
                    $has_permission = true;
                    break;
                }
            }
            ?>
            
            <?php if ($has_permission): ?>
            <div>
                <strong><?= ucfirst($menu) ?></strong>
                <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                    <?php foreach ($actions as $action): ?>
                        <?php if (in_array("$menu.$action", $permissions)): ?>
                        <li><small>✓ <?= ucfirst($action) ?></small></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="card mt-3">
    <div class="card-body text-center">
        <span class="badge badge-danger" style="font-size: 1rem; padding: 0.75rem 1.5rem;">
            🔑 Owner - มีสิทธิ์เต็มทุกอย่างในระบบ
        </span>
    </div>
</div>
<?php endif; ?>
