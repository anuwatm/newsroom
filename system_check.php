<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();

// Database variables
$dbFile = __DIR__ . '/database/newsroom.sqlite';
$missingTables = [];
$requiredTables = ['roles', 'departments', 'users', 'stories', 'rundowns', 'rundown_stories', 'programs', 'assignments', 'assignment_trips', 'assignment_equipment', 'equipment_master'];

if (file_exists($dbFile)) {
    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($requiredTables as $t) {
            if (!in_array($t, $existingTables)) {
                $missingTables[] = $t;
            }
        }
    } catch (Exception $e) {}
} else {
    $missingTables = $requiredTables;
}

$checks = [
    'php_version' => [
        'name' => '1. PHP Version (>= 7.4)',
        'description' => 'ตรวจสอบเวอร์ชั่นของ PHP เพื่อให้รองรับฟีเจอร์ของระบบ',
        'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'fixable' => false,
        'manual_fix' => 'อัพเกรด PHP เป็น 7.4 ขึ้นไป ผ่านแผงควบคุมโฮสติ้ง หรือแก้ไข <code>php.ini</code> หรือใช้ XAMPP เวอร์ชั่นล่าสุด'
    ],
    'ext_pdo_sqlite' => [
        'name' => '2. PHP Extension: PDO SQLite',
        'description' => 'จำเป็นสำหรับการเชื่อมต่อและใช้งานฐานข้อมูล Newsroom',
        'status' => extension_loaded('pdo_sqlite'),
        'fixable' => false,
        'manual_fix' => 'เปิดใช้งาน extension ใน <code>php.ini</code> โดยหาบรรทัด <code>;extension=pdo_sqlite</code> และลบเครื่องหมายเซมิโคลอน (;) ด้านหน้าออก จากนั้น Restart Apache/Nginx'
    ],
    'ext_mbstring' => [
        'name' => '3. PHP Extension: MBString',
        'description' => 'จำเป็นสำหรับการจัดการข้อมูลภาษาไทย (UTF-8)',
        'status' => extension_loaded('mbstring'),
        'fixable' => false,
        'manual_fix' => 'เปิดใช้งาน extension ใน <code>php.ini</code> โดยหาบรรทัด <code>;extension=mbstring</code> และลบเครื่องหมาย (;) ด้านหน้าออก'
    ],
    'dir_database' => [
        'name' => '4. ไดเร็กทอรี: /database',
        'description' => 'ระบบต้องมีสิทธิ์สร้างและเขียนไฟล์ฐานข้อมูลในโฟลเดอร์นี้',
        'status' => is_dir(__DIR__ . '/database') && is_writable(__DIR__ . '/database'),
        'fixable' => true,
        'fix_action' => 'fix_dir_database',
        'manual_fix' => 'สร้างโฟลเดอร์ <code>database</code> และตั้งค่าสิทธิ์ให้สามารถเขียนได้ (chmod 777 หรือตั้ง owner เป็น www-data)'
    ],
    'dir_data_stories' => [
        'name' => '5. ไดเร็กทอรี: /data/stories',
        'description' => 'ใช้สำหรับเก็บไฟล์สคริปต์ข่าว (JSON Array) แบบ Hybrid Storage',
        'status' => is_dir(__DIR__ . '/data/stories') && is_writable(__DIR__ . '/data/stories'),
        'fixable' => true,
        'fix_action' => 'fix_dir_data',
        'manual_fix' => 'สร้างโฟลเดอร์ <code>data/stories</code> และตั้งค่าสิทธิ์ให้สามารถเขียนได้ (chmod -R 777 data)'
    ],
    'dir_data_log' => [
        'name' => '6. ไดเร็กทอรี: /data/log',
        'description' => 'โฟลเดอร์เก็บประวัติ Audit Logs (ประเมินสิทธิ์การเขียนและไฟล์ป้องกัน .htaccess)',
        'status' => is_dir(__DIR__ . '/data/log') && is_writable(__DIR__ . '/data/log') && file_exists(__DIR__ . '/data/log/.htaccess'),
        'fixable' => true,
        'fix_action' => 'fix_dir_log',
        'manual_fix' => 'สร้างโฟลเดอร์ <code>data/log</code> (chmod -R 777) และสร้างไฟล์ <code>.htaccess</code> ด้านในที่มีค่า <code>Deny from all</code>'
    ],
    'db_sqlite' => [
        'name' => '7. ฐานข้อมูล: newsroom.sqlite',
        'description' => 'ไฟล์ฐานข้อมูลหลักของระบบ และตารางข้อมูลเริ่มต้น (Seed Data)',
        'status' => file_exists($dbFile),
        'fixable' => true,
        'fix_action' => 'fix_db_init',
        'manual_fix' => 'ต้องสร้างไฟล์ฐานข้อมูลโดยใช้ปุ่ม Auto Fix เพื่อ generate ไฟล์'
    ],
    'db_tables' => [
        'name' => '8. ตารางในฐานข้อมูล (Tables)',
        'description' => 'ตรวจสอบตารางข้อมูลที่จำเป็น (' . implode(', ', $missingTables ?: ['Complete']) . ')',
        'status' => empty($missingTables) && file_exists($dbFile),
        'fixable' => true,
        'fix_action' => 'fix_db_tables',
        'manual_fix' => 'ไม่พบตารางข้อมูลที่ต้องการ กรุณากดปุ่ม Auto Fix เพื่อให้ระบบช่วยสร้างคำสั่ง CREATE TABLE'
    ]
];

