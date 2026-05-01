<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'db.php';

if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$max_attempts = 5;
$lockout_time = 300; // 5 minutes

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $emp_id = trim($_POST['employee_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($emp_id) || empty($password)) {
        $error = "กรุณากรอกรหัสพนักงานและรหัสผ่าน";
    } else {
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, d.name as department_name 
            FROM users u
            JOIN roles r ON u.role_id = r.id
            JOIN departments d ON u.department_id = d.id
            WHERE u.employee_id = ?
        ");
        $stmt->execute([$emp_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Check lockout
            $locked_until = strtotime($user['locked_until'] ?? '0');
            if ($locked_until > time()) {
                $remaining = ceil(($locked_until - time()) / 60);
                $error = "คุณเข้าระบบผิดพลาดเกิน $max_attempts ครั้ง กรุณารอสักครู่ $remaining นาที แล้วลองใหม่";
                write_log('LOGIN_LOCKED', "Rejected attempt for locked user: {$emp_id}", 'WARNING');
            } else {
                if (password_verify($password, $user['password'])) {
                    // Login success
                    $db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE employee_id = ?")->execute([$emp_id]);
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'employee_id' => $user['employee_id'],
                        'full_name' => $user['full_name'],
                        'role_id' => $user['role_id'],
                        'role_name' => $user['role_name'],
                        'department_id' => $user['department_id'],
                        'department_name' => $user['department_name']
                    ];
                    $_SESSION['last_activity'] = time(); // For session timeout
                    
                    write_log('LOGIN_SUCCESS', "Logged in successfully to Role: " . $user['role_name']);

                    header("Location: index.php");
                    exit;
                } else {
                    $attempts = intval($user['login_attempts']) + 1;
                    if ($attempts >= $max_attempts) {
                        $lock_time = date('Y-m-d H:i:s', time() + $lockout_time);
                        $db->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE employee_id = ?")->execute([$attempts, $lock_time, $emp_id]);
                        write_log('LOGIN_LOCKOUT', "Account locked due to $max_attempts failed attempts for user: {$emp_id}", 'WARNING');
                        $error = "คุณเข้าระบบผิดพลาดเกิน $max_attempts ครั้ง กรุณารอสักครู่ 5 นาที แล้วลองใหม่";
                    } else {
                        $db->prepare("UPDATE users SET login_attempts = ? WHERE employee_id = ?")->execute([$attempts, $emp_id]);
                        write_log('LOGIN_FAILED', "Failed attempt ({$attempts}/$max_attempts) for user: {$emp_id}", 'WARNING');
                        $error = "รหัสพนักงานหรือรหัสผ่านไม่ถูกต้อง";
                    }
                }
            }
        } else {
            // User not found - simulate generic error
            write_log('LOGIN_FAILED', "Failed attempt for non-existent user: {$emp_id}", 'WARNING');
            $error = "รหัสพนักงานหรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Newsroom Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=6">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: var(--bg-dark);
            margin: 0;
            font-family: var(--font-thai);
            color: var(--text-primary);
        }
        .login-card {
            background: linear-gradient(145deg, #272727, #1a1a1a);
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7);
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .login-card h2 {
            margin-bottom: 24px;
            text-align: center;
            color: var(--accent);
            font-family: var(--font-ui);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
        }
        .form-control {
            width: 100%;
            padding: 12px;
            background-color: var(--bg-dark);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-primary);
            font-size: 16px;
            font-family: var(--font-ui);
            transition: border-color 0.2s;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--accent);
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background-color: var(--accent);
            color: #000;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            font-family: var(--font-thai);
        }
        .btn-login:hover {
            background-color: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px var(--accent-bg);
        }
        .error-msg {
            background-color: rgba(255, 71, 87, 0.1);
            color: var(--danger);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            border: 1px solid var(--danger);
        }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Newsroom Login</h2>
        <?php if (!empty($error)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="employee_id">รหัสพนักงาน (Username)</label>
                <input type="text" id="employee_id" name="employee_id" class="form-control" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">รหัสผ่าน (Password)</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn-login">เข้าสู่ระบบ</button>
        </form>
    </div>
</body>
</html>

