<?php
// lib/db.php - จัดการฐานข้อมูล SQLite (Fixed Version)

class DB {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $this->pdo = new PDO('sqlite:' . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // เปิด foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA cache_size = 10000');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            
            // สร้างตารางถ้ายังไม่มี
            $this->migrate();
            
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    // ฟังก์ชันช่วยสำหรับ query
    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }
    
    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_map(function($f) { return ':' . $f; }, $fields);
        
        $sql = "INSERT INTO $table (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $this->query($sql, $data);
        return $this->pdo->lastInsertId();
    }
    
    public function update($table, $data, $where, $whereParams = []) {
        // Build SET clause
        $sets = [];
        $allParams = [];
        
        foreach ($data as $field => $value) {
            $sets[] = "$field = ?";
            $allParams[] = $value;
        }
        
        // Add WHERE parameters
        foreach ($whereParams as $value) {
            $allParams[] = $value;
        }
        
        $sql = "UPDATE $table SET " . implode(',', $sets) . " WHERE $where";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($allParams);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Update SQL: " . $sql);
            error_log("Update Params: " . print_r($allParams, true));
            return 0;
        }
    }
    
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    // ฟังก์ชันใหม่: สร้าง ticket number สำหรับแต่ละงวด
    public function generateTicketNumber($draw_id) {
        // หา ticket_number สูงสุดของงวดนี้
        $result = $this->fetch("
            SELECT MAX(CAST(ticket_number AS INTEGER)) as max_num 
            FROM tickets 
            WHERE draw_id = ? AND ticket_number IS NOT NULL AND ticket_number > 0", 
            [$draw_id]);
        
        // ถ้าไม่มีเลย ให้เริ่มที่ 1, ถ้ามีแล้วให้ +1
        return ($result && $result['max_num']) ? ($result['max_num'] + 1) : 1;
    }
    
    // ฟังก์ชันใหม่: อัพเดทยอดรวมของ ticket
    public function updateTicketTotal($ticket_id) {
        $total = $this->fetch("
            SELECT COALESCE(SUM(amount), 0) as total 
            FROM ticket_lines 
            WHERE ticket_id = ?", [$ticket_id]);
        
        if ($total) {
            $this->update('tickets', 
                ['total_amount' => $total['total']],
                'id = ?', [$ticket_id]);
        }
        
        return $total ? $total['total'] : 0;
    }
    
    // Migration - สร้างตาราง
    private function migrate() {
        // ตาราง roles
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(50) NOT NULL UNIQUE,
                permissions_json TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // ตาราง users
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role_id INTEGER NOT NULL,
                commission_pct DECIMAL(5,2) DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (role_id) REFERENCES roles (id)
            )
        ");
        
        // ตาราง draws (งวด)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS draws (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(100) NOT NULL,
                draw_date DATE NOT NULL,
                status VARCHAR(20) DEFAULT 'open',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME
            )
        ");
        
        // ตาราง tickets (โพย) - ปรับ ticket_number ให้มี default 0
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                draw_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                ticket_number INTEGER DEFAULT 0,
                customer_name VARCHAR(100),
                total_amount DECIMAL(10,2) DEFAULT 0,
                paid_amount DECIMAL(10,2) DEFAULT 0,
                status VARCHAR(20) DEFAULT 'pending',
                payment_status VARCHAR(20) DEFAULT 'unpaid',
                is_winner INTEGER DEFAULT 0,
                win_amount DECIMAL(10,2) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME,
                updated_by INTEGER,
                FOREIGN KEY (draw_id) REFERENCES draws (id),
                FOREIGN KEY (user_id) REFERENCES users (id),
                FOREIGN KEY (updated_by) REFERENCES users (id)
            )
        ");
        
        // ตาราง ticket_lines (รายการในโพย)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ticket_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL,
                number VARCHAR(10) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                rate DECIMAL(10,2),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE
            )
        ");
        
        // ตาราง ticket_history สำหรับเก็บประวัติการแก้ไข
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS ticket_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id INTEGER NOT NULL,
                action VARCHAR(50),
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by INTEGER,
                FOREIGN KEY (ticket_id) REFERENCES tickets (id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users (id)
            )
        ");
        
        // ตาราง results (ผลรางวัล)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS results (
                draw_id INTEGER PRIMARY KEY,
                top6 VARCHAR(6),
                bottom2 VARCHAR(2),
                is_from_api INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_by INTEGER,
                FOREIGN KEY (draw_id) REFERENCES draws (id),
                FOREIGN KEY (updated_by) REFERENCES users (id)
            )
        ");
        
        // ตาราง limits_std (ลิมิตมาตรฐาน)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS limits_std (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                draw_id INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL,
                max_total DECIMAL(10,2),
                rate_override DECIMAL(10,2),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(draw_id, type),
                FOREIGN KEY (draw_id) REFERENCES draws (id)
            )
        ");
        
        // ตาราง limits_num (ลิมิตเฉพาะเลข)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS limits_num (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                draw_id INTEGER NOT NULL,
                type VARCHAR(20) NOT NULL,
                number VARCHAR(10) NOT NULL,
                max_total DECIMAL(10,2),
                rate_override DECIMAL(10,2),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(draw_id, type, number),
                FOREIGN KEY (draw_id) REFERENCES draws (id)
            )
        ");
        
        // ตาราง payments (การชำระเงิน)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                draw_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                type VARCHAR(20) DEFAULT 'payment',
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by INTEGER,
                FOREIGN KEY (draw_id) REFERENCES draws (id),
                FOREIGN KEY (user_id) REFERENCES users (id),
                FOREIGN KEY (created_by) REFERENCES users (id)
            )
        ");
        
        // ตาราง settings
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key VARCHAR(50) PRIMARY KEY,
                val TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // อัพเดท columns ถ้าตารางมีอยู่แล้ว
        $this->addColumnIfNotExists('tickets', 'ticket_number', 'INTEGER DEFAULT 0');
        $this->addColumnIfNotExists('tickets', 'payment_status', "VARCHAR(20) DEFAULT 'unpaid'");
        $this->addColumnIfNotExists('tickets', 'is_winner', 'INTEGER DEFAULT 0');
        $this->addColumnIfNotExists('tickets', 'win_amount', 'DECIMAL(10,2) DEFAULT 0');
        $this->addColumnIfNotExists('tickets', 'updated_at', 'DATETIME');
        $this->addColumnIfNotExists('tickets', 'updated_by', 'INTEGER');
        $this->addColumnIfNotExists('results', 'is_from_api', 'INTEGER DEFAULT 0');

        // แก้ไข ticket numbers ที่มีปัญหา
        $this->fixTicketNumbers();
        
        // สร้างข้อมูลเริ่มต้น
        $this->seedInitialData();
        
        // เพิ่ม Indexes สำหรับ Performance
        $this->createIndexes();
    }
    
