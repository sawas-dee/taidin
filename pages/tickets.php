<?php
// pages/tickets.php - ระบบคีย์โพย (Fixed AJAX Response)

// ตรวจสอบ AJAX request ทันที
$is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
           (isset($_POST['action']) && !empty($_POST['action'])) ||
           (isset($_GET['action']) && !empty($_GET['action']));

$ajax_action = $_POST['action'] ?? $_GET['action'] ?? '';

// ถ้าเป็น AJAX request ให้จัดการทันที ก่อนที่จะมี output ใดๆ
if ($is_ajax && $ajax_action) {
    // No session_start here as it's already started in config.php
    header('Content-Type: application/json; charset=utf-8');
    
    // Disable all error output
    error_reporting(0);
    ini_set('display_errors', 0);
    
    // Clean any existing output
    if (ob_get_level()) {
        ob_clean();
    }
    
    try {
        // Load required files if not loaded
        if (!function_exists('current_user')) {
            require_once __DIR__ . '/../lib/functions.php';
        }
        if (!class_exists('DB')) {
            require_once __DIR__ . '/../lib/db.php';
        }
        
        $current_user = current_user();
        if (!$current_user || !can($current_user, 'tickets.view')) {
            throw new Exception('ไม่มีสิทธิ์เข้าถึง');
        }
        
        $db = DB::getInstance();
        $current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();
        $current_draw = $current_draw_id ? get_draw($current_draw_id) : null;
        
        if (!$current_draw) {
            throw new Exception('ไม่พบข้อมูลงวด');
        }
        
        // Initialize cart
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        
        $response = ['success' => false, 'message' => 'Invalid action'];
        
        switch ($ajax_action) {
            case 'get_cart':
                $cart_total = 0;
                foreach ($_SESSION['cart'] as $item) {
                    $cart_total += floatval($item['amount']);
                }
                
                // Generate cart HTML
                $cart_html = '';
                if (empty($_SESSION['cart'])) {
                    $cart_html = '<div class="text-center text-muted p-3">ยังไม่มีรายการ</div>';
                } else {
                    $cart_html = '<div style="max-height: 400px; overflow-y: auto;"><table class="table table-sm">';
                    $cart_html .= '<thead><tr><th>เลข</th><th>ประเภท</th><th width="100">จำนวน</th><th width="40"></th></tr></thead><tbody>';
                    
                    foreach ($_SESSION['cart'] as $key => $item) {
                        $type_name = LOTTERY_TYPES[$item['type']]['name'] ?? $item['type'];
                        $cart_html .= '<tr>';
                        $cart_html .= '<td><strong style="color: var(--primary); font-size: 1.1em;">' . htmlspecialchars($item['number']) . '</strong></td>';
                        $cart_html .= '<td><small>' . htmlspecialchars($type_name) . ' <span class="text-muted">x' . $item['rate'] . '</span></small></td>';
                        $cart_html .= '<td><input type="number" class="form-control form-control-sm amount-input" data-key="' . htmlspecialchars($key) . '" value="' . $item['amount'] . '" min="1"></td>';
                        $cart_html .= '<td><button type="button" class="btn btn-sm btn-danger remove-btn" data-key="' . htmlspecialchars($key) . '">×</button></td>';
                        $cart_html .= '</tr>';
                    }
                    
                    $cart_html .= '</tbody></table></div>';
                }
                
                $response = [
                    'success' => true,
                    'html' => $cart_html,
                    'count' => count($_SESSION['cart']),
                    'total' => $cart_total
                ];
                break;
                
            case 'add_to_cart':
                if ($current_draw['status'] !== 'open') {
                    throw new Exception('งวดนี้ปิดรับแล้ว');
                }
                
                $type = $_POST['type'] ?? '';
                $number = $_POST['number'] ?? '';
                $amount = floatval($_POST['amount'] ?? 0);
                $with_reverse = ($_POST['with_reverse'] ?? 'false') === 'true';
                
                // Validate
                if (!isset(LOTTERY_TYPES[$type])) {
                    throw new Exception('ประเภทไม่ถูกต้อง');
                }
                
                $expected_digits = LOTTERY_TYPES[$type]['digits'];
                if (!preg_match('/^\d{' . $expected_digits . '}$/', $number)) {
                    throw new Exception('รูปแบบเลขไม่ถูกต้อง');
                }
                
                if ($amount <= 0) {
                    throw new Exception('จำนวนเงินต้องมากกว่า 0');
                }
                
                // Prepare numbers to add
                $numbers_to_add = [$number];
                
                if ($with_reverse) {
                    if ($expected_digits == 2) {
                        // Reverse 2 digits
                        $reversed = strrev($number);
                        if ($reversed !== $number) {
                            $numbers_to_add[] = $reversed;
                        }
                    } elseif ($expected_digits == 3) {
                        // Permute 3 digits (helper function below)
                        $perms = [];
                        $arr = str_split($number);
                        permute($arr, 0, count($arr) - 1, $perms);
                        foreach ($perms as $p) {
                            if ($p !== $number && !in_array($p, $numbers_to_add)) {
                                $numbers_to_add[] = $p;
                            }
                        }
                    }
                }
                
                // Add to cart
                $added = 0;
                $errors = [];
                
                foreach ($numbers_to_add as $num) {
                    $cart_key = $type . '_' . $num;
                    $current_amount = isset($_SESSION['cart'][$cart_key]) ? $_SESSION['cart'][$cart_key]['amount'] : 0;
                    
                    // Check limit
                    $limit_check = check_limit($current_draw_id, $type, $num, $amount + $current_amount);
                    
                    if (!$limit_check['can_sell']) {
                        $errors[] = "เลข $num เหลือรับ " . format_number($limit_check['available']);
                        continue;
                    }
                    
                    // Add or update
                    if (isset($_SESSION['cart'][$cart_key])) {
                        $_SESSION['cart'][$cart_key]['amount'] += $amount;
                    } else {
                        $_SESSION['cart'][$cart_key] = [
                            'type' => $type,
                            'number' => $num,
                            'amount' => $amount,
                            'rate' => rate_for($type, $current_draw_id, $num)
                        ];
                    }
                    $added++;
                }
                
                // Generate updated cart HTML
                $cart_html = '';
                $cart_total = 0;
                
                if (empty($_SESSION['cart'])) {
                    $cart_html = '<div class="text-center text-muted p-3">ยังไม่มีรายการ</div>';
                } else {
                    $cart_html = '<div style="max-height: 400px; overflow-y: auto;"><table class="table table-sm">';
                    $cart_html .= '<thead><tr><th>เลข</th><th>ประเภท</th><th width="100">จำนวน</th><th width="40"></th></tr></thead><tbody>';
                    
                    foreach ($_SESSION['cart'] as $key => $item) {
                        $cart_total += floatval($item['amount']);
                        $type_name = LOTTERY_TYPES[$item['type']]['name'] ?? $item['type'];
                        $cart_html .= '<tr>';
                        $cart_html .= '<td><strong style="color: var(--primary); font-size: 1.1em;">' . htmlspecialchars($item['number']) . '</strong></td>';
                        $cart_html .= '<td><small>' . htmlspecialchars($type_name) . ' <span class="text-muted">x' . $item['rate'] . '</span></small></td>';
                        $cart_html .= '<td><input type="number" class="form-control form-control-sm amount-input" data-key="' . htmlspecialchars($key) . '" value="' . $item['amount'] . '" min="1"></td>';
                        $cart_html .= '<td><button type="button" class="btn btn-sm btn-danger remove-btn" data-key="' . htmlspecialchars($key) . '">×</button></td>';
                        $cart_html .= '</tr>';
                    }
                    
                    $cart_html .= '</tbody></table></div>';
                }
                
                $response = [
                    'success' => $added > 0,
                    'message' => $added > 0 ? "เพิ่ม $added เลข" : 'ไม่สามารถเพิ่มได้',
                    'errors' => $errors,
                    'html' => $cart_html,
                    'count' => count($_SESSION['cart']),
                    'total' => $cart_total
                ];
                break;
                
            case 'update_amount':
                $key = $_POST['key'] ?? '';
                $amount = floatval($_POST['amount'] ?? 0);
                
                if (isset($_SESSION['cart'][$key]) && $amount > 0) {
                    $item = $_SESSION['cart'][$key];
                    $limit_check = check_limit($current_draw_id, $item['type'], $item['number'], $amount);
                    
                    if ($limit_check['can_sell']) {
                        $_SESSION['cart'][$key]['amount'] = $amount;
                        
                        // Regenerate cart
                        $cart_html = '';
                        $cart_total = 0;
                        
                        if (!empty($_SESSION['cart'])) {
                            $cart_html = '<div style="max-height: 400px; overflow-y: auto;"><table class="table table-sm">';
                            $cart_html .= '<thead><tr><th>เลข</th><th>ประเภท</th><th width="100">จำนวน</th><th width="40"></th></tr></thead><tbody>';
                            
                            foreach ($_SESSION['cart'] as $k => $itm) {
                                $cart_total += floatval($itm['amount']);
                                $type_name = LOTTERY_TYPES[$itm['type']]['name'] ?? $itm['type'];
                                $cart_html .= '<tr>';
                                $cart_html .= '<td><strong style="color: var(--primary); font-size: 1.1em;">' . htmlspecialchars($itm['number']) . '</strong></td>';
                                $cart_html .= '<td><small>' . htmlspecialchars($type_name) . ' <span class="text-muted">x' . $itm['rate'] . '</span></small></td>';
                                $cart_html .= '<td><input type="number" class="form-control form-control-sm amount-input" data-key="' . htmlspecialchars($k) . '" value="' . $itm['amount'] . '" min="1"></td>';
                                $cart_html .= '<td><button type="button" class="btn btn-sm btn-danger remove-btn" data-key="' . htmlspecialchars($k) . '">×</button></td>';
                                $cart_html .= '</tr>';
                            }
                            
                            $cart_html .= '</tbody></table></div>';
                        }
                        
                        $response = [
                            'success' => true,
                            'message' => 'อัพเดทสำเร็จ',
                            'html' => $cart_html,
                            'count' => count($_SESSION['cart']),
                            'total' => $cart_total
                        ];
                    } else {
                        throw new Exception('เหลือรับได้ ' . format_number($limit_check['available']));
                    }
                } else {
                    throw new Exception('ข้อมูลไม่ถูกต้อง');
                }
                break;
                
            case 'remove_item':
                $key = $_POST['key'] ?? '';
                if (isset($_SESSION['cart'][$key])) {
                    unset($_SESSION['cart'][$key]);
                }
                
                // Regenerate cart
                $cart_html = '';
                $cart_total = 0;
                
                if (empty($_SESSION['cart'])) {
                    $cart_html = '<div class="text-center text-muted p-3">ยังไม่มีรายการ</div>';
                } else {
                    $cart_html = '<div style="max-height: 400px; overflow-y: auto;"><table class="table table-sm">';
                    $cart_html .= '<thead><tr><th>เลข</th><th>ประเภท</th><th width="100">จำนวน</th><th width="40"></th></tr></thead><tbody>';
                    
                    foreach ($_SESSION['cart'] as $k => $item) {
                        $cart_total += floatval($item['amount']);
                        $type_name = LOTTERY_TYPES[$item['type']]['name'] ?? $item['type'];
                        $cart_html .= '<tr>';
                        $cart_html .= '<td><strong style="color: var(--primary); font-size: 1.1em;">' . htmlspecialchars($item['number']) . '</strong></td>';
                        $cart_html .= '<td><small>' . htmlspecialchars($type_name) . ' <span class="text-muted">x' . $item['rate'] . '</span></small></td>';
                        $cart_html .= '<td><input type="number" class="form-control form-control-sm amount-input" data-key="' . htmlspecialchars($k) . '" value="' . $item['amount'] . '" min="1"></td>';
                        $cart_html .= '<td><button type="button" class="btn btn-sm btn-danger remove-btn" data-key="' . htmlspecialchars($k) . '">×</button></td>';
                        $cart_html .= '</tr>';
                    }
                    
                    $cart_html .= '</tbody></table></div>';
                }
                
                $response = [
                    'success' => true,
                    'html' => $cart_html,
                    'count' => count($_SESSION['cart']),
                    'total' => $cart_total
                ];
                break;
                
            case 'clear_cart':
                $_SESSION['cart'] = [];
                $response = [
                    'success' => true,
                    'html' => '<div class="text-center text-muted p-3">ยังไม่มีรายการ</div>',
                    'count' => 0,
                    'total' => 0
                ];
                break;
                
            case 'set_all_price':
                $amount = floatval($_POST['amount'] ?? 0);
                
                if ($amount > 0) {
                    $updated = 0;
                    $errors = [];
                    
                    foreach ($_SESSION['cart'] as $key => &$item) {
                        $limit_check = check_limit($current_draw_id, $item['type'], $item['number'], $amount);
                        if ($limit_check['can_sell']) {
                            $item['amount'] = $amount;
                            $updated++;
                        } else {
                            $errors[] = "เลข {$item['number']} รับได้สูงสุด " . format_number($limit_check['available']);
                        }
                    }
                    
                    // Regenerate cart
                    $cart_html = '';
                    $cart_total = 0;
                    
                    if (!empty($_SESSION['cart'])) {
                        $cart_html = '<div style="max-height: 400px; overflow-y: auto;"><table class="table table-sm">';
                        $cart_html .= '<thead><tr><th>เลข</th><th>ประเภท</th><th width="100">จำนวน</th><th width="40"></th></tr></thead><tbody>';
                        
                        foreach ($_SESSION['cart'] as $k => $item) {
                            $cart_total += floatval($item['amount']);
                            $type_name = LOTTERY_TYPES[$item['type']]['name'] ?? $item['type'];
                            $cart_html .= '<tr>';
                            $cart_html .= '<td><strong style="color: var(--primary); font-size: 1.1em;">' . htmlspecialchars($item['number']) . '</strong></td>';
                            $cart_html .= '<td><small>' . htmlspecialchars($type_name) . ' <span class="text-muted">x' . $item['rate'] . '</span></small></td>';
                            $cart_html .= '<td><input type="number" class="form-control form-control-sm amount-input" data-key="' . htmlspecialchars($k) . '" value="' . $item['amount'] . '" min="1"></td>';
                            $cart_html .= '<td><button type="button" class="btn btn-sm btn-danger remove-btn" data-key="' . htmlspecialchars($k) . '">×</button></td>';
                            $cart_html .= '</tr>';
                        }
                        
                        $cart_html .= '</tbody></table></div>';
                    }
                    
                    $response = [
                        'success' => $updated > 0,
                        'message' => "อัพเดท $updated เลข",
                        'errors' => $errors,
                        'html' => $cart_html,
                        'count' => count($_SESSION['cart']),
                        'total' => $cart_total
                    ];
                } else {
                    throw new Exception('จำนวนเงินไม่ถูกต้อง');
                }
                break;
                
            case 'save_ticket':
                if (empty($_SESSION['cart'])) {
                    $response = ['success' => false, 'message' => 'ไม่มีรายการ'];
                    break;
                }
                
                $customer_name = $_POST['customer_name'] ?? '';
                
                $db->getConnection()->beginTransaction();
                try {
                    // Final validation
                    $errors = [];
                    foreach ($_SESSION['cart'] as $item) {
                        $limit_check = check_limit($current_draw_id, $item['type'], $item['number'], $item['amount']);
                        if (!$limit_check['can_sell']) {
                            $errors[] = "เลข {$item['number']} เหลือรับ " . format_number($limit_check['available']);
                        }
                    }
                    
                    if (!empty($errors)) {
                        throw new Exception(implode(', ', $errors));
                    }
                    
                    // Calculate total
                    $total = 0;
                    foreach ($_SESSION['cart'] as $item) {
                        $total += floatval($item['amount']);
                    }
                    
                    // Generate ticket number for this draw (นับแยกตามงวด)
                    $ticket_number = $db->generateTicketNumber($current_draw_id);
                    
                    // Create ticket with ticket_number
                    $ticket_id = $db->insert('tickets', [
                        'draw_id' => $current_draw_id,
                        'user_id' => $current_user['id'],
                        'ticket_number' => $ticket_number,  // เพิ่ม ticket_number
                        'customer_name' => $customer_name,
                        'total_amount' => $total,
                        'status' => 'confirmed',
                        'payment_status' => 'unpaid',  // default status
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    // Add lines
                    foreach ($_SESSION['cart'] as $item) {
                        $db->insert('ticket_lines', [
                            'ticket_id' => $ticket_id,
                            'type' => $item['type'],
                            'number' => $item['number'],
                            'amount' => $item['amount'],
                            'rate' => $item['rate']
                        ]);
                    }
                    
                    $db->getConnection()->commit();
                    $_SESSION['cart'] = [];
                    
                    // ⬇️ วางตรงนี้เลย (หลัง commit และล้าง cart)
                    if (function_exists('cache_clear')) {
                        cache_clear("dashboard_{$current_draw_id}_*");
                        cache_clear("numbers_{$current_draw_id}_*");
                        cache_clear("stats_{$current_draw_id}_*");
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => "บันทึกโพย #$ticket_number\nยอดรวม " . format_number($total) . " บาท",
                        'ticket_id' => $ticket_id,
                        'ticket_number' => $ticket_number
                    ];
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollBack();
                    $response = [
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
                break;
                
            default:
                throw new Exception('Invalid action: ' . $ajax_action);
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    
    exit; // Stop here for AJAX
}

// Helper function for permutation
function permute(&$arr, $l, $r, &$result) {
    if ($l == $r) {
        $result[] = implode('', $arr);
    } else {
        for ($i = $l; $i <= $r; $i++) {
            // Swap
            $tmp = $arr[$l];
            $arr[$l] = $arr[$i];
            $arr[$i] = $tmp;
            
            permute($arr, $l + 1, $r, $result);
            
            // Swap back
            $tmp = $arr[$l];
            $arr[$l] = $arr[$i];
            $arr[$i] = $tmp;
        }
    }
}

// === NORMAL PAGE LOAD STARTS HERE ===

$current_user = current_user();
if (!can($current_user, 'tickets.view')) {
    set_alert('danger', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
    safe_redirect('?page=dashboard');
    exit;
}

$db = DB::getInstance();
$current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();
$current_draw = get_draw($current_draw_id);

// Initialize cart
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Check if draw is open
if (!$current_draw || $current_draw['status'] !== 'open') {
    echo '<div class="alert alert-warning">งวดนี้ปิดรับแล้ว ไม่สามารถคีย์โพยได้</div>';
    exit;
}

// Calculate initial cart total
$cart_total = 0;
foreach ($_SESSION['cart'] as $item) {
    $cart_total += floatval($item['amount']);
}
?>

<!-- ส่วน HTML และ JavaScript เหมือนเดิม -->
<style>
.type-btn {
    min-width: 120px;
    padding: 0.7rem;
    text-align: center;
    transition: all 0.3s;
    position: relative;
    border: 2px solid var(--border);
    margin: 0 0.3rem;
}
.type-btn.active {
    background: var(--primary) !important;
    color: white !important;
    border-color: var(--primary-dark);
    transform: scale(1.05);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}
.type-btn:not(.active):hover {
    background: var(--light);
}
.reverse-btn {
    transition: all 0.3s;
    border: 2px solid var(--info);
}
.reverse-btn.active {
    background: var(--info) !important;
    color: white !important;
}
.num-pad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.4rem;
    max-width: 280px;
    margin: 0 auto;
}
.num-btn {
    width: 100%;
    height: 55px;
    font-size: 1.3rem;
    font-weight: bold;
    border-radius: 0.4rem;
}
.num-btn:active {
    transform: scale(0.95);
}
.action-section {
    background: var(--light);
    padding: 0.8rem;
    border-radius: 0.4rem;
    margin-bottom: 0.8rem;
}
.quick-amt {
    min-width: 55px;
}
.number-inputs {
    display: flex;
    gap: 0.3rem;
    justify-content: center;
    margin-bottom: 1rem;
}
.number-input-box {
    width: 50px;
    height: 60px;
    font-size: 2rem;
    font-weight: bold;
    text-align: center;
    border: 2px solid var(--primary);
    border-radius: 0.3rem;
}
.number-input-box:focus {
    outline: none;
    border-color: var(--primary-dark);
    background: var(--light);
}
</style>

<h1 class="mb-2">📝 คีย์โพย - <?= escape($current_draw['name']) ?></h1>

<div class="grid grid-2" style="gap: 1rem;">
    <!-- Left Panel -->
    <div>
        <!-- Type Selection -->
        <div class="card mb-2">
            <div class="d-flex justify-center gap-3" style="padding: 0.5rem;">
                <?php foreach (LOTTERY_TYPES as $key => $type): ?>
                <button class="btn type-btn" data-type="<?= $key ?>">
                    <strong><?= escape($type['name']) ?></strong>
                    <div style="font-size: 0.75rem; margin-top: 0.2rem;">
                        เรท <?= rate_for($key, $current_draw_id, '') ?>
                    </div>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Number Entry -->
        <div class="card">
            <!-- Number Input Boxes -->
            <div class="number-inputs" id="number-boxes">
                <!-- Will be generated by JavaScript -->
            </div>
            <small class="text-muted d-block text-center mb-2">
                *พิมพ์หรือกดบนแป้นด้านล่าง
            </small>
            
            <!-- Amount Input -->
            <div class="d-flex justify-center mb-3">
                <div style="width: 150px;">
                    <input type="number" id="amount-input" class="form-control text-center" 
                           style="font-size: 1.5rem; font-weight: bold; height: 50px;"
                           placeholder="เงิน" min="1">
                </div>
            </div>
            
            <!-- Action Section -->
            <div class="action-section">
                <!-- Reverse Toggle & Add Button -->
                <div class="d-flex gap-2 justify-center mb-2">
                    <button class="btn reverse-btn" id="reverse-toggle" style="min-width: 130px;">
                        กลับ/สลับเลข
                    </button>
                    <button class="btn btn-success" id="add-btn" style="min-width: 130px;">
                        ➕ เพิ่มเลข
                    </button>
                </div>
                
                <!-- Quick Amounts -->
                <div class="d-flex gap-1 justify-center">
                    <?php foreach ([10, 20, 50, 100, 500, 1000] as $amt): ?>
                    <button class="btn btn-sm btn-outline quick-amt" data-amount="<?= $amt ?>">
                        <?= $amt ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Number Pad -->
            <div class="num-pad">
                <?php for ($i = 1; $i <= 9; $i++): ?>
                <button class="btn btn-outline num-btn" data-num="<?= $i ?>"><?= $i ?></button>
                <?php endfor; ?>
                <button class="btn btn-warning num-btn" id="clear-btn" style="font-size: 1rem;">เคลียร์</button>
                <button class="btn btn-outline num-btn" data-num="0">0</button>
                <button class="btn btn-danger num-btn" id="backspace-btn">⌫</button>
            </div>
        </div>
    </div>
    
    <!-- Right Panel: Cart -->
    <div>
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-between align-center">
                    <h3 class="card-title" style="margin: 0;">
                        🛒 รายการ <span class="badge badge-info" id="cart-count"><?= count($_SESSION['cart']) ?></span>
                    </h3>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-primary" id="set-price-btn">ราคาเดียว</button>
                        <button class="btn btn-sm btn-danger" id="clear-cart-btn">ล้าง</button>
                    </div>
                </div>
            </div>
            
            <div id="cart-items">
                <!-- Cart will be loaded by AJAX -->
                <div class="text-center text-muted p-3">กำลังโหลด...</div>
            </div>
            
            <div style="border-top: 2px solid var(--border); padding: 1rem;">
                <div class="d-flex justify-between align-center mb-2">
                    <h3 style="margin: 0;">รวม:</h3>
                    <h2 class="text-primary" style="margin: 0;">฿ <span id="cart-total">0.00</span></h2>
                </div>
                
                <input type="text" id="customer-name" class="form-control mb-2" 
                       placeholder="ชื่อลูกค้า (ไม่บังคับ)" style="font-size: 0.9rem;">
                
                <button class="btn btn-success btn-lg w-100" id="save-btn">
                    ✅ บันทึกโพย
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// State
let selectedType = null;
let reverseEnabled = false;
let currentInputIndex = 0;
let numberInputs = [];
let isAmountFocused = false;
// *** ไม่ต้องใช้ autoAddEnabled แล้ว ***

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadCart();
    setupEventHandlers();
    
    // Auto select first type
    document.querySelector('.type-btn')?.click();
});

function setupEventHandlers() {
    // Type buttons
    document.querySelectorAll('.type-btn').forEach(btn => {
        btn.addEventListener('click', selectType);
    });
    
    // Number pad
    document.querySelectorAll('.num-btn').forEach(btn => {
        btn.addEventListener('click', handleNumPad);
    });
    
    // Control buttons
    document.getElementById('clear-btn').addEventListener('click', clearNumber);
    document.getElementById('backspace-btn').addEventListener('click', backspaceNumber);
    
    // Reverse toggle - แบบ toggle ไม่ใช้ checkbox
    document.getElementById('reverse-toggle').addEventListener('click', function() {
        reverseEnabled = !reverseEnabled;
        if (reverseEnabled) {
            this.classList.add('active');
        } else {
            this.classList.remove('active');
        }
    });
    
    // Add button
    document.getElementById('add-btn').addEventListener('click', addToCart);
    
    // Amount input
    const amountInput = document.getElementById('amount-input');
    amountInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addToCart();
        }
    });
    
    // Track focus on amount input
    amountInput.addEventListener('focus', function() {
        isAmountFocused = true;
    });
    
    amountInput.addEventListener('blur', function() {
        isAmountFocused = false;
    });
    
    // *** ไม่ต้องมี event listener สำหรับ input ของ amountInput ***
    
    // Quick amounts - ยังคงเพิ่มอัตโนมัติเมื่อกด Quick Amount
    document.querySelectorAll('.quick-amt').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('amount-input').value = this.dataset.amount;
            document.getElementById('amount-input').focus();
            
            // เพิ่มอัตโนมัติเฉพาะเมื่อกด Quick Amount และเลขครบแล้ว
            const number = getNumber();
            const expectedLength = selectedType ? (selectedType.includes('3') ? 3 : 2) : 0;
            
            if (number.length === expectedLength) {
                setTimeout(() => addToCart(), 100); // delay นิดหน่อยให้ UI update ก่อน
            }
        });
    });
    
    // Cart buttons
    document.getElementById('set-price-btn').addEventListener('click', setAllPrice);
    document.getElementById('clear-cart-btn').addEventListener('click', clearCart);
    document.getElementById('save-btn').addEventListener('click', saveTicket);
}

