<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/functions.php';

$db = DB::getInstance();
$current_draw_id = $_SESSION['current_draw_id'] ?? draw_current_id();

echo "<h2>ตรวจสอบ Tickets ในงวด ID: $current_draw_id</h2>";

// Query เดียวกับในหน้า orders.php
$sql = "
    SELECT t.*, u.name as seller_name,
        (SELECT COUNT(id) FROM ticket_lines WHERE ticket_id = t.id) as line_count
    FROM tickets t
    JOIN users u ON u.id = t.user_id
    WHERE t.draw_id = ?
    ORDER BY t.user_id, t.created_at, t.id
";

$tickets = $db->fetchAll($sql, [$current_draw_id]);

echo "จำนวน tickets ที่ query ได้: " . count($tickets) . "<br><br>";

// แสดงข้อมูล
echo "<table border='1'>";
echo "<tr><th>Ticket ID</th><th>User ID</th><th>Seller</th><th>Created At</th></tr>";

$seen_ids = [];
$duplicates = [];

foreach ($tickets as $ticket) {
    $style = "";
    if (in_array($ticket['id'], $seen_ids)) {
        $style = "style='background-color: red; color: white;'";
        $duplicates[] = $ticket['id'];
    }
    $seen_ids[] = $ticket['id'];
    
    echo "<tr $style>";
    echo "<td>{$ticket['id']}</td>";
    echo "<td>{$ticket['user_id']}</td>";
    echo "<td>{$ticket['seller_name']}</td>";
    echo "<td>{$ticket['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

if (!empty($duplicates)) {
    echo "<br><strong>พบ Ticket ID ที่ซ้ำ:</strong> " . implode(', ', array_unique($duplicates));
} else {
    echo "<br><strong>ไม่พบข้อมูลซ้ำ</strong>";
}

// ตรวจสอบใน database โดยตรง
echo "<h3>ตรวจสอบจาก Database โดยตรง:</h3>";
$check = $db->fetchAll("
    SELECT id, COUNT(*) as count 
    FROM tickets 
    WHERE draw_id = ?
    GROUP BY id 
    HAVING count > 1
", [$current_draw_id]);

if (empty($check)) {
    echo "ไม่พบ ID ซ้ำใน database<br>";
} else {
    echo "พบ ID ซ้ำใน database:<br>";
    print_r($check);
}
?>