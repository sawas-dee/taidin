<?php
// ============ pages/roles.php - จัดการสิทธิ์ ============

$current_user = current_user();
if (!can($current_user, 'roles.view')) {
    set_alert('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    header('Location: ?page=dashboard');
    exit;
}

$db = DB::getInstance();

// Process form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Add role
    if ($action === 'add' && can($current_user, 'roles.add')) {
        $name = trim($_POST['name'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        
        if ($name && !empty($permissions)) {
            // Check duplicate
            $exists = $db->fetch("SELECT id FROM roles WHERE name = ?", [$name]);
            
            if (!$exists) {
                $db->insert('roles', [
                    'name' => $name,
                    'permissions_json' => json_encode($permissions)
                ]);
                
                set_alert('success', 'เพิ่ม Role สำเร็จ');
            } else {
                set_alert('danger', 'ชื่อ Role นี้มีอยู่แล้ว');
            }
        } else {
            set_alert('danger', 'กรุณากรอกข้อมูลให้ครบ');
        }
        
        header('Location: ?page=roles');
        exit;
    }
    
    // Edit role
    if ($action === 'edit' && can($current_user, 'roles.edit')) {
        $role_id = intval($_POST['role_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $permissions = $_POST['permissions'] ?? [];
        
        if ($role_id && $name) {
            // Don't allow editing Owner role
            $role = $db->fetch("SELECT name FROM roles WHERE id = ?", [$role_id]);
            
            if ($role && $role['name'] !== 'Owner') {
                $db->update('roles',
                    ['name' => $name, 'permissions_json' => json_encode($permissions)],
                    'id = ?', [$role_id]
                );
                
                set_alert('success', 'แก้ไข Role สำเร็จ');
            } else if ($role['name'] === 'Owner') {
                set_alert('warning', 'ไม่สามารถแก้ไข Role Owner');
            }
        }
        
        header('Location: ?page=roles');
        exit;
    }
    
    // Delete role
    if ($action === 'delete' && can($current_user, 'roles.delete')) {
        $role_id = intval($_POST['role_id'] ?? 0);
        
        if ($role_id) {
            // Check if role is in use
            $role = $db->fetch("SELECT name FROM roles WHERE id = ?", [$role_id]);
            $users_count = $db->fetch("SELECT COUNT(id) as cnt FROM users WHERE role_id = ?", [$role_id]);
            
            if ($role && $role['name'] === 'Owner') {
                set_alert('danger', 'ไม่สามารถลบ Role Owner');
            } elseif ($users_count && $users_count['cnt'] > 0) {
                set_alert('danger', 'ไม่สามารถลบ Role ที่มีผู้ใช้งานอยู่');
            } else {
                $db->delete('roles', 'id = ?', [$role_id]);
                set_alert('success', 'ลบ Role สำเร็จ');
            }
        }
        
        header('Location: ?page=roles');
        exit;
    }
}

// Get roles
$roles = $db->fetchAll("
    SELECT r.*,
        (SELECT COUNT(id) FROM users WHERE role_id = r.id) as user_count
    FROM roles r
    ORDER BY r.id
");
?>

<h1 class="mb-3">🔐 จัดการสิทธิ์ (Roles)</h1>

<?php if (can($current_user, 'roles.add')): ?>
<!-- Add role form -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">➕ เพิ่ม Role ใหม่</h3>
    </div>
    
    <form method="POST">
        <input type="hidden" name="action" value="add">
        
        <div class="form-group">
            <label class="form-label">ชื่อ Role *</label>
            <input type="text" name="name" class="form-control" required>
        </div>
        
        <div class="form-group">
            <label class="form-label">สิทธิ์การใช้งาน *</label>
            
            <div class="grid grid-3">
                <?php foreach (PERMISSIONS as $menu => $actions): ?>
                <div class="card" style="padding: 1rem;">
                    <h4 style="margin-bottom: 0.5rem;"><?= ucfirst($menu) ?></h4>
                    <?php foreach ($actions as $action): ?>
                    <div class="form-check">
                        <input type="checkbox" name="permissions[]" 
                               value="<?= $menu . '.' . $action ?>"
                               id="perm_<?= $menu . '_' . $action ?>">
                        <label for="perm_<?= $menu . '_' . $action ?>">
                            <?= ucfirst($action) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">เพิ่ม Role</button>
    </form>
</div>
<?php endif; ?>

<!-- Roles list -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📋 รายการ Roles</h3>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th width="60">#</th>
                    <th>ชื่อ Role</th>
                    <th>จำนวนผู้ใช้</th>
                    <th>สิทธิ์</th>
                    <th width="150">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role): ?>
                <?php $perms = json_decode($role['permissions_json'], true) ?: []; ?>
                <tr>
                    <td><?= $role['id'] ?></td>
                    <td>
                        <strong><?= escape($role['name']) ?></strong>
                        <?php if ($role['name'] === 'Owner'): ?>
                            <span class="badge badge-danger">System</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-info"><?= $role['user_count'] ?> คน</span>
                    </td>
                    <td>
                        <small>
                            <?php if ($role['name'] === 'Owner'): ?>
                                <span class="text-danger">สิทธิ์เต็มทุกอย่าง</span>
                            <?php else: ?>
                                <?= count($perms) ?> สิทธิ์
                                <a href="#" onclick="showPermissions(<?= htmlspecialchars(json_encode($perms)) ?>); return false;">
                                    [ดูรายละเอียด]
                                </a>
                            <?php endif; ?>
                        </small>
                    </td>
                    <td>
                        <?php if ($role['name'] !== 'Owner'): ?>
                            <?php if (can($current_user, 'roles.edit')): ?>
                                <button class="btn btn-sm btn-primary"
                                        onclick="editRole(<?= htmlspecialchars(json_encode($role)) ?>)">
                                    แก้ไข
                                </button>
                            <?php endif; ?>
                            
                            <?php if (can($current_user, 'roles.delete') && $role['user_count'] == 0): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"
                                            onclick="return confirm('ลบ Role <?= escape($role['name']) ?>?')">
                                        ลบ
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit modal -->
<div id="editRoleModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">แก้ไข Role</h3>
            <button type="button" class="modal-close" onclick="closeModal('editRoleModal')">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="role_id" id="edit_role_id">
            
            <div class="form-group">
                <label class="form-label">ชื่อ Role</label>
                <input type="text" name="name" id="edit_role_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">สิทธิ์การใช้งาน</label>
                
                <div class="grid grid-3">
                    <?php foreach (PERMISSIONS as $menu => $actions): ?>
                    <div class="card" style="padding: 1rem;">
                        <h4 style="margin-bottom: 0.5rem;"><?= ucfirst($menu) ?></h4>
                        <?php foreach ($actions as $action): ?>
                        <div class="form-check">
                            <input type="checkbox" name="permissions[]" 
                                   value="<?= $menu . '.' . $action ?>"
                                   id="edit_perm_<?= $menu . '_' . $action ?>">
                            <label for="edit_perm_<?= $menu . '_' . $action ?>">
                                <?= ucfirst($action) ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary">บันทึก</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('editRoleModal')">ยกเลิก</button>
        </form>
    </div>
</div>

<script>
function showPermissions(perms) {
    const formatted = perms.map(p => {
        const [menu, action] = p.split('.');
        return `${menu}: ${action}`;
    }).join('\n');
    
    Swal.fire({
        title: 'สิทธิ์ที่มี',
        text: formatted,
        icon: 'info'
    });
}

function editRole(role) {
    document.getElementById('edit_role_id').value = role.id;
    document.getElementById('edit_role_name').value = role.name;
    
    // Clear all checkboxes
    document.querySelectorAll('#editRoleModal input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
    
    // Check permissions
    const perms = JSON.parse(role.permissions_json || '[]');
    perms.forEach(perm => {
        const id = 'edit_perm_' + perm.replace('.', '_');
        const checkbox = document.getElementById(id);
        if (checkbox) checkbox.checked = true;
    });
    
    document.getElementById('editRoleModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}
</script>