private function createIndexes() {
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_tickets_draw_user ON tickets(draw_id, user_id)",
        "CREATE INDEX IF NOT EXISTS idx_tickets_draw_status ON tickets(draw_id, payment_status)",
        "CREATE INDEX IF NOT EXISTS idx_tickets_number ON tickets(draw_id, ticket_number)",
        "CREATE INDEX IF NOT EXISTS idx_ticket_lines_ticket ON ticket_lines(ticket_id)",
        "CREATE INDEX IF NOT EXISTS idx_ticket_lines_type_number ON ticket_lines(type, number)",
        "CREATE INDEX IF NOT EXISTS idx_ticket_lines_draw ON ticket_lines(ticket_id, type, number)", // เพิ่ม
        "CREATE INDEX IF NOT EXISTS idx_tickets_created ON tickets(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_draws_status ON draws(status)",
        "CREATE INDEX IF NOT EXISTS idx_results_draw ON results(draw_id)",
        "CREATE INDEX IF NOT EXISTS idx_limits_std_draw ON limits_std(draw_id, type)", // เพิ่ม
        "CREATE INDEX IF NOT EXISTS idx_limits_num_draw ON limits_num(draw_id, type, number)" // เพิ่ม
    ];
    
    foreach ($indexes as $sql) {
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            // Index might already exist
        }
    }
}
    
    // ฟังก์ชันใหม่: แก้ไข ticket numbers ที่มีปัญหา
    private function fixTicketNumbers() {
        try {
            // Get all draws
            $draws = $this->fetchAll("SELECT DISTINCT draw_id FROM tickets");
            
            foreach ($draws as $draw) {
                $draw_id = $draw['draw_id'];
                
                // Get tickets without proper number or with NULL/0
                $tickets = $this->fetchAll("
                    SELECT id FROM tickets 
                    WHERE draw_id = ? 
                    AND (ticket_number IS NULL OR ticket_number = 0 OR ticket_number = '')
                    ORDER BY created_at, id", [$draw_id]);
                
                if (!empty($tickets)) {
                    // Get max number for this draw
                    $max = $this->fetch("
                        SELECT MAX(CAST(ticket_number AS INTEGER)) as max_num 
                        FROM tickets 
                        WHERE draw_id = ? 
                        AND ticket_number IS NOT NULL 
                        AND ticket_number > 0", [$draw_id]);
                    
                    $next_num = ($max && $max['max_num']) ? ($max['max_num'] + 1) : 1;
                    
                    // Update each ticket
                    foreach ($tickets as $ticket) {
                        $this->update('tickets', 
                            ['ticket_number' => $next_num],
                            'id = ?', [$ticket['id']]);
                        $next_num++;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore errors during migration
        }
    }
    
    // Helper function to add column if not exists
    private function addColumnIfNotExists($table, $column, $definition) {
        try {
            $result = $this->pdo->query("PRAGMA table_info($table)");
            $columns = $result->fetchAll();
            $columnExists = false;
            
            foreach ($columns as $col) {
                if ($col['name'] == $column) {
                    $columnExists = true;
                    break;
                }
            }
            
            if (!$columnExists) {
                $this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
        } catch (PDOException $e) {
            // Column might already exist or table doesn't exist
        }
    }
    
    // ข้อมูลเริ่มต้น
    private function seedInitialData() {
        // เช็คว่ามี role Owner หรือยัง
        $ownerRole = $this->fetch("SELECT id FROM roles WHERE name = 'Owner'");
        if (!$ownerRole) {
            // สร้าง role Owner พร้อมสิทธิ์เต็ม
            $allPermissions = [];
            foreach (PERMISSIONS as $menu => $actions) {
                foreach ($actions as $action) {
                    $allPermissions[] = "$menu.$action";
                }
            }
            
            $roleId = $this->insert('roles', [
                'name' => 'Owner',
                'permissions_json' => json_encode($allPermissions)
            ]);
            
            // สร้าง role Seller
            $sellerPermissions = [
                'dashboard.view',
                'tickets.view', 'tickets.add',
                'orders.view',
                'numbers.view',
                'credits.view',
                'profile.view', 'profile.edit'
            ];
            $this->insert('roles', [
                'name' => 'Seller',
                'permissions_json' => json_encode($sellerPermissions)
            ]);
            
            // สร้าง user admin
            $this->insert('users', [
                'name' => 'Owner',
                'email' => 'owner@taidin.com',
                'password_hash' => password_hash('Owner@112233', PASSWORD_DEFAULT),
                'role_id' => $roleId,
                'commission_pct' => 0
            ]);
        }
        
        // ตั้งค่าเรท default
        $settings = [
            'rate_2_top' => '70',
            'rate_2_bottom' => '80',
            'rate_3_top' => '600',
            'site_name' => 'ระบบคีย์เลข',
            'commission_default' => '0'
        ];
        
        foreach ($settings as $key => $val) {
            $exists = $this->fetch("SELECT key FROM settings WHERE key = ?", [$key]);
            if (!$exists) {
                $this->insert('settings', ['key' => $key, 'val' => $val]);
            }
        }
        
        // สร้างงวดทดสอบถ้ายังไม่มี
        // $draw = $this->fetch("SELECT id FROM draws LIMIT 1");
        // if (!$draw) {
        //     $this->insert('draws', [
        //         'name' => 'งวด 1 ม.ค. 68',
        //         'draw_date' => date('Y-m-d'),
        //         'status' => 'open'
        //     ]);
        // }
    }
}

// สร้าง instance เมื่อโหลดไฟล์
$db = DB::getInstance();