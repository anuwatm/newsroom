<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Only role_id 3 (admin) can access User Management
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
    <title>User Management - News Room</title>
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
            <h2>User Management</h2>
            <button class="btn btn-primary" onclick="openUserForm()">+ Create New User</button>
        </div>

        <table class="table-admin">
            <thead>
                <tr>
                    <th>Username (Employee ID)</th>
                    <th>Full Name</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="users-body">
                <!-- Loaded via JS -->
            </tbody>
        </table>
    </div>

    <script>
        const windowUser = <?php echo json_encode($user); ?>;
        const csrfToken = <?php echo json_encode($csrf_token); ?>;
        let rolesCache = [];
        let departmentsCache = [];

        document.addEventListener('DOMContentLoaded', async () => {
            await loadMetaData();
            loadUsers();
        });
        
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

        async function loadMetaData() {
            try {
                const resRoles = await fetch('api.php?action=get_roles');
                const jsonRoles = await resRoles.json();
                if (jsonRoles.success) rolesCache = jsonRoles.data;

                const resDepts = await fetch('api.php?action=get_departments');
                const jsonDepts = await resDepts.json();
                if (jsonDepts.success) departmentsCache = jsonDepts.data;
            } catch (e) {
                console.error(e);
            }
        }

        async function loadUsers() {
            try {
                const res = await fetch('api.php?action=get_all_users');
                const json = await res.json();
                
                const tbody = document.getElementById('users-body');
                tbody.innerHTML = '';
                
                if (json.success) {
                    json.data.forEach(u => {
                        const tr = document.createElement('tr');
                        const isSelf = u.employee_id === windowUser.employee_id || (windowUser.employee_id == null && u.employee_id === windowUser.full_name);
                        
                        tr.innerHTML = `
                            <td style="color:#888;">${escapeHTML(u.employee_id)}</td>
                            <td style="font-weight: bold;">${escapeHTML(u.full_name)}</td>
                            <td>${escapeHTML(u.role_name)}</td>
                            <td>${escapeHTML(u.department_name)}</td>
                            <td style="text-align: right;">
                                <button class="btn-sm btn-edit" onclick='openUserForm(${JSON.stringify(u).replace(/'/g, "&apos;")})'>Edit</button>
                                ${!isSelf ? `<button class="btn-sm btn-delete" onclick='deleteUser("${escapeHTML(u.employee_id)}")'>Del</button>` : `<span style="padding: 6px 12px; margin-left: 2px;">   </span>`}
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function openUserForm(user = null) {
            const isEdit = !!user;
            let uEmpId = isEdit ? escapeHTML(user.employee_id) : '';
            let uFullName = isEdit ? escapeHTML(user.full_name) : '';
            let uRoleId = isEdit ? user.role_id : '';
            let uDeptId = isEdit ? user.department_id : '';
            
            let rolesOpts = rolesCache.map(r => `<option value="${r.id}" ${uRoleId == r.id ? 'selected' : ''}>${escapeHTML(r.name)}</option>`).join('');
            let deptsOpts = departmentsCache.map(d => `<option value="${d.id}" ${uDeptId == d.id ? 'selected' : ''}>${escapeHTML(d.name)}</option>`).join('');

            const { value: formValues } = await Swal.fire({
                title: isEdit ? 'Edit User' : 'Create User',
                html: `
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Username (Employee ID)</label>
                    <input id="swal-u-empId" class="swal2-input" value="${uEmpId}" ${isEdit ? 'readonly style="background:#333; color:#aaa; cursor:not-allowed;"' : ''}>
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:15px;">Full Name</label>
                    <input id="swal-u-fullname" class="swal2-input" value="${uFullName}">
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:15px;">Password ${isEdit ? '<small style="color:#f44336;">(Leave blank to keep current)</small>' : ''}</label>
                    <input id="swal-u-password" type="password" class="swal2-input" placeholder="${isEdit ? 'Enter new password...' : 'New password...'}">
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:15px;">Role</label>
                    <select id="swal-u-role" class="swal2-input">
                        <option value="" disabled ${!isEdit ? 'selected' : ''}>Select Role</option>
                        ${rolesOpts}
                    </select>
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:15px;">Department</label>
                    <select id="swal-u-dept" class="swal2-input">
                        <option value="" disabled ${!isEdit ? 'selected' : ''}>Select Department</option>
                        ${deptsOpts}
                    </select>
                `,
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: () => {
                    const empId = document.getElementById('swal-u-empId').value.trim();
                    const fname = document.getElementById('swal-u-fullname').value.trim();
                    const pwd = document.getElementById('swal-u-password').value;
                    const role = document.getElementById('swal-u-role').value;
                    const dept = document.getElementById('swal-u-dept').value;

                    if (!empId || !fname || !role || !dept) {
                        Swal.showValidationMessage('Please fill all required fields');
                        return false;
                    }
                    if (!isEdit && !pwd) {
                        Swal.showValidationMessage('Password is required for new users');
                        return false;
                    }

                    return {
                        is_edit: isEdit,
                        employee_id: empId,
                        full_name: fname,
                        password: pwd,
                        role_id: parseInt(role),
                        department_id: parseInt(dept)
                    };
                }
            });

            if (formValues) {
                try {
                    const res = await fetch('api.php?action=save_user', {
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
                        loadUsers();
                    } else {
                        Swal.fire('Error', json.error, 'error');
                    }
                } catch (e) {
                    Swal.fire('Error', 'Server Error', 'error');
                }
            }
        }
        
        async function deleteUser(empId) {
            if (confirm("Are you sure you want to delete this user? Their historical data may remain orphaned.")) {
                try {
                    const res = await fetch('api.php?action=delete_user', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            employee_id: empId
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        loadUsers();
                    } else {
                        Swal.fire('Error', json.error, 'error');
                    }
                } catch (e) {}
            }
        }
    </script>
</body>
</html>
