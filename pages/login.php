<?php
// pages/login.php - หน้า Login

// ถ้า login แล้ว redirect ไป dashboard
if (current_user()) {
    safe_redirect('?page=dashboard');
    exit;
}

// Process login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($email && $password) {
        $db = DB::getInstance();
        $user = $db->fetch(
            "SELECT * FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );
        
        if ($user && check_password($password, $user['password_hash'])) {
            // Login สำเร็จ
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            
            safe_redirect('?page=dashboard');
            exit;
        } else {
            $error = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $error = 'กรุณากรอกข้อมูลให้ครบ';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - <?= escape(get_setting('site_name', 'ระบบคีย์หวย')) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .login-container {
            background: white;
            padding: 3rem;
            border-radius: 1rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-logo {
            width: 80px;
            height: 80px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
            color: white;
        }
        
        .login-title {
            font-size: 1.5rem;
            color: var(--secondary);
            margin-bottom: 0.5rem;
        }
        
        .login-subtitle {
            color: var(--gray);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="login-logo">🎯</div>
            <h1 class="login-title"><?= escape(get_setting('site_name', 'ระบบคีย์หวย')) ?></h1>
            <p class="login-subtitle">เข้าสู่ระบบเพื่อเริ่มใช้งาน</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?= escape($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">อีเมล</label>
                <input type="email" name="email" class="form-control" 
                       placeholder="your@email.com" required autofocus
                       value="<?= escape($_POST['email'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">รหัสผ่าน</label>
                <input type="password" name="password" class="form-control" 
                       placeholder="••••••••" required>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 btn-lg">
                เข้าสู่ระบบ
            </button>
        </form>
        
        <div class="text-center mt-3 text-muted">
            <!--<small>-->
            <!--    ////-->
            <!--</small>-->
        </div>
    </div>
</body>
</html>