function selectType(e) {
    const btn = e.currentTarget;
    selectedType = btn.dataset.type;
    
    // Update UI
    document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    // Create number input boxes based on type
    const numDigits = selectedType.includes('3') ? 3 : 2;
    createNumberInputs(numDigits);
    
    // Focus first input
    if (numberInputs[0]) {
        numberInputs[0].focus();
    }
}

function createNumberInputs(count) {
    const container = document.getElementById('number-boxes');
    container.innerHTML = '';
    numberInputs = [];
    currentInputIndex = 0;
    
    for (let i = 0; i < count; i++) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'number-input-box';
        input.maxLength = 1;
        input.dataset.index = i;
        
        // Handle input
        input.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value && i < count - 1) {
                numberInputs[i + 1].focus();
            } else if (this.value && i === count - 1) {
                // เลขครบแล้ว ไปที่ช่องเงิน
                document.getElementById('amount-input').focus();
            }
        });
        
        // Handle keyboard input
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && i > 0) {
                numberInputs[i - 1].focus();
            } else if (e.key >= '0' && e.key <= '9') {
                e.preventDefault();
                this.value = e.key;
                if (i < count - 1) {
                    numberInputs[i + 1].focus();
                } else {
                    document.getElementById('amount-input').focus();
                }
            }
        });
        
        // Handle focus
        input.addEventListener('focus', function() {
            currentInputIndex = i;
            isAmountFocused = false;
        });
        
        container.appendChild(input);
        numberInputs.push(input);
    }
}

