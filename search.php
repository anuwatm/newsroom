<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'session_guard.php';
require_once 'db.php';
// Basic protection
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user'];

// Optional: Handle API part here if action=search
if (isset($_GET['action']) && $_GET['action'] == 'search') {
    $q = trim($_GET['q'] ?? '');
    
    // Search across title and keywords
    $stmt = $db->prepare("SELECT id, title, status, author_id, updated_at FROM stories WHERE is_deleted=0 AND status != 'DRAFT' AND (title LIKE ? OR keywords LIKE ?) ORDER BY updated_at DESC");
    $stmt->execute(["%$q%", "%$q%"]);
    $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (isset($_GET['export']) && $_GET['export'] == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=search_results.csv');
        $output = fopen('php://output', 'w');
        fputcsv($output, array('ID', 'Title', 'Status', 'Author', 'Last Updated'));
        foreach ($stories as $st) {
            fputcsv($output, $st);
        }
        exit;
    }
    
    echo json_encode(['success' => true, 'data' => $stories]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Search Stories - News Room</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=6">
</head>
<body style="background-color: #1a1a1a; color: #fff; overflow-y: auto !important;">
    <?php $active_menu = 'search'; require_once 'top_menu.php'; ?>
    <div style="max-width: 1200px; margin: 40px auto; padding: 20px;">
        <h2 style="margin-bottom: 20px;">ค้นหาข่าว (Advanced Search)</h2>
        <div style="display:flex; gap:10px; margin-bottom: 30px;">
            <input type="text" id="search-input" class="form-control" placeholder="ค้นหาผ่านหัวข่าว หรือ Keyboard..." style="flex:1; background:#222; color:#fff; border:1px solid #444; padding:12px; border-radius:8px; font-size:16px;">
            <button id="btn-search" class="btn btn-primary" style="padding: 12px 24px; border-radius:8px;"><i class="fa-solid fa-search"></i> ค้นหา</button>
            <button id="btn-export" class="btn btn-secondary" style="padding: 12px 24px; border-radius:8px; background:#4caf50; color:#000; border:none;"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
        </div>
        
        <table style="width: 100%; border-collapse: collapse; background:#222; border-radius:8px; overflow:hidden;">
            <thead>
                <tr style="background:#333; text-align:left;">
                    <th style="padding: 15px;">#ID</th>
                    <th style="padding: 15px;">ชื่อเรื่อง</th>
                    <th style="padding: 15px;">ผู้แต่ง</th>
                    <th style="padding: 15px;">สถานะ</th>
                    <th style="padding: 15px;">อัพเดทล่าสุด</th>
                </tr>
            </thead>
            <tbody id="search-results">
                <tr><td colspan="5" style="padding:20px; text-align:center; color:#888;">พิมพ์เพื่อค้นหาข่าว...</td></tr>
            </tbody>
        </table>
    </div>

    <script>
        document.getElementById('btn-search').addEventListener('click', async () => {
            const q = document.getElementById('search-input').value;
            const res = await fetch('search.php?action=search&q=' + encodeURIComponent(q));
            const json = await res.json();
            const tbody = document.getElementById('search-results');
            tbody.innerHTML = '';
            
            if (!json.success || json.data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="padding:20px; text-align:center; color:#888;">ไม่พบข้อมูลข่าวที่ค้นหา</td></tr>';
                return;
            }
            
            json.data.forEach(s => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #333';
                tr.innerHTML = `
                    <td style="padding: 15px; color:#888;">${s.id}</td>
                    <td style="padding: 15px;"><a href="index.php?id=${s.id}" style="color:#2196f3; text-decoration:none; font-weight:bold;">${s.title}</a></td>
                    <td style="padding: 15px; color:#aaa;">${s.author_id}</td>
                    <td style="padding: 15px;"><span style="background:#444; padding:4px 8px; border-radius:4px; font-size:12px;">${s.status}</span></td>
                    <td style="padding: 15px; color:#888; font-size:13px;">${s.updated_at}</td>
                `;
                tbody.appendChild(tr);
            });
        });

        document.getElementById('btn-export').addEventListener('click', () => {
            const q = document.getElementById('search-input').value;
            window.location.href = 'search.php?action=search&export=csv&q=' + encodeURIComponent(q);
        });
    </script>
</body>
</html>

