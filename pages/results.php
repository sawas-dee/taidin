<?php
// pages/results.php - บันทึกผลรางวัล (พร้อมดึง API)

$current_user = current_user();
if (!can($current_user, 'results.view')) {
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

// Check if draw is closed
if ($current_draw['status'] !== 'closed' && !is_owner()) {
    echo '<div class="alert alert-warning">กรุณาปิดงวดก่อนบันทึกผลรางวัล</div>';
    exit;
}

// Get existing result
$result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$current_draw_id]);

// Handle AJAX check lottery
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'check_lottery') {
    header('Content-Type: application/json; charset=utf-8');
    
    $numbers = $_POST['numbers'] ?? [];
    $numbers = array_filter($numbers); // Remove empty
    
    if (empty($numbers)) {
        echo json_encode(['success' => false, 'message' => 'กรุณาใส่เลขสลาก']);
        exit;
    }
    
    // Format numbers for API
    $lottery_nums = [];
    foreach ($numbers as $num) {
        $num = preg_replace('/[^0-9]/', '', $num);
        if (strlen($num) === 6) {
            $lottery_nums[] = ['lottery_num' => $num];
        }
    }
    
    if (empty($lottery_nums)) {
        echo json_encode(['success' => false, 'message' => 'รูปแบบเลขไม่ถูกต้อง (ต้อง 6 หลัก)']);
        exit;
    }
    
    // Get draw date
    $draw_date = new DateTime($current_draw['draw_date']);
    $period = $draw_date->format('Y-m-d');
    
    // Call API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.glo.or.th/api/checking/getcheckLotteryResult");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'number' => $lottery_nums,
        'period_date' => $period
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if (isset($data['response']['result'])) {
            echo json_encode(['success' => true, 'results' => $data['response']['result']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลผลการตรวจ']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อ API ได้']);
    }
    exit;
}

// ดึงผลจาก API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'fetch_api') {
    // แปลงวันที่จาก draw_date
    $draw_date = new DateTime($current_draw['draw_date']);
    $day = $draw_date->format('d');
    $month = $draw_date->format('m');
    $year = $draw_date->format('Y');
    
    // เรียก API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.glo.or.th/api/checking/getLotteryResult");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'date' => $day,
        'month' => $month,
        'year' => $year
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        
        if (isset($data['response']['result']['data'])) {
            $prizes = $data['response']['result']['data'];
            
            // ดึงรางวัลที่ 1 (6 หลัก)
            $first_prize = $prizes['first']['number'][0]['value'] ?? '';
            
            // ดึงเลขท้าย 2 ตัว
            $last2_prize = $prizes['last2']['number'][0]['value'] ?? '';
            
            if (strlen($first_prize) == 6 && strlen($last2_prize) == 2) {
                // บันทึกผล
                if ($result) {
                    // Update
                    $db->update('results',
                        ['top6' => $first_prize, 'bottom2' => $last2_prize, 'updated_by' => $current_user['id'], 'is_from_api' => 1],
                        'draw_id = ?', [$current_draw_id]
                    );
                    set_alert('success', 'อัพเดทผลรางวัลจาก API สำเร็จ');
                } else {
                    // Insert
                    $db->insert('results', [
                        'draw_id' => $current_draw_id,
                        'top6' => $first_prize,
                        'bottom2' => $last2_prize,
                        'updated_by' => $current_user['id'],
                        'is_from_api' => 1
                    ]);
                    set_alert('success', 'ดึงผลรางวัลจาก API สำเร็จ');
                }
                
                // Store full API response for display
                $_SESSION['api_full_result'] = $prizes;
                
                header('Location: ?page=results');
                exit;
            } else {
                set_alert('danger', 'รูปแบบข้อมูลจาก API ไม่ถูกต้อง');
            }
        } else {
            set_alert('danger', 'ไม่พบข้อมูลผลรางวัลจาก API');
        }
    } else {
        set_alert('danger', 'ไม่สามารถเชื่อมต่อ API ได้ (HTTP ' . $httpCode . ')');
    }
}

