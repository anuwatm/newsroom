<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit;
}

$error = '';

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

        if ($user && password_verify($password, $user['password'])) {
            // Login success
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'employee_id' => $user['employee_id'],
                'full_name' => $user['full_name'],
                'role_id' => $user['role_id'],
                'role_name' => $user['role_name'],
                'department_id' => $user['department_id'],
                'department_name' => $user['department_name']
            ];
            header("Location: index.php");
            exit;
        } else {
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
