<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'session_guard.php';
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $emp_id = $user['employee_id'];
    $stmt = $db->prepare("SELECT password FROM users WHERE employee_id = ?");
    $stmt->execute([$emp_id]);
    $db_pass = $stmt->fetchColumn();

    if (!password_verify($current_password, $db_pass)) {
        $error = "รหัสผ่านปัจจุบันไม่ถูกต้อง";
    } elseif ($new_password !== $confirm_password) {
        $error = "รหัสผ่านใหม่และการยืนยันไม่ตรงกัน";
    } elseif (strlen($new_password) < 8 || !preg_match('/[A-Za-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $error = "รหัสผ่านใหม่ต้องมีความยาวอย่างน้อย 8 ตัวอักษร และประกอบด้วยตัวอักษรและตัวเลข";
    } else {
        $hashed = password_hash($new_password, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ? WHERE employee_id = ?")->execute([$hashed, $emp_id]);
        $success = "เปลี่ยนรหัสผ่านสำเร็จ";
    }
}
$active_menu = '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Change Password - Newsroom</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=6">
</head>
<body>
    <?php include 'top_menu.php'; ?>
    <div class="main-content" style="display:flex; justify-content:center; align-items:center; min-height:80vh;">
        <div class="card" style="width: 400px; padding: 30px;">
            <h2 style="margin-top:0;">เปลี่ยนรหัสผ่าน (Change Password)</h2>
            <?php if ($error): ?>
                <div style="color:var(--danger); background:rgba(255,71,87,0.1); padding:10px; border-radius:4px; margin-bottom:15px; border:1px solid var(--danger); text-align:center;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div style="color:#4caf50; background:rgba(76,175,80,0.1); padding:10px; border-radius:4px; margin-bottom:15px; border:1px solid #4caf50; text-align:center;">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>รหัสผ่านปัจจุบัน</label>
                    <input type="password" name="current_password" required class="form-control" style="width:100%; border:1px solid var(--border); padding:10px; background:var(--bg-dark); color:#fff; border-radius:4px;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>รหัสผ่านใหม่ (อักขระ + ตัวเลข, ขั้นต่ำ 8 ตัว)</label>
                    <input type="password" name="new_password" required class="form-control" style="width:100%; border:1px solid var(--border); padding:10px; background:var(--bg-dark); color:#fff; border-radius:4px;">
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>ยืนยันรหัสผ่านใหม่</label>
                    <input type="password" name="confirm_password" required class="form-control" style="width:100%; border:1px solid var(--border); padding:10px; background:var(--bg-dark); color:#fff; border-radius:4px;">
                </div>
                <button type="submit" style="width:100%; padding:12px; background:var(--accent); color:#000; border:none; border-radius:4px; font-weight:bold; cursor:pointer;">บันทึกรหัสผ่านใหม่</button>
            </form>
        </div>
    </div>
</body>
</html>