// Process manual form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'manual_save' && can($current_user, 'results.add')) {
    // ตรวจสอบว่าผลมาจาก API หรือไม่
    if ($result && $result['is_from_api'] && !is_owner()) {
        set_alert('danger', 'ไม่สามารถแก้ไขผลที่ดึงจาก API ได้');
        header('Location: ?page=results');
        exit;
    }
    
    $top6 = preg_replace('/[^0-9]/', '', $_POST['top6'] ?? '');
    $bottom2 = preg_replace('/[^0-9]/', '', $_POST['bottom2'] ?? '');
    
    if (strlen($top6) !== 6) {
        set_alert('danger', 'รางวัลที่ 1 ต้องเป็นตัวเลข 6 หลัก');
    } elseif (strlen($bottom2) !== 2) {
        set_alert('danger', 'เลขท้าย 2 ตัว ต้องเป็นตัวเลข 2 หลัก');
    } else {
        if ($result) {
            // Update
            $db->update('results',
                ['top6' => $top6, 'bottom2' => $bottom2, 'updated_by' => $current_user['id'], 'is_from_api' => 0],
                'draw_id = ?', [$current_draw_id]
            );
            set_alert('success', 'แก้ไขผลรางวัลสำเร็จ');
        } else {
            // Insert
            $db->insert('results', [
                'draw_id' => $current_draw_id,
                'top6' => $top6,
                'bottom2' => $bottom2,
                'updated_by' => $current_user['id'],
                'is_from_api' => 0
            ]);
            set_alert('success', 'บันทึกผลรางวัลสำเร็จ');
        }
        
        header('Location: ?page=results');
        exit;
    }
}

// Re-fetch result after any updates
$result = $db->fetch("SELECT * FROM results WHERE draw_id = ?", [$current_draw_id]);