function handleNumPad(e) {
    const num = e.currentTarget.dataset.num;
    if (!selectedType || num === undefined) return;
    
    // ถ้าโฟกัสอยู่ที่ช่องเงิน ให้พิมพ์ที่ช่องเงิน
    if (isAmountFocused) {
        const amountInput = document.getElementById('amount-input');
        amountInput.value += num;
        amountInput.focus();
        // *** ไม่เรียก checkAutoAdd() ***
        return;
    }
    
    // ถ้าไม่ได้โฟกัสที่ช่องเงิน ให้พิมพ์ที่ช่องเลข
    let targetInput = numberInputs[currentInputIndex];
    
    if (!targetInput) return;
    
    targetInput.value = num;
    
    // Move to next input
    if (currentInputIndex < numberInputs.length - 1) {
        currentInputIndex++;
        numberInputs[currentInputIndex].focus();
    } else {
        // เลขครบแล้ว ไปที่ช่องเงิน
        document.getElementById('amount-input').focus();
    }
}

function clearNumber() {
    numberInputs.forEach(input => input.value = '');
    currentInputIndex = 0;
    if (numberInputs[0]) {
        numberInputs[0].focus();
    }
}

// *** คง backspaceNumber ไว้เหมือนเดิม ***
function backspaceNumber() {
    // ถ้าโฟกัสอยู่ที่ช่องเงิน
    if (isAmountFocused) {
        const amountInput = document.getElementById('amount-input');
        amountInput.value = amountInput.value.slice(0, -1);
        return;
    }
    
    // ถ้าโฟกัสอยู่ที่ช่องเลข
    if (currentInputIndex >= 0 && numberInputs[currentInputIndex]) {
        if (numberInputs[currentInputIndex].value) {
            numberInputs[currentInputIndex].value = '';
        } else if (currentInputIndex > 0) {
            currentInputIndex--;
            numberInputs[currentInputIndex].value = '';
            numberInputs[currentInputIndex].focus();
        }
    }
}

