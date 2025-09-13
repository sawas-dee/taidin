<?php
// ============ partials/nav.php ============
$current_user = current_user();
$current_page = $_GET['page'] ?? 'dashboard';
$current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();

// ถ้าเปลี่ยนงวด
if (isset($_POST['change_draw'])) {
    $_SESSION['current_draw_id'] = intval($_POST['draw_id']);
    $current_draw_id = $_SESSION['current_draw_id'];
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// เมนูตามสิทธิ์
$menu_items = [
    'dashboard' => ['label' => 'หน้าหลัก', 'icon' => '🏠', 'permission' => 'dashboard.view'],
    'tickets' => ['label' => 'คีย์โพย', 'icon' => '📝', 'permission' => 'tickets.view'],
    'orders' => ['label' => 'โพย', 'icon' => '📋', 'permission' => 'orders.view'],
    'numbers' => ['label' => 'ยอดเลข', 'icon' => '🔢', 'permission' => 'numbers.view'],
    'reports' => ['label' => 'รายงาน', 'icon' => '📊', 'permission' => 'reports.view'],
    'credits' => ['label' => 'เครดิต', 'icon' => '💰', 'permission' => 'credits.view'],
    'limits' => ['label' => 'อั้น/เรท', 'icon' => '⚙️', 'permission' => 'limits.view'],
    'draws' => ['label' => 'งวด', 'icon' => '📅', 'permission' => 'draws.view'],
    'results' => ['label' => 'ผล', 'icon' => '🎯', 'permission' => 'results.view'],
    'users' => ['label' => 'ผู้ใช้', 'icon' => '👥', 'permission' => 'users.view'],
    'roles' => ['label' => 'สิทธิ์', 'icon' => '🔐', 'permission' => 'roles.view'],
];
?>

<nav class="navbar">
    <div class="container">
        <div class="navbar-content">
            <div class="d-flex align-center gap-2">
                <a href="?page=dashboard" class="navbar-brand">
                    🎯 <?= escape(get_setting('site_name', 'ระบบคีย์หวย')) ?>
                </a>
                
                <!-- Draw Selector -->
                <form method="POST" style="display: inline;">
                    <select name="draw_id" class="draw-selector" onchange="this.form.submit()">
                        <?php
                        $draws = draw_list();
                        foreach ($draws as $draw) {
                            $selected = ($draw['id'] == $current_draw_id) ? 'selected' : '';
                            $status_text = $draw['status'] === 'open' ? '🟢' : '🔴';
                            echo '<option value="' . $draw['id'] . '" ' . $selected . '>';
                            echo $status_text . ' ' . escape($draw['name']);
                            echo '</option>';
                        }
                        ?>
                    </select>
                    <input type="hidden" name="change_draw" value="1">
                </form>
            </div>
            
            <div class="navbar-menu">
                <!-- User Info -->
                <span class="nav-link" style="background: var(--light);">
                    👤 <?= escape($current_user['name']) ?>
                    <small>(<?= escape($current_user['role_name']) ?>)</small>
                </span>
                
                <a href="?page=profile" class="nav-link <?= $current_page === 'profile' ? 'active' : '' ?>">
                    โปรไฟล์
                </a>
                
                <a href="?page=logout" class="nav-link" style="color: var(--danger);">
                    ออก
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- Sub Navigation -->
<div style="background: var(--white); border-bottom: 1px solid var(--border); margin-bottom: 1rem;">
    <div class="container">
        <div class="d-flex gap-1" style="overflow-x: auto; padding: 0.5rem 0;">
            <?php foreach ($menu_items as $key => $item): ?>
                <?php if (can($current_user, $item['permission'])): ?>
                    <a href="?page=<?= $key ?>" 
                       class="nav-link <?= $current_page === $key ? 'active' : '' ?>"
                        <span style="font-size: 0.9rem;"><?= $item['icon'] ?></span>
                        <span><?= $item['label'] ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>