// Handle AJAX Fix Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => 'Unknown action'];

    try {
        if ($action === 'fix_dir_database') {
            $path = __DIR__ . '/database';
            if (!is_dir($path)) @mkdir($path, 0755, true);
            if (!is_writable($path)) @chmod($path, 0777);
            $response = ['success' => is_writable($path), 'message' => is_writable($path) ? 'แก้ไขสำเร็จ' : 'แก้ไขไม่สำเร็จ กรุณาเช็ค Permission ด้วยตนเอง'];
        } 
        elseif ($action === 'fix_dir_data') {
            $path1 = __DIR__ . '/data';
            $path2 = __DIR__ . '/data/stories';
            if (!is_dir($path1)) @mkdir($path1, 0755, true);
            if (!is_dir($path2)) @mkdir($path2, 0755, true);
            if (!is_writable($path2)) { @chmod($path1, 0777); @chmod($path2, 0777); }
            $response = ['success' => is_writable($path2), 'message' => is_writable($path2) ? 'แก้ไขสำเร็จ' : 'แก้ไขไม่สำเร็จ เกิดปัญหา Permission Denied โปรดแก้ด้วยตนเอง'];
        } 
        elseif ($action === 'fix_dir_log') {
            $path1 = __DIR__ . '/data';
            $path2 = __DIR__ . '/data/log';
            $htaccess = $path2 . '/.htaccess';
            if (!is_dir($path1)) @mkdir($path1, 0755, true);
            if (!is_dir($path2)) @mkdir($path2, 0755, true);
            if (!is_writable($path2)) { @chmod($path1, 0777); @chmod($path2, 0777); }
            if (is_dir($path2) && !file_exists($htaccess)) {
                @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all");
            }
            $success = is_writable($path2) && file_exists($htaccess);
            $response = ['success' => $success, 'message' => $success ? 'สร้างโฟลเดอร์ Log และระบบป้องกันสำเร็จ' : 'แก้ไขไม่สำเร็จ กรุณาเช็ค Permission ด้วยตนเอง'];
        } 
        elseif ($action === 'fix_db_init') {
            ob_start();
            require_once 'db.php';
            ob_end_clean();
            $exists = file_exists($dbFile);
            $response = ['success' => $exists, 'message' => $exists ? 'สร้างและอัพเดทฐานข้อมูลเบื้องต้นสำเร็จ' : 'ไม่สามารถสร้างฐานข้อมูลได้'];
        }
        elseif ($action === 'fix_db_tables') {
            ob_start();
            require_once 'db.php';
            ob_end_clean();

            $db = new PDO("sqlite:" . $dbFile);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $queries = [
                "CREATE TABLE IF NOT EXISTS rundowns (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, broadcast_time DATETIME NOT NULL, target_trt INTEGER NOT NULL DEFAULT 0, is_locked INTEGER DEFAULT 0, created_by TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS rundown_stories (id INTEGER PRIMARY KEY AUTOINCREMENT, rundown_id INTEGER NOT NULL, story_id INTEGER NOT NULL, order_index INTEGER NOT NULL DEFAULT 0, is_dropped INTEGER DEFAULT 0, is_break INTEGER DEFAULT 0, break_duration INTEGER DEFAULT 0, FOREIGN KEY(rundown_id) REFERENCES rundowns(id) ON DELETE CASCADE, FOREIGN KEY(story_id) REFERENCES stories(id) ON DELETE CASCADE)",
                "CREATE TABLE IF NOT EXISTS programs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, duration INTEGER NOT NULL DEFAULT 0, break_count INTEGER NOT NULL DEFAULT 0)",
                "CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, description TEXT, reporter_id TEXT NOT NULL, reporter_name TEXT NOT NULL, department_id INTEGER NOT NULL, status TEXT DEFAULT 'PENDING', approved_by TEXT, approved_at DATETIME, rejection_note TEXT, created_by TEXT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
                "CREATE TABLE IF NOT EXISTS assignment_trips (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE, trip_date DATE NOT NULL, start_time TIME NOT NULL, end_time TIME, location_name TEXT NOT NULL, location_detail TEXT, order_index INTEGER DEFAULT 0)",
                "CREATE TABLE IF NOT EXISTS assignment_equipment (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE, equipment_name TEXT NOT NULL, quantity INTEGER DEFAULT 1, note TEXT)",
                "CREATE TABLE IF NOT EXISTS equipment_master (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, category TEXT, total_units INTEGER DEFAULT 1, is_active INTEGER DEFAULT 1)"
            ];

            foreach ($queries as $q) {
                try { $db->exec($q); } catch (Exception $e) {}
            }

            // Migrations (Alter table ignore errors if exists)
            $alters = [
                "ALTER TABLE rundowns ADD COLUMN program_id INTEGER",
                "ALTER TABLE stories ADD COLUMN current_version INTEGER DEFAULT 0",
                "ALTER TABLE stories ADD COLUMN keywords TEXT",
                "ALTER TABLE stories ADD COLUMN keyword_soundex TEXT",
                "ALTER TABLE stories ADD COLUMN is_deleted INTEGER DEFAULT 0",
                "ALTER TABLE stories ADD COLUMN locked_by TEXT",
                "ALTER TABLE stories ADD COLUMN locked_at DATETIME",
                "ALTER TABLE stories ADD COLUMN author_id TEXT"
            ];
            foreach ($alters as $q) {
                try { $db->exec($q); } catch (Exception $e) {}
            }
            try { $db->exec("UPDATE stories SET author_id = reporter WHERE author_id IS NULL"); } catch (Exception $e) {}

            $stmt = $db->query("SELECT COUNT(*) FROM equipment_master");
            if ($stmt->fetchColumn() == 0) {
                $db->exec("INSERT OR IGNORE INTO equipment_master (name, category, total_units, is_active) VALUES ('กล้องวิดีโอ ENG', 'กล้อง', 5, 1), ('ช่างกล้อง ENG', 'บุคลากร', 5, 1), ('ไมค์บูม', 'เสียง', 5, 1), ('ไมค์คลิป', 'เสียง', 5, 1), ('รถตู้ข่าว', 'ยานพาหนะ', 3, 1), ('ไฟ LED พกพา', 'แสง', 4, 1)");
            }

            $response = ['success' => true, 'message' => 'สร้างตารางและอัพเดท Schema เรียบร้อยแล้ว!'];
        }
    } catch (Throwable $e) {
        $response = ['success' => false, 'message' => 'เกิด Error: ' . $e->getMessage()];
    }
    
    echo json_encode($response);
    exit;
}

