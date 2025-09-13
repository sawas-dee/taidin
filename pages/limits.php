<?php
// pages/limits.php - จัดการอั้นและเรท

$current_user = current_user();
if (!can($current_user, 'limits.view')) {
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

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && can($current_user, 'limits.edit')) {
    $action = $_POST['action'] ?? '';
    
    // บันทึกลิมิตมาตรฐาน
    if ($action === 'save_standard') {
        foreach (LOTTERY_TYPES as $type => $config) {
            $max_total = $_POST['std_max_' . $type] ?? null;
            $rate_override = $_POST['std_rate_' . $type] ?? null;
            
            // ถ้าไม่ใส่ = ไม่จำกัด/ใช้ default
            $max_total = $max_total !== '' ? floatval($max_total) : null;
            $rate_override = $rate_override !== '' ? floatval($rate_override) : null;
            
            // Check if exists
            $exists = $db->fetch(
                "SELECT id FROM limits_std WHERE draw_id = ? AND type = ?",
                [$current_draw_id, $type]
            );
            
            if ($exists) {
                // Update
                $db->update('limits_std',
                    ['max_total' => $max_total, 'rate_override' => $rate_override],
                    'draw_id = ? AND type = ?',
                    [$current_draw_id, $type]
                );
            } else if ($max_total !== null || $rate_override !== null) {
                // Insert only if has value
                $db->insert('limits_std', [
                    'draw_id' => $current_draw_id,
                    'type' => $type,
                    'max_total' => $max_total,
                    'rate_override' => $rate_override
                ]);
            }
        }
        
        set_alert('success', 'บันทึกลิมิตมาตรฐานสำเร็จ');
        header('Location: ?page=limits');
        exit;
    }
    
    // บันทึกลิมิตเฉพาะเลข
    if ($action === 'save_specific') {
        $type = $_POST['type'] ?? '';
        $numbers = $_POST['numbers'] ?? '';
        $max_total = $_POST['max_total'] ?? null;
        $rate_override = $_POST['rate_override'] ?? null;
        
        if ($type && $numbers) {
            // แยกเลขด้วย comma, space, newline
            $number_list = preg_split('/[\s,\n]+/', trim($numbers));
            $number_list = array_filter($number_list); // ลบค่าว่าง
            
            $max_total = $max_total !== '' ? floatval($max_total) : null;
            $rate_override = $rate_override !== '' ? floatval($rate_override) : null;
            
            $success_count = 0;
            $error_numbers = [];
            
            foreach ($number_list as $number) {
                // Validate number format
                if (!validate_lottery_number($number, $type)) {
                    $error_numbers[] = $number;
                    continue;
                }
                
                // Check if exists
                $exists = $db->fetch(
                    "SELECT id FROM limits_num WHERE draw_id = ? AND type = ? AND number = ?",
                    [$current_draw_id, $type, $number]
                );
                
                if ($exists) {
                    // Update
                    $db->update('limits_num',
                        ['max_total' => $max_total, 'rate_override' => $rate_override],
                        'draw_id = ? AND type = ? AND number = ?',
                        [$current_draw_id, $type, $number]
                    );
                } else {
                    // Insert
                    $db->insert('limits_num', [
                        'draw_id' => $current_draw_id,
                        'type' => $type,
                        'number' => $number,
                        'max_total' => $max_total,
                        'rate_override' => $rate_override
                    ]);
                }
                $success_count++;
            }
            
            if ($success_count > 0) {
                $msg = "บันทึกลิมิตสำเร็จ $success_count เลข";
                if (!empty($error_numbers)) {
                    $msg .= " (เลขผิดรูปแบบ: " . implode(', ', $error_numbers) . ")";
                }
                set_alert('success', $msg);
            } else {
                set_alert('danger', 'ไม่มีเลขที่ถูกต้อง');
            }
        } else {
            set_alert('danger', 'กรุณาเลือกประเภทและใส่เลข');
        }
        
        header('Location: ?page=limits');
        exit;
    }
    
    // ลบลิมิตเฉพาะเลข
    if ($action === 'delete_specific' && can($current_user, 'limits.delete')) {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            $db->delete('limits_num', 'id = ? AND draw_id = ?', [$id, $current_draw_id]);
            set_alert('success', 'ลบลิมิตสำเร็จ');
        }
        header('Location: ?page=limits');
        exit;
    }
}

// Get current limits
$standard_limits = [];
foreach (LOTTERY_TYPES as $type => $config) {
    $limit = $db->fetch(
        "SELECT * FROM limits_std WHERE draw_id = ? AND type = ?",
        [$current_draw_id, $type]
    );
    $standard_limits[$type] = $limit ?: ['max_total' => null, 'rate_override' => null];
}

$specific_limits = $db->fetchAll(
    "SELECT * FROM limits_num WHERE draw_id = ? ORDER BY type, number",
    [$current_draw_id]
);