function getNumber() {
    return numberInputs.map(input => input.value).join('');
}

// *** ไม่ต้องมี function checkAutoAdd แล้ว ***

function addToCart() {
    if (!selectedType) {
        Swal.fire('กรุณาเลือกประเภท', '', 'warning');
        return;
    }
    
    const number = getNumber();
    const amount = parseFloat(document.getElementById('amount-input').value) || 0;
    const expectedLength = selectedType.includes('3') ? 3 : 2;
    
    if (number.length !== expectedLength) {
        Swal.fire(`กรุณาใส่เลข ${expectedLength} หลัก`, '', 'warning');
        return;
    }
    
    if (amount <= 0) {
        Swal.fire('กรุณาใส่จำนวนเงิน', '', 'warning');
        return;
    }
    
    // Send AJAX
    const formData = new FormData();
    formData.append('action', 'add_to_cart');
    formData.append('type', selectedType);
    formData.append('number', number);
    formData.append('amount', amount);
    formData.append('with_reverse', reverseEnabled);
    
    fetch('?page=tickets', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            updateCartDisplay(data);
            
            // Clear inputs for next entry
            clearNumber();
            document.getElementById('amount-input').value = '';
            
            // Focus back to first number input
            if (numberInputs[0]) {
                numberInputs[0].focus();
            }
            
            // Show success
            Swal.fire({
                position: 'top-end',
                icon: 'success',
                title: data.message,
                showConfirmButton: false,
                timer: 800,
                toast: true
            });
            
            // Show errors if any
            if (data.errors && data.errors.length > 0) {
                setTimeout(() => {
                    Swal.fire('บางเลขเกินลิมิต', data.errors.join('\n'), 'warning');
                }, 1000);
            }
        } else {
            Swal.fire('ไม่สำเร็จ', data.message || 'เกิดข้อผิดพลาด', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเพิ่มเลขได้', 'error');
    });
}

