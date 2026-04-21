<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Only role_id 3 (admin) and role_id 2 (Editor) can access Equipment Management 
// (or maybe just 3 based on top_menu.php line 29: if ($user['role_id'] == 3 || $user['role_id'] == 2) Admin drop down shows)
// Wait, top_menu.php shows "Department Management" only to role 3! Let's restrict Equipment to role 3 too.
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
    <title>Equipment Management - News Room</title>
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
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-active { background: #4caf5022; color: #4caf50; }
        .badge-inactive { background: #f4433622; color: #f44336; }

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
            <h2>Equipment Management</h2>
            <button class="btn btn-primary" onclick="openEquipmentForm()">+ Create New Equipment</button>
        </div>

        <table class="table-admin">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Equipment Name</th>
                    <th>Category</th>
                    <th>Total Units</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody id="equipments-body">
                <!-- Loaded via JS -->
            </tbody>
        </table>
    </div>

    <script>
        const windowUser = <?php echo json_encode($user, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const csrfToken = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        document.addEventListener('DOMContentLoaded', loadEquipments);
        
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

        async function loadEquipments() {
            try {
                // Call the existing get_equipment_master OR a new get_equipment_master_all (inc. inactive)
                const res = await fetch('api.php?action=get_equipment_master_all');
                const json = await res.json();
                
                const tbody = document.getElementById('equipments-body');
                tbody.innerHTML = '';
                
                if (json.success) {
                    json.data.forEach(d => {
                        const tr = document.createElement('tr');
                        const statusBadge = d.is_active == 1 
                            ? '<span class="badge badge-active">Active</span>'
                            : '<span class="badge badge-inactive">Inactive</span>';

                        tr.innerHTML = `
                            <td style="color:#888;">${escapeHTML(d.id)}</td>
                            <td style="font-weight: bold;">${escapeHTML(d.name)}</td>
                            <td>${escapeHTML(d.category || '-')}</td>
                            <td>${escapeHTML(d.total_units)}</td>
                            <td>${statusBadge}</td>
                            <td style="text-align: right;">
                                <button class="btn-sm btn-edit" onclick='openEquipmentForm(${JSON.stringify(d).replace(/'/g, "&apos;")})'>Edit</button>
                                <button class="btn-sm btn-delete" onclick='deleteEquipment(${d.id})'>Del</button>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function openEquipmentForm(eq = null) {
            const isEdit = !!eq;
            let dName = isEdit ? escapeHTML(eq.name) : '';
            let dCat = isEdit ? escapeHTML(eq.category || '') : '';
            let dQty = isEdit ? escapeHTML(eq.total_units) : '1';
            let dActive = isEdit ? (eq.is_active == 1 ? 'selected' : '') : 'selected';
            let dInactive = isEdit ? (eq.is_active == 0 ? 'selected' : '') : '';
            
            const { value: formValues } = await Swal.fire({
                title: isEdit ? 'Edit Equipment' : 'Create Equipment',
                html: `
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Equipment Name</label>
                    <input id="swal-eq-name" class="swal2-input" value="${dName}" style="width: 85%;">
                    
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Category</label>
                    <input id="swal-eq-cat" class="swal2-input" value="${dCat}" style="width: 85%;" placeholder="e.g. กล้อง, ไฟ, เสียง">
                    
                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Total Units</label>
                    <input id="swal-eq-qty" type="number" min="1" class="swal2-input" value="${dQty}" style="width: 85%;">

                    <label style="display:block; text-align:left; color:#ccc; font-size:12px; margin-top:10px;">Status</label>
                    <select id="swal-eq-status" class="swal2-input" style="width: 85%; background: #2a2a2a; color: #fff;">
                        <option value="1" ${dActive}>Active</option>
                        <option value="0" ${dInactive}>Inactive</option>
                    </select>
                `,
                focusConfirm: false,
                showCancelButton: true,
                preConfirm: () => {
                    const name = document.getElementById('swal-eq-name').value.trim();
                    const cat = document.getElementById('swal-eq-cat').value.trim();
                    const qty = document.getElementById('swal-eq-qty').value.trim();
                    const status = document.getElementById('swal-eq-status').value;

                    if (!name) {
                        Swal.showValidationMessage('Please provide an equipment name');
                        return false;
                    }
                    if (!qty || isNaN(qty) || parseInt(qty) < 1) {
                        Swal.showValidationMessage('Quantity must be at least 1');
                        return false;
                    }

                    return {
                        id: isEdit ? eq.id : null,
                        name: name,
                        category: cat,
                        total_units: parseInt(qty),
                        is_active: parseInt(status)
                    };
                }
            });

            if (formValues) {
                try {
                    const res = await fetch('api.php?action=save_equipment', {
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
                        loadEquipments();
                    } else {
                        Swal.fire('Error', json.error, 'error');
                    }
                } catch (e) {
                    Swal.fire('Error', 'Server Error', 'error');
                }
            }
        }
        
        async function deleteEquipment(id) {
            if (confirm("Are you sure you want to delete this equipment? This action cannot be undone.")) {
                try {
                    const res = await fetch('api.php?action=delete_equipment', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: csrfToken,
                            id: id
                        })
                    });
                    const json = await res.json();
                    if (json.success) {
                        loadEquipments();
                    } else {
                        Swal.fire('Error', json.error || 'Cannot delete item currently in use', 'error');
                    }
                } catch (e) {
                    Swal.fire('Error', 'Server Error', 'error');
                }
            }
        }
    </script>
</body>
</html>