// Get default settings
$default_rates = [];
foreach (LOTTERY_TYPES as $type => $config) {
    $default_rates[$type] = default_rate($type);
}
?>

<h1 class="mb-3">⚙️ อั้นเลข/เรทจ่าย - <?= escape($current_draw['name']) ?></h1>

<div class="alert alert-info">
    <strong>📌 ลำดับความสำคัญ:</strong><br>
    <strong>ลิมิต:</strong> เฉพาะเลข → มาตรฐานชนิด → ไม่จำกัด<br>
    <strong>เรทจ่าย:</strong> เฉพาะเลข → มาตรฐานชนิด → ค่า default (<?= implode(', ', array_map(function($t, $r) {
        return LOTTERY_TYPES[$t]['name'] . " = $r";
    }, array_keys($default_rates), $default_rates)) ?>)
</div>

<!-- ลิมิตมาตรฐาน -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">📊 ลิมิตมาตรฐานทั้งงวด</h3>
    </div>
    
    <?php if (can($current_user, 'limits.edit')): ?>
    <form method="POST">
        <input type="hidden" name="action" value="save_standard">
        
        <table class="table">
            <thead>
                <tr>
                    <th>ประเภท</th>
                    <th>ยอดรับสูงสุด (บาท)</th>
                    <th>เรทจ่าย Override</th>
                    <th>เรท Default</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (LOTTERY_TYPES as $type => $config): ?>
                <tr>
                    <td>
                        <strong><?= escape($config['name']) ?></strong>
                    </td>
                    <td>
                        <input type="number" name="std_max_<?= $type ?>" 
                               value="<?= $standard_limits[$type]['max_total'] ?>"
                               class="form-control" placeholder="ไม่จำกัด"
                               min="0" step="100">
                    </td>
                    <td>
                        <input type="number" name="std_rate_<?= $type ?>" 
                               value="<?= $standard_limits[$type]['rate_override'] ?>"
                               class="form-control" placeholder="ใช้ default"
                               min="0" step="10">
                    </td>
                    <td class="text-muted">
                        <?= format_number($default_rates[$type], 0) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="p-3">
            <button type="submit" class="btn btn-primary">
                💾 บันทึกลิมิตมาตรฐาน
            </button>
            <small class="text-muted" style="margin-left: 1rem;">
                * เว้นว่างไว้ = ไม่จำกัด/ใช้ค่า default
            </small>
        </div>
    </form>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>ประเภท</th>
                    <th>ยอดรับสูงสุด</th>
                    <th>เรทจ่าย</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (LOTTERY_TYPES as $type => $config): ?>
                <tr>
                    <td><?= escape($config['name']) ?></td>
                    <td>
                        <?= $standard_limits[$type]['max_total'] 
                            ? '฿ ' . format_number($standard_limits[$type]['max_total']) 
                            : 'ไม่จำกัด' ?>
                    </td>
                    <td>
                        <?= $standard_limits[$type]['rate_override'] 
                            ? format_number($standard_limits[$type]['rate_override'], 0)
                            : 'ใช้ default (' . $default_rates[$type] . ')' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- ลิมิตเฉพาะเลข -->
