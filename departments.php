<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Only role_id 3 (admin) can access Department Management
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
    <title>Department Management - News Room</title>
    <link rel="stylesheet" href="style.css?v=6">
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

    <?php $active_menu = 'admin'; require_once 'top_menu.php'; ?>

    <div class="admin-app">
        <div class="admin-header">
            <h2>Department Management</h2>
            <button class="btn btn-primary" onclick="openDepartmentForm()">+ Create New Department</button>
        </div>

        <table class="table-admin">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Department Name</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="departments-body">
                <!-- Loaded via JS -->
            </tbody>
        </table>
    </div>

    <script>
        const windowUser = <?php echo json_encode($user); ?>;
        const csrfToken = <?php echo json_encode($csrf_token); ?>;

        document.addEventListener('DOMContentLoaded', loadDepartments);
        
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

        async function loadDepartments() {
            try {
                const res = await fetch('api.php?action=get_departments');
                const json = await res.json();
                
                const tbody = document.getElementById('departments-body');
                tbody.innerHTML = '';
                
                if (json.success) {
                    json.data.forEach(d => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td style="color:#888;">${escapeHTML(d.id)}</td>
                            <td style="font-weight: bold;">${escapeHTML(d.name)}</td>
                            <td style="text-align: right;">
                                <button class="btn-sm btn-edit" onclick='openDepartmentForm(${JSON.stringify(d).replace(/'/g, "&apos;")})'>Edit</button>
                                <button class="btn-sm btn-delete" onclick='deleteDepartment(${d.id})'>Del</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function openDepartmentForm(dept = null) {
            const isEdit = !!dept;
            let dName = isEdit ? escapeHTML(dept.name) : '';
            
            const { value: formValues } = await Swal.fire({
                title: isEdit ? 'Edit Department' : 'Create Department',
                html: `
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Department Name</label>
                    <input id="swal-d-name" class="swal2-input" value="${dName}">
                `,
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: () => {
                    const name = document.getElementById('swal-d-name').value.trim();

                    if (!name) {
                        Swal.showValidationMessage('Please provide a department name');
                        return false;
                    }

                    return {
                        id: isEdit ? dept.id : null,
                        name: name
                    };
                }
            });

            if (formValues) {
                try {
                    const res = await fetch('api.php?action=save_department', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            ...formValues
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        Swal.fire({ title: 'Saved!', icon: 'success', timer: 1500, showConfirmButton: false });
                        loadDepartments();
                    } else {
                        Swal.fire('Error', json.error, 'error');
                    }
                } catch (e) {
                    Swal.fire('Error', 'Server Error', 'error');
                }
            }
        }
        
        async function deleteDepartment(id) {
            if (confirm("Are you sure you want to delete this department? Operation will abort if users or stories still belong to it.")) {
                try {
                    const res = await fetch('api.php?action=delete_department', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            id: id
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        loadDepartments();
                    } else {
                        Swal.fire('Error', json.error, 'error');
                    }
                } catch (e) {}
            }
        }
    </script>
</body>
</html>
