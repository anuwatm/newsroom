<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
require_once 'db.php';
$id = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT a.*, d.name as department_name FROM assignments a LEFT JOIN departments d ON a.department_id = d.id WHERE a.id = ?");
$stmt->execute([$id]);
$ass = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ass) {
    die("Assignment not found.");
}

$user = $_SESSION['user'];
$role_id = intval($user['role_id']);
$user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];

if ($role_id == 1 || $role_id == 4) {
    if ($ass['reporter_id'] !== $user_emp_id && $ass['created_by'] !== $user_emp_id) {
        die("Unauthorized to print this assignment.");
    }
} elseif ($role_id == 2) {
    if ($ass['department_id'] != $user['department_id']) {
        die("Unauthorized to print this assignment.");
    }
}


$stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id = ? ORDER BY trip_date ASC, start_time ASC");
$stmtT->execute([$id]);
$trips = $stmtT->fetchAll(PDO::FETCH_ASSOC);

$stmtE = $db->prepare("SELECT * FROM assignment_equipment WHERE assignment_id = ?");
$stmtE->execute([$id]);
$equipment = $stmtE->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Print Assignment - <?php echo htmlspecialchars($ass['title']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fff; color: #000; padding: 20px; font-size: 14pt; }
        .print-container { max-width: 800px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 24pt; }
        .details-box { display: flex; flex-wrap: wrap; margin-bottom: 20px; }
        .details-box div { width: 50%; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        .signature-box { display: flex; justify-content: space-between; margin-top: 50px; }
        .signature { text-align: center; width: 40%; }
        .signature p { margin-top: 50px; border-top: 1px solid #000; padding-top: 5px; }
        @media print {
            body { padding: 0; }
            button { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="print-container">
        <div style="text-align: right; margin-bottom: 10px;">
            <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer; background:#4caf50; color:#fff; border:none; border-radius:4px;">🖨 พิมพ์</button>
        </div>
        <div class="header">
            <h1>ใบมอบหมายการทำข่าว</h1>
            <p>อัพเดทล่าสุด: <?php echo date('d/m/Y H:i', strtotime($ass['updated_at'])); ?></p>
        </div>

        <div class="details-box">
            <div style="width: 100%;"><b>ชื่อเรื่อง:</b> <?php echo htmlspecialchars($ass['title']); ?></div>
            <div><b>นักข่าว:</b> <?php echo htmlspecialchars($ass['reporter_name']); ?></div>
            <div><b>สังกัด:</b> <?php echo htmlspecialchars($ass['department_name']); ?></div>
            <div style="width: 100%;"><b>รายละเอียด:</b> <?php echo nl2br(htmlspecialchars($ass['description'] ?? '-')); ?></div>
            <div><b>สถานะ:</b> <?php echo htmlspecialchars($ass['status']); ?></div>
        </div>

        <h3>กำหนดการ</h3>
        <table>
            <thead>
                <tr>
                    <th>วันที่</th>
                    <th>เวลา</th>
                    <th>สถานที่</th>
                    <th>รายละเอียด</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($trips as $t): ?>
                <tr>
                    <td><?php echo htmlspecialchars($t['trip_date']); ?></td>
                    <td><?php echo htmlspecialchars($t['start_time'] . ' - ' . ($t['end_time'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars($t['location_name']); ?></td>
                    <td><?php echo htmlspecialchars($t['location_detail']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3>อุปกรณ์ที่เบิก</h3>
        <ul>
            <?php if(empty($equipment)): ?>
            <li>ไม่มีการเบิกอุปกรณ์</li>
            <?php else: ?>
                <?php foreach($equipment as $eq): ?>
                <li><?php echo htmlspecialchars($eq['equipment_name']) . ' x' . $eq['quantity'] . ' (' . htmlspecialchars($eq['note']) . ')'; ?></li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>

        <div class="signature-box">
            <div class="signature">
                <br>
                <p>ผู้รับมอบหมาย (<?php echo htmlspecialchars($ass['reporter_name']); ?>)</p>
            </div>
            <div class="signature">
                <br>
                <p>ผู้อนุมัติ (<?php echo htmlspecialchars($ass['approved_by'] ?? '...........................'); ?>)</p>
            </div>
        </div>
    </div>
</body>
</html>
