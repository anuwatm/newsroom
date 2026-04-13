<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Only role_id 3 (admin) can access Master Data
if ($user['role_id'] != 3) {
    echo "<h1>Permission Denied. Only Admin can access this page.</h1>";
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Data Admin - News Room</title>
    <link rel="stylesheet" href="style.css?v=5">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #1a1a1a; color: #fff; overflow-y: auto !important; }
        .admin-app { max-width: 900px; margin: 0 auto; padding: 40px 20px; }
        
        .admin-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #333;
        }

        .table-admin { width: 100%; border-collapse: collapse; background: #222; border-radius: 8px; overflow: hidden; }
        .table-admin th { background: #2a2a2a; text-align: left; padding: 16px; color: #aaa; font-size: 13px; text-transform: uppercase; }
        .table-admin td { padding: 16px; border-bottom: 1px solid #333; }
        
        .btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 4px; cursor: pointer; border: none; }
        .btn-edit { background: #4caf50; color: #fff; }
        .btn-delete { background: #f44336; color: #fff; }

        .app-header { background: #1a1a1a; padding: 16px 24px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; }
        .nav-link { color: #fff; text-decoration: none; margin-right: 20px; font-weight: 500; }
        .nav-link:hover { color: #4caf50; }
    </style>
</head>
<body>

    <div class="app-header">
        <div class="header-left" style="display: flex; align-items: center;">
            <div class="app-title" style="font-weight: 700; font-size: 18px; margin-right: 40px;">News Room</div>
            <nav class="top-nav" style="display: flex;">
                <div class="nav-item dropdown">
                    <span class="nav-link">Story ▾</span>
                    <div class="dropdown-menu">
                        <a href="index.php" id="nav-new-story">New Story</a>
                        <a href="index.php" id="nav-find-story">Find Story</a>
                        <a href="index.php" id="nav-my-story">My Story</a>
                    </div>
                </div>
                <div class="nav-item"><a href="rundown.php" class="nav-link">Rundown</a></div>
                <div class="nav-item"><a href="#" class="nav-link">Assignment</a></div>
                <div class="nav-item dropdown">
                    <span class="nav-link" style="color: #4caf50;">Admin ▾</span>
                    <div class="dropdown-menu">
                        <a href="admin.php">Program Data</a>
                        <a href="#">User Management</a>
                    </div>
                </div>
            </nav>
        </div>
        <div class="user-info-bar" style="display: flex; align-items: center; gap: 12px;">
            <div class="user-avatar" style="width: 32px; height: 32px; background: #333; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #fff;">
                <?php echo mb_substr($user['full_name'], 0, 1, 'UTF-8'); ?>
            </div>
            <div class="user-details" style="display: flex; flex-direction: column;">
                <div class="user-name" style="font-size: 14px; font-weight: 600; color: #fff;"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role" style="font-size: 12px; color: #aaa;"><?php echo htmlspecialchars($user['department_name'] . ' • ' . $user['role_name']); ?></div>
            </div>
            <a href="logout.php" class="btn-logout" title="Logout" style="margin-left: 16px; display: flex; align-items: center; gap: 6px; color: #f44336; text-decoration: none; font-size: 13px; font-weight: bold;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Sign Out
            </a>
        </div>
    </div>

    <div class="admin-app">
        <div class="admin-header">
            <h2>Programs Master Data</h2>
            <button class="btn btn-primary" onclick="openProgramForm()">+ Create New Program</button>
        </div>

        <table class="table-admin">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Program Name</th>
                    <th>Target Duration (Min)</th>
                    <th>Auto Commercial Breaks</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="programs-body">
                <!-- Loaded via JS -->
            </tbody>
        </table>
    </div>

    <script>
        const csrfToken = <?php echo json_encode($csrf_token); ?>;

        document.addEventListener('DOMContentLoaded', loadPrograms);
        
        const escapeHTML = (str) => {
            if (!str) return '';
            return String(str).replace(/[&<>'"]/g, 
                tag => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                }[tag] || tag)
            );
        };

        async function loadPrograms() {
            try {
                const res = await fetch('api.php?action=get_programs');
                const json = await res.json();
                
                const tbody = document.getElementById('programs-body');
                tbody.innerHTML = '';
                
                if (json.success) {
                    json.data.forEach(p => {
                        const tr = document.createElement('tr');
                        const durMin = Math.floor(parseInt(p.duration) / 60);
                        
                        tr.innerHTML = `
                            <td style="color:#888;">${p.id}</td>
                            <td style="font-weight: bold;">${escapeHTML(p.name)}</td>
                            <td>${durMin} นาที (${p.duration}s)</td>
                            <td>${p.break_count}</td>
                            <td style="text-align: right;">
                                <button class="btn-sm btn-edit" onclick='openProgramForm(${JSON.stringify(p).replace(/'/g, "&apos;")})'>Edit</button>
                                <button class="btn-sm btn-delete" onclick='deleteProgram(${p.id})'>Del</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function openProgramForm(program = null) {
            const isEdit = !!program;
            let pName = isEdit ? escapeHTML(program.name) : '';
            let pDur = isEdit ? Math.floor(parseInt(program.duration) / 60) : 30;
            let pBreak = isEdit ? program.break_count : 0;
            
            const { value: formValues } = await Swal.fire({
                title: isEdit ? 'Edit Program' : 'Create Program',
                html: `
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Program Name</label>
                    <input id="swal-p-name" class="swal2-input" value="${pName}">
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:20px;">Target Duration (Minutes)</label>
                    <input id="swal-p-dur" type="number" class="swal2-input" value="${pDur}">
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:20px;">Auto Add N Commercial Breaks</label>
                    <input id="swal-p-break" type="number" class="swal2-input" value="${pBreak}">
                `,
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: () => {
                    return {
                        id: isEdit ? program.id : null,
                        name: document.getElementById('swal-p-name').value,
                        duration: parseInt(document.getElementById('swal-p-dur').value) * 60,
                        break_count: parseInt(document.getElementById('swal-p-break').value)
                    };
                }
            });

            if (formValues && formValues.name) {
                try {
                    const res = await fetch('api.php?action=save_program', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            ...formValues
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        Swal.fire('Saved!', '', 'success');
                        loadPrograms();
                    } else {
                        Swal.fire('Error', json.error, 'error');
                    }
                } catch (e) {
                    Swal.fire('Error', 'Server Error', 'error');
                }
            }
        }
        
        async function deleteProgram(id) {
            if (confirm("Are you sure you want to delete this program Master Data? Historical records referencing this ID might lose linking info.")) {
                try {
                    const res = await fetch('api.php?action=delete_program', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            id: id
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        loadPrograms();
                    }
                } catch (e) {}
            }
        }
    </script>
</body>
</html>