// *** ส่วนที่เหลือคงเดิม (loadCart, updateCartDisplay, attachCartHandlers, etc.) ***
function loadCart() {
    fetch('?page=tickets', {
        method: 'POST',
        headers: { 
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=get_cart',
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) throw new Error('Network error');
        return response.json();
    })
    .then(data => {
        if (data.success) {
            updateCartDisplay(data);
        }
    })
    .catch(error => {
        console.error('Error loading cart:', error);
        document.getElementById('cart-items').innerHTML = '<div class="text-center text-muted p-3">เกิดข้อผิดพลาดในการโหลดตะกร้า</div>';
    });
}

function updateCartDisplay(data) {
    document.getElementById('cart-items').innerHTML = data.html || '<div class="text-center text-muted p-3">ยังไม่มีรายการ</div>';
    document.getElementById('cart-count').textContent = data.count || 0;
    document.getElementById('cart-total').textContent = parseFloat(data.total || 0).toFixed(2);
    
    // Re-attach handlers
    attachCartHandlers();
}

function attachCartHandlers() {
    // Amount inputs
    document.querySelectorAll('.amount-input').forEach(input => {
        input.addEventListener('change', function() {
            updateItemAmount(this.dataset.key, this.value);
        });
    });
    
    // Remove buttons
    document.querySelectorAll('.remove-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            removeItem(this.dataset.key);
        });
    });
}