$allPassed = true;
foreach ($checks as $k => $c) {
    if (!$c['status']) $allPassed = false;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health Check - Newsroom</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { 
            background-color: #121212; 
            color: #e0e0e0; 
            font-family: 'Sarabun', sans-serif; 
            margin: 0; padding: 40px 20px;
        }
        .container { 
            max-width: 800px; 
            margin: 0 auto; 
            background: #1e1e1e; 
            border-radius: 12px; 
            border: 1px solid #333;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            overflow: hidden;
        }
        .header {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px 30px;
            border-bottom: 1px solid #333;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header h1 { margin: 0; font-size: 24px; color: #fff; }
        .overall-status {
            padding: 6px 12px; border-radius: 20px; font-size: 14px; font-weight: 600;
            opacity: 0; transition: opacity 0.5s ease;
        }
        .status-passed { background: rgba(76, 175, 80, 0.2); color: #4caf50; border: 1px solid rgba(76,175,80,0.3); }
        .status-failed { background: rgba(244, 67, 54, 0.2); color: #f44336; border: 1px solid rgba(244,67,54,0.3); }

        .check-list { padding: 30px; }
        .check-item {
            background: #252525;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            gap: 20px;
        }
        .check-icon {
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
        }
        .check-icon.success { color: #4caf50; }
        .check-icon.error { color: #f44336; }
        .check-icon.loading { color: #2196F3; }
        
        .check-content { flex: 1; }
        .check-title { font-size: 18px; font-weight: 600; color: #fff; margin-bottom: 5px; }
        .check-desc { font-size: 14px; color: #aaa; margin-bottom: 10px; line-height: 1.5; }
        
        .check-action-area { 
            background: #1a1a1a; padding: 12px 16px; border-radius: 6px; border: 1px solid #333; 
            display: none; flex-direction: column; gap: 10px; margin-top: 10px;
        }
        .manual-fix {
            font-size: 13px; color: #ffb74d; display: flex; gap: 8px; align-items: flex-start; line-height: 1.5;
        }
        .manual-fix code { background: #333; padding: 2px 6px; border-radius: 4px; color: #fff; }
        
        .btn-auto-fix {
            background: #2196F3; color: white; border: none; padding: 8px 16px; border-radius: 6px;
            cursor: pointer; font-family: inherit; font-weight: 600; font-size: 14px;
            display: inline-flex; align-items: center; gap: 8px; align-self: flex-start;
            transition: 0.2s;
        }
        .btn-auto-fix:hover { background: #1976D2; }
        
        .nav-links { text-align: center; margin-top: 30px; opacity: 0; transition: opacity 0.5s ease; }
        .nav-links a { 
            color: #bbb; text-decoration: none; padding: 10px 20px; 
            border: 1px solid #444; border-radius: 6px; font-size: 14px; background: #222; transition: 0.2s;
            display: inline-block;
        }
        .nav-links a:hover { background: #333; color: #fff; }
        .nav-links a.primary { background: #4caf50; border-color: #4caf50; color: #fff; }
        .nav-links a.primary:hover { background: #45a049; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1><i class="fa-solid fa-stethoscope" style="color: #2196F3; margin-right: 10px;"></i> System Validation</h1>
        <div class="overall-status <?php echo $allPassed ? 'status-passed' : 'status-failed'; ?>" id="overall-status">
            <?php echo $allPassed ? '<i class="fa-solid fa-check"></i> ระบบพร้อมใช้งาน 100%' : '<i class="fa-solid fa-triangle-exclamation"></i> พบปัญหาบางอย่าง'; ?>
        </div>
    </div>
    
    <div class="check-list" id="check-list">
        <?php foreach ($checks as $key => $check): ?>
            <div class="check-item" data-status="<?php echo $check['status'] ? 'success' : 'error'; ?>">
                <div class="check-icon loading">
                    <i class="fa-solid fa-circle-notch fa-spin"></i>
                </div>
                <div class="check-content">
                    <div class="check-title"><?php echo $check['name']; ?></div>
                    <div class="check-desc"><?php echo $check['description']; ?></div>
                    
                    <div class="check-action-area">
                        <?php if (!$check['status']): ?>
                            <?php if ($check['fixable']): ?>
                                <button class="btn-auto-fix" onclick="autoFix('<?php echo $check['fix_action']; ?>')">
                                    <i class="fa-solid fa-screwdriver-wrench"></i> Auto Fix (แก้ไขอัตโนมัติ)
                                </button>
                            <?php endif; ?>
                            
                            <div class="manual-fix">
                                <i class="fa-solid fa-circle-info" style="margin-top: 3px;"></i> 
                                <div>
                                    <b>แนวทางการแก้ไข: </b> <?php echo $check['manual_fix']; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="nav-links" id="nav-links">
            <?php if ($allPassed): ?>
                <a href="login.php" class="primary"><i class="fa-solid fa-rocket"></i> ระบบสมบูรณ์ เข้าสู่ระบบ</a>
            <?php else: ?>
                <a href="system_check.php"><i class="fa-solid fa-rotate-right"></i> รีเฟรชหน้าจอนี้เพื่อเช็คใหม่</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const items = document.querySelectorAll('.check-item');
    
    // Initial hide
    items.forEach(item => {
        item.style.opacity = '0';
        item.style.transform = 'translateY(15px)';
        item.style.transition = 'all 0.4s ease';
    });

    // Staggered Animation Sequence
    items.forEach((item, index) => {
        setTimeout(() => {
            // Fade in item with spinner
            item.style.opacity = '1';
            item.style.transform = 'translateY(0)';
            
            // Check status result
            const isSuccess = item.getAttribute('data-status') === 'success';
            
            setTimeout(() => {
                // Change icon
                const icon = item.querySelector('.check-icon');
                icon.className = 'check-icon ' + (isSuccess ? 'success' : 'error');
                icon.innerHTML = isSuccess ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-xmark"></i>';
                
                // Show action area if failed
                if (!isSuccess) {
                    const actionArea = item.querySelector('.check-action-area');
                    if (actionArea) actionArea.style.display = 'flex';
                }

                // If this is the last item, show the overall status and buttons
                if (index === items.length - 1) {
                    document.getElementById('overall-status').style.opacity = '1';
                    document.getElementById('nav-links').style.opacity = '1';
                }
            }, 600); // 600ms fake computing time per check
            
        }, index * 400); // Wait 400ms before starting each subsequent check
    });
});

async function autoFix(action) {
    if (!action) return;
    
    Swal.fire({
        title: 'กำลังดำเนินการ...',
        text: 'โปรดรอสักครู่ ระบบกำลังพยายามรันคำสั่งแก้ไขปัญหาเซิร์ฟเวอร์',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const formData = new FormData();
        formData.append('action', action);
        const res = await fetch('system_check.php', { method: 'POST', body: formData });
        const result = await res.json();
        
        if (result.success) {
            await Swal.fire({ icon: 'success', title: 'สำเร็จ!', text: result.message, timer: 1500, showConfirmButton: false });
            window.location.reload();
        } else {
            Swal.fire({ icon: 'error', title: 'แก้ไขอัตโนมัติล้มเหลว', text: result.message + '\n\nโปรดลองทำตาม "แนวทางการแก้ไข" ด้านล่างแทน' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Network Error', text: 'การเชื่อมต่อเซิร์ฟเวอร์ล้มเหลว: ' + e.message });
    }
}
</script>

</body>
</html>