<div class="grid grid-2">
    <!-- ฟอร์มเพิ่ม -->
    <?php if (can($current_user, 'limits.add')): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">➕ เพิ่มลิมิตเฉพาะเลข</h3>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_specific">
            
            <div class="form-group">
                <label class="form-label">ประเภท</label>
                <select name="type" class="form-control" required>
                    <option value="">-- เลือกประเภท --</option>
                    <?php foreach (LOTTERY_TYPES as $type => $config): ?>
                        <option value="<?= $type ?>">
                            <?= escape($config['name']) ?> (<?= $config['digits'] ?> หลัก)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">เลข (คั่นด้วย comma หรือขึ้นบรรทัดใหม่)</label>
                <textarea name="numbers" class="form-control" rows="4" 
                          placeholder="เช่น 12,34,56 หรือ&#10;12&#10;34&#10;56" required></textarea>
            </div>
            
            <div class="form-group">
                <label class="form-label">ยอดรับสูงสุด (บาท)</label>
                <input type="number" name="max_total" class="form-control" 
                       placeholder="เว้นว่าง = ใช้ค่ามาตรฐาน" min="0" step="100">
            </div>
            
            <div class="form-group">
                <label class="form-label">เรทจ่าย Override</label>
                <input type="number" name="rate_override" class="form-control" 
                       placeholder="เว้นว่าง = ใช้ค่ามาตรฐาน" min="0" step="10">
            </div>
            
            <button type="submit" class="btn btn-primary">
                เพิ่มลิมิต
            </button>
        </form>
    </div>
    <?php endif; ?>
    
    <!-- รายการลิมิตเฉพาะเลข -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                📋 ลิมิตเฉพาะเลข 
                <span class="badge badge-info"><?= count($specific_limits) ?></span>
            </h3>
        </div>
        
        <div style="max-height: 500px; overflow-y: auto;">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th>เลข</th>
                        <th>ยอดรับ</th>
                        <th>เรท</th>
                        <?php if (can($current_user, 'limits.delete')): ?>
                        <th width="60"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($specific_limits as $limit): ?>
                    <tr>
                        <td>
                            <small><?= escape(LOTTERY_TYPES[$limit['type']]['name'] ?? $limit['type']) ?></small>
                        </td>
                        <td>
                            <strong style="font-size: 1.1em; color: var(--primary);">
                                <?= escape($limit['number']) ?>
                            </strong>
                        </td>
                        <td>
                            <?= $limit['max_total'] !== null
                                ? '฿ ' . format_number($limit['max_total']) 
                                : '<span class="text-muted">มาตรฐาน</span>' ?>
                        </td>
                        <td>
                            <?= $limit['rate_override'] !== null
                                ? 'x' . format_number($limit['rate_override'], 0)
                                : '<span class="text-muted">มาตรฐาน</span>' ?>
                        </td>
                        <?php if (can($current_user, 'limits.delete')): ?>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="delete_specific">
                                <input type="hidden" name="id" value="<?= $limit['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        onclick="return confirm('ลบลิมิตเลข <?= $limit['number'] ?>?')">
                                    ลบ
                                </button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($specific_limits)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted">
                            ยังไม่มีลิมิตเฉพาะเลข
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- สรุปเลขที่มีลิมิต -->
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">📊 สรุปสถานะลิมิตปัจจุบัน</h3>
    </div>
    
    <div class="grid grid-3">
        <?php 
        // จัดกลุ่มตามประเภท
        $grouped_limits = [];
        foreach ($specific_limits as $limit) {
            $grouped_limits[$limit['type']][] = $limit;
        }
        ?>
        
        <?php foreach (LOTTERY_TYPES as $type => $config): ?>
        <div>
            <h4><?= escape($config['name']) ?></h4>
            
            <div class="mb-2">
                <small class="text-muted">มาตรฐาน:</small><br>
                <?php if ($standard_limits[$type]['max_total'] !== null): ?>
                    <span class="badge badge-warning">
                        อั้น ฿<?= format_number($standard_limits[$type]['max_total'], 0) ?>
                    </span>
                <?php endif; ?>
                <?php if ($standard_limits[$type]['rate_override'] !== null): ?>
                    <span class="badge badge-info">
                        เรท x<?= format_number($standard_limits[$type]['rate_override'], 0) ?>
                    </span>
                <?php endif; ?>
                <?php if ($standard_limits[$type]['max_total'] === null && $standard_limits[$type]['rate_override'] === null): ?>
                    <span class="text-muted">ไม่ได้ตั้ง</span>
                <?php endif; ?>
            </div>
            
            <?php if (isset($grouped_limits[$type])): ?>
                <small class="text-muted">เฉพาะเลข (<?= count($grouped_limits[$type]) ?> เลข):</small><br>
                <div style="max-height: 150px; overflow-y: auto;">
                    <?php foreach ($grouped_limits[$type] as $limit): ?>
                        <span class="badge badge-success" style="margin: 2px;">
                            <?= $limit['number'] ?>
                            <?php if ($limit['max_total'] !== null): ?>
                                (฿<?= format_number($limit['max_total'], 0) ?>)
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <small class="text-muted">ไม่มีลิมิตเฉพาะเลข</small>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
// Auto format numbers in textarea
document.querySelector('textarea[name="numbers"]')?.addEventListener('input', function(e) {
    // Auto format as user types
    let value = e.target.value;
    
    // Replace spaces with commas
    value = value.replace(/\s+/g, ',');
    
    // Show formatted preview
    const type = document.querySelector('select[name="type"]').value;
    if (type) {
        const digits = type.includes('3') ? 3 : 2;
        const numbers = value.split(/[,\n]+/).filter(n => n.length === digits);
        
        if (numbers.length > 0) {
            const preview = numbers.slice(0, 5).join(', ');
            const more = numbers.length > 5 ? ` ... และอีก ${numbers.length - 5} เลข` : '';
            
            // You could show this in a preview element
            console.log('Numbers to add:', preview + more);
        }
    }
});

// Validate before submit
document.querySelector('form').addEventListener('submit', function(e) {
    const action = this.querySelector('input[name="action"]').value;
    
    if (action === 'save_specific') {
        const type = this.querySelector('select[name="type"]').value;
        const numbers = this.querySelector('textarea[name="numbers"]').value;
        
        if (!type || !numbers.trim()) {
            e.preventDefault();
            Swal.fire('กรุณากรอกข้อมูลให้ครบ', '', 'warning');
            return false;
        }
        
        // Validate number format
        const digits = type.includes('3') ? 3 : 2;
        const numberList = numbers.split(/[,\s\n]+/).filter(n => n);
        const validNumbers = numberList.filter(n => new RegExp(`^\\d{${digits}}
        
</script>