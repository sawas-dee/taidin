<?php
// ============ pages/users.php - จัดการผู้ใช้ ============

$current_user = current_user();
if (!can($current_user, 'users.view')) {
    set_alert('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: ?page=dashboard');
    exit;
}

$db = DB::getInstance();

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Add user
    if ($action === 'add' && can($current_user, 'users.add')) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id = intval($_POST['role_id'] ?? 0);
        $commission_pct = floatval($_POST['commission_pct'] ?? 0);
        
        if ($name && validate_email($email) && $password && $role_id) {
            // Check duplicate email
            $exists = $db->fetch("SELECT id FROM users WHERE email = ?", [$email]);
            
            if (!$exists) {
                $user_id = $db->insert('users', [
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => hash_password($password),
                    'role_id' => $role_id,
                    'commission_pct' => $commission_pct
                ]);
                
                set_alert('success', 'เพิ่มผู้ใช้สำเร็จ');
            } else {
                set_alert('danger', 'อีเมลนี้มีในระบบแล้ว');
            }
        } else {
            set_alert('danger', 'กรุณากรอกข้อมูลให้ถูกต้อง');
        }
        
        header('Location: ?page=users');
        exit;
    }
    
    // Edit user
    if ($action === 'edit' && can($current_user, 'users.edit')) {
        $user_id = intval($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role_id = intval($_POST['role_id'] ?? 0);
        $commission_pct = floatval($_POST['commission_pct'] ?? 0);
        
        if ($user_id && $name && validate_email($email) && $role_id) {
            // Check duplicate email
            $exists = $db->fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$email, $user_id]);
            
            if (!$exists) {
                $db->update('users',
                    ['name' => $name, 'email' => $email, 'role_id' => $role_id, 'commission_pct' => $commission_pct],
                    'id = ?', [$user_id]
                );
                
                set_alert('success', 'แก้ไขผู้ใช้สำเร็จ');
            } else {
                set_alert('danger', 'อีเมลนี้มีในระบบแล้ว');
            }
        }
        
        header('Location: ?page=users');
        exit;
    }
    
    // Reset password
    if ($action === 'reset_password' && can($current_user, 'users.edit')) {
        $user_id = intval($_POST['user_id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';
        
        if ($user_id && $new_password) {
            $db->update('users',
                ['password_hash' => hash_password($new_password)],
                'id = ?', [$user_id]
            );
            
            set_alert('success', 'รีเซ็ตรหัสผ่านสำเร็จ');
        }
        
        header('Location: ?page=users');
        exit;
    }
    
    // Toggle active
    if ($action === 'toggle_active' && can($current_user, 'users.edit')) {
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if ($user_id && $user_id != $current_user['id']) {
            $user = $db->fetch("SELECT is_active FROM users WHERE id = ?", [$user_id]);
            if ($user) {
                $new_status = $user['is_active'] ? 0 : 1;
                $db->update('users',
                    ['is_active' => $new_status],
                    'id = ?', [$user_id]
                );
                
                set_alert('success', $new_status ? 'เปิดใช้งานผู้ใช้สำเร็จ' : 'ปิดใช้งานผู้ใช้สำเร็จ');
            }
        }
        
        header('Location: ?page=users');
        exit;
    }
    
    // Delete user
    if ($action === 'delete' && can($current_user, 'users.delete')) {
        $user_id = intval($_POST['user_id'] ?? 0);
        
        if ($user_id && $user_id != $current_user['id']) {
            // Check if user has data
            $has_tickets = $db->fetch("SELECT id FROM tickets WHERE user_id = ? LIMIT 1", [$user_id]);
            
            if (!$has_tickets) {
                $db->delete('users', 'id = ?', [$user_id]);
                set_alert('success', 'ลบผู้ใช้สำเร็จ');
            } else {
                set_alert('danger', 'ไม่สามารถลบผู้ใช้ที่มีข้อมูลในระบบ');
            }
        }
        
        header('Location: ?page=users');
        exit;
    }
}

// Get users
$users = $db->fetchAll("
    SELECT u.*, r.name as role_name,
        (SELECT COUNT(id) FROM tickets WHERE user_id = u.id) as ticket_count
    FROM users u
    JOIN roles r ON r.id = u.role_id
    ORDER BY u.id DESC
");

// Get roles for dropdown
$roles = $db->fetchAll("SELECT * FROM roles ORDER BY name");
?>

<h1 class="mb-3">👥 จัดการผู้ใช้</h1>

<?php if (can($current_user, 'users.add')): ?>
<!-- Add user form -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">➕ เพิ่มผู้ใช้ใหม่</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="add">
        
        <div class="grid grid-2">
            <div class="form-group">
                <label class="form-label">ชื่อ *</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">อีเมล *</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">รหัสผ่าน *</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">สิทธิ์ *</label>
                <select name="role_id" class="form-control" required>
                    <option value="">-- เลือกสิทธิ์ --</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['id'] ?>"><?= escape($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">ค่าคอมมิชชั่น (%)</label>
                <input type="number" name="commission_pct" class="form-control" 
                       min="0" max="100" step="0.01" value="0">
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">เพิ่มผู้ใช้</button>
    </form>
</div>
<?php endif; ?>

<!-- Users list -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            📋 รายการผู้ใช้ 
            <span class="badge badge-info"><?= count($users) ?></span>
        </h3>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th width="60">#</th>
                    <th>ชื่อ</th>
                    <th>อีเมล</th>
                    <th>สิทธิ์</th>
                    <th class="text-center">คอม%</th>
                    <th class="text-center">โพย</th>
                    <th class="text-center">สถานะ</th>
                    <th width="200">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td>
                        <strong><?= escape($user['name']) ?></strong>
                        <?php if ($user['id'] == $current_user['id']): ?>
                            <span class="badge badge-info">คุณ</span>
                        <?php endif; ?>
                    </td>
                    <td><?= escape($user['email']) ?></td>
                    <td>
                        <span class="badge badge-warning"><?= escape($user['role_name']) ?></span>
                    </td>
                    <td class="text-center"><?= format_number($user['commission_pct'], 2) ?>%</td>
                    <td class="text-center"><?= $user['ticket_count'] ?></td>
                    <td class="text-center">
                        <?php if ($user['is_active']): ?>
                            <span class="badge badge-success">ใช้งาน</span>
                        <?php else: ?>
                            <span class="badge badge-danger">ปิด</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php if (can($current_user, 'users.edit')): ?>
                                <button class="btn btn-sm btn-primary"
                                        onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                    แก้ไข
                                </button>
                                
                                <button class="btn btn-sm btn-warning"
                                        onclick="resetPassword(<?= $user['id'] ?>, '<?= escape($user['name']) ?>')">
                                    รีเซ็ต
                                </button>
                                
                                <?php if ($user['id'] != $current_user['id']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $user['is_active'] ? 'btn-danger' : 'btn-success' ?>">
                                            <?= $user['is_active'] ? 'ปิด' : 'เปิด' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if (can($current_user, 'users.delete') && $user['id'] != $current_user['id'] && $user['ticket_count'] == 0): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('ลบผู้ใช้ <?= escape($user['name']) ?>?')">
                                        ลบ
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modals -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">แก้ไขผู้ใช้</h3>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div class="form-group">
                <label class="form-label">ชื่อ</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">อีเมล</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">สิทธิ์</label>
                <select name="role_id" id="edit_role_id" class="form-control" required>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['id'] ?>"><?= escape($role['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">ค่าคอมมิชชั่น (%)</label>
                <input type="number" name="commission_pct" id="edit_commission" class="form-control" 
                       min="0" max="100" step="0.01">
            </div>
            
            <button type="submit" class="btn btn-primary">บันทึก</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">ยกเลิก</button>
        </form>
    </div>
</div>

<div id="resetModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">รีเซ็ตรหัสผ่าน</h3>
            <button type="button" class="modal-close" onclick="closeModal('resetModal')">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">
            
            <p>รีเซ็ตรหัสผ่านสำหรับ: <strong id="reset_user_name"></strong></p>
            
            <div class="form-group">
                <label class="form-label">รหัสผ่านใหม่</label>
                <input type="password" name="new_password" class="form-control" required minlength="6">
            </div>
            
            <button type="submit" class="btn btn-primary">รีเซ็ตรหัสผ่าน</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('resetModal')">ยกเลิก</button>
        </form>
    </div>
</div>

<script>
function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_name').value = user.name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_role_id').value = user.role_id;
    document.getElementById('edit_commission').value = user.commission_pct;
    document.getElementById('editModal').classList.add('show');
}

function resetPassword(userId, userName) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_user_name').textContent = userName;
    document.getElementById('resetModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}
</script>