function updateItemAmount(key, amount) {
    const formData = new FormData();
    formData.append('action', 'update_amount');
    formData.append('key', key);
    formData.append('amount', amount);
    
    fetch('?page=tickets', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartDisplay(data);
        } else {
            Swal.fire('ไม่สำเร็จ', data.message, 'error');
            loadCart(); // Reload cart to restore original values
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function removeItem(key) {
    const formData = new FormData();
    formData.append('action', 'remove_item');
    formData.append('key', key);
    
    fetch('?page=tickets', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartDisplay(data);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

function clearCart() {
    Swal.fire({
        title: 'ล้างรายการทั้งหมด?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'ล้าง',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'clear_cart');
            
            fetch('?page=tickets', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateCartDisplay(data);
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
    });
}

async function setAllPrice() {
    const count = parseInt(document.getElementById('cart-count').textContent);
    if (count === 0) {
        Swal.fire('ไม่มีรายการ', '', 'warning');
        return;
    }
    
    const { value: amount } = await Swal.fire({
        title: 'ตั้งราคาเดียวกันทุกเลข',
        input: 'number',
        inputLabel: 'จำนวนเงิน',
        inputAttributes: { min: 1, step: 1 },
        showCancelButton: true,
        confirmButtonText: 'ตั้งราคา',
        cancelButtonText: 'ยกเลิก'
    });
    
    if (amount && amount > 0) {
        const formData = new FormData();
        formData.append('action', 'set_all_price');
        formData.append('amount', amount);
        
        fetch('?page=tickets', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateCartDisplay(data);
                
                if (data.errors && data.errors.length > 0) {
                    Swal.fire('บางเลขเกินลิมิต', data.errors.join('\n'), 'warning');
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
}

// เพิ่มฟังก์ชัน resetForm() ใหม่
function resetForm() {
    // เคลียร์ cart โดยเรียก API
    const formData = new FormData();
    formData.append('action', 'clear_cart');
    
    fetch('?page=tickets', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData,
        credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            updateCartDisplay(data);
        }
    });
    
    // เคลียร์ number inputs
    clearNumber();
    
    // เคลียร์ amount input
    document.getElementById('amount-input').value = '';
    
    // เคลียร์ customer name
    document.getElementById('customer-name').value = '';
    
    // รีเซ็ต reverse toggle
    reverseEnabled = false;
    const reverseBtn = document.getElementById('reverse-toggle');
    if (reverseBtn) {
        reverseBtn.classList.remove('active');
    }
    
    // โฟกัสที่ช่องแรก
    if (numberInputs[0]) {
        numberInputs[0].focus();
    }
}

// แก้ไขฟังก์ชัน saveTicket()
function saveTicket() {
    const count = parseInt(document.getElementById('cart-count').textContent);
    if (count === 0) {
        Swal.fire('ไม่มีรายการ', '', 'warning');
        return;
    }
    
    const total = document.getElementById('cart-total').textContent;
    const customer = document.getElementById('customer-name').value;
    
    Swal.fire({
        title: 'ยืนยันการบันทึก?',
        html: `${count} รายการ<br>ยอดรวม ฿${total}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#22c55e',
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'save_ticket');
            formData.append('customer_name', customer);
            
            fetch('?page=tickets', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'บันทึกสำเร็จ!',
                        text: data.message,
                        showCancelButton: true,
                        confirmButtonText: 'คีย์ต่อ',
                        cancelButtonText: 'ดูโพย',
                        confirmButtonColor: '#22c55e',
                        cancelButtonColor: '#3b82f6'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // กดปุ่ม "คีย์ต่อ" - รีเซ็ตฟอร์ม
                            resetForm();
                        } else if (result.dismiss === Swal.DismissReason.cancel) {
                            // กดปุ่ม "ดูโพย"
                            window.location.href = '?page=orders';
                        } else {
                            // กด ESC หรือคลิกนอกกรอบ - รีเซ็ตฟอร์ม
                            resetForm();
                        }
                    });
                } else {
                    Swal.fire('ไม่สำเร็จ', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกได้', 'error');
            });
        }
    });
}
</script>