// Calculate winners if result exists
$winners = [];
if ($result) {
    // 3 ตัวบน - 3 ตัวท้ายจากรางวัลที่ 1
    $win_3top = substr($result['top6'], 3, 3);
    $winners['number3_top'] = $db->fetch("
        SELECT COUNT(DISTINCT tl.ticket_id) as tickets,
               SUM(tl.amount) as total_bet,
               SUM(tl.amount * tl.rate) as total_payout
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? AND tl.type = 'number3_top' AND tl.number = ?
    ", [$current_draw_id, $win_3top]);
    
    // 2 ตัวบน - 2 ตัวท้ายจากรางวัลที่ 1
    $win_2top = substr($result['top6'], 4, 2);
    $winners['number2_top'] = $db->fetch("
        SELECT COUNT(DISTINCT tl.ticket_id) as tickets,
               SUM(tl.amount) as total_bet,
               SUM(tl.amount * tl.rate) as total_payout
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? AND tl.type = 'number2_top' AND tl.number = ?
    ", [$current_draw_id, $win_2top]);
    
    // 2 ตัวล่าง - เลขท้าย 2 ตัว
    $winners['number2_bottom'] = $db->fetch("
        SELECT COUNT(DISTINCT tl.ticket_id) as tickets,
               SUM(tl.amount) as total_bet,
               SUM(tl.amount * tl.rate) as total_payout
        FROM ticket_lines tl
        JOIN tickets t ON t.id = tl.ticket_id
        WHERE t.draw_id = ? AND tl.type = 'number2_bottom' AND tl.number = ?
    ", [$current_draw_id, $result['bottom2']]);
}
?>

<h1 class="mb-3">🎯 ออกผลรางวัล - <?= escape($current_draw['name']) ?></h1>

<?php if ($current_draw['status'] !== 'closed'): ?>
<div class="alert alert-warning">
    ⚠️ งวดนี้ยังเปิดรับอยู่ ควรปิดงวดก่อนบันทึกผล
    <?php if (is_owner()): ?>
    <a href="?page=draws" class="btn btn-sm btn-warning" style="margin-left: 1rem;">
        ไปปิดงวด
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ปุ่มดึงผลจาก API -->
<?php if (can($current_user, 'results.add')): ?>
<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">🔄 ดึงผลจาก API</h3>
    </div>
    <div class="text-center p-3">
        <p>วันที่งวด: <strong><?= thai_date($current_draw['draw_date']) ?></strong></p>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="fetch_api">
            <button type="submit" class="btn btn-primary btn-lg">
                🔄 ดึงผลรางวัลจาก API
            </button>
        </form>
        
        <?php if (!$result): ?>
        <button class="btn btn-secondary btn-lg ml-2" onclick="toggleManualForm()">
            ✏️ กรอกเอง
        </button>
        <?php endif; ?>
        
        <button class="btn btn-info btn-lg ml-2" onclick="toggleCheckLottery()">
            🔍 ตรวจผลหวย
        </button>
    </div>
</div>
<?php endif; ?>

<!-- ฟอร์มตรวจผลหวย -->
<div class="card mb-3" id="check-lottery-form" style="display: none;">
    <div class="card-header">
        <h3 class="card-title">🔍 ตรวจผลสลากกินแบ่ง</h3>
    </div>
    <div class="p-3">
        <div id="lottery-inputs">
            <div class="input-group mb-2">
                <input type="text" class="form-control lottery-number" maxlength="6" placeholder="ใส่เลขสลาก 6 หลัก">
                <button class="btn btn-success" onclick="addLotteryInput()">➕ เพิ่ม</button>
            </div>
        </div>
        
        <button class="btn btn-primary w-100 mt-2" onclick="checkLottery()">
            🎯 เช็คผลรางวัลสลากกินแบ่ง
        </button>
        
        <div id="check-results" class="mt-3"></div>
    </div>
</div>

<div class="grid grid-2">
    <!-- ฟอร์มกรอกเอง (ซ่อนไว้) -->
    <div class="card" id="manual-form" style="display: <?= $result ? 'block' : 'none' ?>;">
        <div class="card-header">
            <h3 class="card-title">
                <?= $result ? '✏️ แก้ไขผลรางวัล' : '📝 กรอกผลรางวัลเอง' ?>
            </h3>
        </div>
        
        <?php if ($result && $result['is_from_api']): ?>
            <div class="alert alert-info m-3">
                ⚠️ ผลรางวัลนี้ดึงมาจาก API 
                <?php if (is_owner()): ?>
                    <br><small>Owner สามารถแก้ไขได้</small>
                <?php else: ?>
                    <br><small>ไม่สามารถแก้ไขได้</small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if (can($current_user, 'results.add') && (!$result || !$result['is_from_api'] || is_owner())): ?>
        <form method="POST" onsubmit="return confirmSave()">
            <input type="hidden" name="action" value="manual_save">
            
            <div class="form-group">
                <label class="form-label">รางวัลที่ 1 (6 หลัก)</label>
                <input type="text" name="top6" class="form-control" 
                       style="font-size: 2rem; text-align: center; font-weight: bold;"
                       maxlength="6" pattern="\d{6}" required
                       value="<?= $result ? $result['top6'] : '' ?>"
                       placeholder="XXXXXX">
                <small class="text-muted">ใส่ตัวเลข 6 หลัก</small>
            </div>
            
            <div class="form-group">
                <label class="form-label">เลขท้าย 2 ตัว</label>
                <input type="text" name="bottom2" class="form-control" 
                       style="font-size: 2rem; text-align: center; font-weight: bold;"
                       maxlength="2" pattern="\d{2}" required
                       value="<?= $result ? $result['bottom2'] : '' ?>"
                       placeholder="XX">
                <small class="text-muted">ใส่ตัวเลข 2 หลัก</small>
            </div>
            
            <button type="submit" class="btn btn-success btn-lg w-100">
                <?= $result ? '💾 บันทึกการแก้ไข' : '✅ บันทึกผลรางวัล' ?>
            </button>
        </form>
        <?php endif; ?>
        
        <?php if ($result): ?>
        <div style="border-top: 2px solid var(--border); margin-top: 1.5rem; padding-top: 1rem;">
            <small class="text-muted">
                บันทึกเมื่อ: <?= format_date($result['created_at']) ?>
                <?php if ($result['updated_by']): ?>
                    <?php 
                    $updater = $db->fetch("SELECT name FROM users WHERE id = ?", [$result['updated_by']]);
                    ?>
                    <br>โดย: <?= escape($updater['name'] ?? 'Unknown') ?>
                <?php endif; ?>
                <?php if ($result['is_from_api']): ?>
                    <br><span class="badge badge-info">จาก API</span>
                <?php endif; ?>
            </small>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- แสดงผล -->
    <div>
        <?php if ($result): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">🏆 ผลรางวัลที่ออก</h3>
            </div>
            
            <div class="text-center p-3">
                <div class="mb-4">
                    <small class="text-muted d-block">รางวัลที่ 1</small>
                    <div style="font-size: 3rem; font-weight: bold; color: var(--primary);">
                        <?= $result['top6'] ?>
                    </div>
                </div>
                
                <hr>
                
                <!-- แสดงผลแนวนอนกึ่งกลาง -->
                <div style="display: flex; justify-content: center; align-items: flex-start; gap: 3rem;">
                    <div style="text-align: center;">
                        <small style="display: block; color: #6b7280; margin-bottom: 0.5rem;">3 ตัวบน</small>
                        <div style="font-size: 2rem; font-weight: bold; color: #f59e0b;">
                            <?= substr($result['top6'], 3, 3) ?>
                        </div>
                        <small style="display: block; color: #6b7280; margin-top: 0.25rem;">(3 ตัวท้าย)</small>
                    </div>
                    
                    <div style="text-align: center;">
                        <small style="display: block; color: #6b7280; margin-bottom: 0.5rem;">2 ตัวบน</small>
                        <div style="font-size: 2rem; font-weight: bold; color: #3b82f6;">
                            <?= substr($result['top6'], 4, 2) ?>
                        </div>
                        <small style="display: block; color: #6b7280; margin-top: 0.25rem;">(2 ตัวท้าย)</small>
                    </div>
                    
                    <div style="text-align: center;">
                        <small style="display: block; color: #6b7280; margin-bottom: 0.5rem;">2 ตัวล่าง</small>
                        <div style="font-size: 2rem; font-weight: bold; color: #8b5cf6;">
                            <?= $result['bottom2'] ?>
                        </div>
                        <small style="display: block; color: #6b7280; margin-top: 0.25rem;">(เลขท้าย 2 ตัว)</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- สรุปผู้ถูกรางวัล -->
        <div class="card mt-3">
            <div class="card-header">
                <h3 class="card-title">💰 สรุปการจ่ายรางวัล</h3>
            </div>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>ประเภท</th>
                        <th>เลขที่ออก</th>
                        <th class="text-center">ผู้ถูก</th>
                        <th class="text-right">ยอดซื้อ</th>
                        <th class="text-right">จ่ายรางวัล</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>3 ตัวบน</td>
                        <td><strong style="color: #f59e0b;"><?= substr($result['top6'], 3, 3) ?></strong></td>
                        <td class="text-center"><?= $winners['number3_top']['tickets'] ?? 0 ?> คน</td>
                        <td class="text-right">฿ <?= format_number($winners['number3_top']['total_bet'] ?? 0) ?></td>
                        <td class="text-right text-danger">
                            <strong>฿ <?= format_number($winners['number3_top']['total_payout'] ?? 0) ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td>2 ตัวบน</td>
                        <td><strong style="color: #3b82f6;"><?= substr($result['top6'], 4, 2) ?></strong></td>
                        <td class="text-center"><?= $winners['number2_top']['tickets'] ?? 0 ?> คน</td>
                        <td class="text-right">฿ <?= format_number($winners['number2_top']['total_bet'] ?? 0) ?></td>
                        <td class="text-right text-danger">
                            <strong>฿ <?= format_number($winners['number2_top']['total_payout'] ?? 0) ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td>2 ตัวล่าง</td>
                        <td><strong style="color: #8b5cf6;"><?= $result['bottom2'] ?></strong></td>
                        <td class="text-center"><?= $winners['number2_bottom']['tickets'] ?? 0 ?> คน</td>
                        <td class="text-right">฿ <?= format_number($winners['number2_bottom']['total_bet'] ?? 0) ?></td>
                        <td class="text-right text-danger">
                            <strong>฿ <?= format_number($winners['number2_bottom']['total_payout'] ?? 0) ?></strong>
                        </td>
                    </tr>
                    <tr class="bg-light">
                        <td colspan="3"><strong>รวม</strong></td>
                        <td class="text-right">
                            <strong>฿ <?= format_number(
                                ($winners['number3_top']['total_bet'] ?? 0) +
                                ($winners['number2_top']['total_bet'] ?? 0) +
                                ($winners['number2_bottom']['total_bet'] ?? 0)
                            ) ?></strong>
                        </td>
                        <td class="text-right text-danger">
                            <strong>฿ <?= format_number(
                                ($winners['number3_top']['total_payout'] ?? 0) +
                                ($winners['number2_top']['total_payout'] ?? 0) +
                                ($winners['number2_bottom']['total_payout'] ?? 0)
                            ) ?></strong>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmSave() {
    const isEdit = <?= $result ? 'true' : 'false' ?>;
    const message = isEdit 
        ? 'ยืนยันการแก้ไขผลรางวัล?\n\n⚠️ การแก้ไขจะส่งผลต่อการคำนวณรางวัลทั้งหมด'
        : 'ยืนยันการบันทึกผลรางวัล?\n\nกรุณาตรวจสอบความถูกต้องก่อนบันทึก';
    
    return confirm(message);
}

function toggleManualForm() {
    const form = document.getElementById('manual-form');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function toggleCheckLottery() {
    const form = document.getElementById('check-lottery-form');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

let lotteryInputCount = 1;

function addLotteryInput() {
    if (lotteryInputCount >= 10) {
        Swal.fire('ใส่ได้สูงสุด 10 เลข', '', 'warning');
        return;
    }
    
    lotteryInputCount++;
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `
        <input type="text" class="form-control lottery-number" maxlength="6" placeholder="ใส่เลขสลาก 6 หลัก">
        <button class="btn btn-danger" onclick="this.parentElement.remove(); lotteryInputCount--;">❌ ลบ</button>
    `;
    
    document.getElementById('lottery-inputs').appendChild(div);
}

function checkLottery() {
    const inputs = document.querySelectorAll('.lottery-number');
    const numbers = [];
    
    inputs.forEach(input => {
        const val = input.value.trim();
        if (val) numbers.push(val);
    });
    
    if (numbers.length === 0) {
        Swal.fire('กรุณาใส่เลขสลาก', '', 'warning');
        return;
    }
    
    // Show loading
    Swal.fire({
        title: 'กำลังตรวจสอบ...',
        allowOutsideClick: false,
        showConfirmButton: false,
        willOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Send request
    const formData = new FormData();
    formData.append('action', 'check_lottery');
    numbers.forEach(num => {
        formData.append('numbers[]', num);
    });
    
    fetch('?page=results', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        
        if (data.success) {
            displayCheckResults(data.results);
        } else {
            Swal.fire('ไม่สำเร็จ', data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถตรวจสอบได้', 'error');
    });
}

function displayCheckResults(results) {
    let html = '<div class="alert alert-info"><strong>ผลการตรวจสอบ:</strong></div>';
    
    results.forEach(item => {
        const statusClass = item.statusType === 1 ? 'success' : 'secondary';
        const statusText = item.statusType === 1 ? '🎉 ถูกรางวัล!' : '❌ ไม่ถูกรางวัล';
        
        html += `
            <div class="card mb-2">
                <div class="card-body">
                    <h5 class="text-${statusClass}">
                        ${item.number} - ${statusText}
                    </h5>
        `;
        
        if (item.status_data && item.status_data.length > 0) {
            html += '<ul class="mb-0">';
            item.status_data.forEach(prize => {
                // แสดงชื่อรางวัลจาก reward field
                html += `<li>${prize.reward}</li>`;
            });
            html += '</ul>';
        }
        
        html += '</div></div>';
    });
    
    document.getElementById('check-results').innerHTML = html;
}

function numberWithCommas(x) {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}
</script>