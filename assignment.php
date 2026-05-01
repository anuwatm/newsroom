<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
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
    <title>Assignment - News Room</title>
    <link rel="stylesheet" href="style.css?v=4">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #1a1a1a; color: #fff; overflow-y: auto !important; }
        .assignment-app { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        .app-header { 
            background: rgba(26, 26, 26, 0.9); 
            backdrop-filter: blur(10px);
            padding: 16px 24px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.05); 
            display: flex; 
            justify-content: space-between; 
            position: sticky; top: 0; z-index: 100;
        }
        .nav-link { color: #fff; text-decoration: none; margin-right: 20px; font-weight: 500; transition: color 0.2s ease; position: relative; }
        .nav-link:hover { color: #4caf50; }
        .badge {
            background-color: #f44336; color: white; display: inline-block;
            border-radius: 10px; padding: 2px 6px; font-size: 10px;
            position: absolute; top: -8px; right: -12px; font-weight: bold;
            display: none;
        }

        .view-controls {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .tabs { display: flex; gap: 10px; }
        .tab-btn {
            background: #2a2a2a; border: 1px solid #444; color: #aaa;
            padding: 8px 16px; border-radius: 8px; cursor: pointer;
            font-size: 14px; font-family: 'Sarabun', sans-serif;
            transition: all 0.2s;
        }
        .tab-btn.active { background: #4caf50; color: #fff; border-color: #4caf50; }
        .tab-btn:hover:not(.active) { background: #333; color: #fff; }

        .floating-btn {
            background: #4caf50; color: #fff; border: none;
            padding: 10px 20px; border-radius: 20px; cursor: pointer;
            font-size: 15px; font-weight: 600; display: flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 10px rgba(76, 175, 80, 0.4);
        }
        .floating-btn:hover { background: #45a049; transform: translateY(-2px); }

        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .calendar-nav { display: flex; gap: 10px; align-items: center; }
        .calendar-nav button { background: #333; color: #fff; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; }
        .calendar-nav button:hover { background: #444; }
        .calendar-title { font-size: 20px; font-weight: 600; min-width: 150px; text-align: center; }

        .filters { display: flex; gap: 10px; }
        .filters select { background: #222; color: #fff; border: 1px solid #444; padding: 6px 12px; border-radius: 6px; outline: none;}

        .calendar-grid {
            display: grid; grid-template-columns: repeat(7, 1fr);
            gap: 1px; background: #333; border: 1px solid #444; border-radius: 8px; overflow: hidden;
        }
        .calendar-day-header { background: #222; text-align: center; padding: 10px; font-weight: 600; color: #aaa; }
        .calendar-cell { background: #1a1a1a; min-height: 120px; padding: 8px; display: flex; flex-direction: column; gap: 4px; border: 1px solid transparent;}
        .calendar-cell.other-month { background: #111; color: #555; }
        .calendar-cell.today { background: rgba(76, 175, 80, 0.1); }
        .calendar-cell:hover { border: 1px solid #4caf50; cursor: pointer; }
        .date-num { font-size: 14px; font-weight: bold; margin-bottom: 5px; display: flex; justify-content: space-between;}
        
        .event-chip {
            font-size: 11px; padding: 4px 6px; border-radius: 4px; color: #fff; cursor: pointer;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center; gap: 4px;
        }
        .status-PENDING { background-color: #ff9800; color: #000; }
        .status-APPROVED { background-color: #4caf50; }
        .status-REJECTED { background-color: #f44336; }
        .status-COMPLETED { background-color: #2196F3; color: #fff;}

        .list-view-controls { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .quick-filters { display: flex; background: #222; border-radius: 8px; overflow: hidden;}
        .quick-filters span { padding: 8px 16px; font-size: 14px; cursor: pointer; border-right: 1px solid #333; color: #aaa; transition: 0.2s;}
        .quick-filters span:last-child { border-right: none; }
        .quick-filters span:hover, .quick-filters span.active { background: #333; color: #fff; }

        table.data-table { width: 100%; border-collapse: collapse; background: #222; border-radius: 8px; overflow: hidden; }
        .data-table th { background: #1a1a1a; padding: 12px; text-align: left; font-size: 13px; color: #aaa; border-bottom: 1px solid #333; }
        .data-table td { padding: 12px; border-bottom: 1px solid #333; font-size: 14px; }
        .data-table tr:hover { background: #2a2a2a; cursor: pointer;}

        .btn-action { background: none; border: none; color: #aaa; cursor: pointer; padding: 6px; font-size: 16px;}
        .btn-action:hover { color: #fff; }

        .layout-with-sidebar { display: flex; gap: 20px; }
        .main-content { flex: 1; }
        .sidebar { width: 300px; background: #222; border-radius: 8px; padding: 20px; border: 1px solid #333; display: none; }

        /* Modal specific */
        .swal2-popup.swal-dark { background: #1e1e1e !important; color: #fff !important; }
        .swal2-title { color: #fff !important; }
        .swal-dynamic-form { display: flex; flex-direction: column; gap: 15px; text-align: left; max-height: 70vh; overflow-y: auto; padding-right: 10px;}
        .swal-dynamic-form label { font-size: 13px; color: #aaa; font-weight: bold; margin-bottom: 4px; display: block;}
        .swal-dynamic-form input, .swal-dynamic-form select, .swal-dynamic-form textarea { 
            width: 100%; background: #2a2a2a; border: 1px solid #444; color: #fff; padding: 10px; border-radius: 6px; outline: none; box-sizing: border-box;
        }
        .swal-dynamic-form input:focus, .swal-dynamic-form select:focus, .swal-dynamic-form textarea:focus { border-color: #4caf50; }
        .trip-row { background: #222; padding: 12px; border-radius: 8px; border: 1px solid #444; margin-bottom: 10px; position: relative;}
        .trip-row-remove { position: absolute; top: 10px; right: 10px; color: #f44336; cursor: pointer; }
        .eq-row { display: flex; align-items: center; justify-content: space-between; background: #222; padding: 8px; border-radius: 6px; margin-bottom: 6px;}
    </style>
</head>
<body>
    <?php $active_menu = 'assignment'; require_once 'top_menu.php'; ?>

    <div class="assignment-app">
        <div class="view-controls">
            <div class="tabs">
                <button class="tab-btn active" id="tab-calendar"><i class="fa-regular fa-calendar-days"></i> ปฏิทิน</button>
                <button class="tab-btn" id="tab-list"><i class="fa-solid fa-list-ul"></i> รายการหมาย</button>
            </div>
            <button class="floating-btn" id="btn-create"><i class="fa-solid fa-plus"></i> สร้างหมายข่าว</button>
        </div>

        <div class="layout-with-sidebar">
            <div class="main-content">
                <div id="view-calendar" style="display: block;">
                    <div class="calendar-header">
                        <div class="filters">
                            <select id="cal-filter-dept"><option value="">All Depts</option></select>
                            <select id="cal-filter-status">
                                <option value="">All Status</option>
                                <option value="PENDING">PENDING</option>
                                <option value="APPROVED">APPROVED</option>
                                <option value="REJECTED">REJECTED</option>
                                <option value="COMPLETED">COMPLETED</option>
                            </select>
                        </div>
                        <div class="calendar-nav">
                            <button id="cal-prev"><i class="fa-solid fa-chevron-left"></i></button>
                            <div class="calendar-title" id="cal-month-year">...</div>
                            <button id="cal-next"><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                    </div>
                    
                    <div class="calendar-grid">
                        <div class="calendar-day-header">Sun</div>
                        <div class="calendar-day-header">Mon</div>
                        <div class="calendar-day-header">Tue</div>
                        <div class="calendar-day-header">Wed</div>
                        <div class="calendar-day-header">Thu</div>
                        <div class="calendar-day-header">Fri</div>
                        <div class="calendar-day-header">Sat</div>
                    </div>
                    <div class="calendar-grid" id="calendar-grid" style="border-top:none; border-top-left-radius:0; border-top-right-radius:0;">
                    </div>
                </div>

                <div id="view-list" style="display: none;">
                    <div class="list-view-controls">
                        <div class="quick-filters" id="quick-filters">
                            <span data-status="" class="active">ทั้งหมด (<b id="count-all">0</b>)</span>
                            <span data-status="PENDING">รออนุมัติ (<b id="count-pending">0</b>)</span>
                            <span data-status="APPROVED">อนุมัติแล้ว (<b id="count-approved">0</b>)</span>
                            <span data-status="REJECTED">ไม่อนุมัติ (<b id="count-rejected">0</b>)</span>
                            <span data-status="COMPLETED">เสร็จแล้ว (<b id="count-completed">0</b>)</span>
                        </div>
                        <div class="filters" style="display:flex; gap:10px;">
                            <input type="text" id="list-search" placeholder="ค้นหาเรื่อง / สถานที่..." style="padding:8px; border-radius:6px; border:1px solid #444; background:#2a2a2a; color:#fff;">
                            <select id="list-filter-month" style="padding:8px; border-radius:6px; border:1px solid #444; background:#2a2a2a; color:#fff;"><option value="">All Months</option></select>
                            <select id="list-filter-dept" style="padding:8px; border-radius:6px; border:1px solid #444; background:#2a2a2a; color:#fff;"><option value="">All Depts</option></select>
                        </div>
                    </div>

                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>วันที่</th>
                                <th>ชื่อเรื่อง</th>
                                <th>นักข่าว</th>
                                <th>สถานะ</th>
                                <th>อุปกรณ์ที่เบิก</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="list-tbody">
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="sidebar" id="eq-sidebar">
                <h3 style="margin-top:0; border-bottom:1px solid #444; padding-bottom:10px;">Equipment Summary</h3>
                <div id="eq-summary-date" style="color: #4caf50; font-size: 14px; margin-bottom: 10px;">Select a date</div>
                <div id="eq-summary-content" style="font-size: 13px; line-height: 1.6; color: #ccc;">
                    Click a date in the calendar to view equipment usage.
                </div>
            </div>
        </div>
    </div>

    <script>
        window.ASSIGNMENT_ENV = {
            csrfToken: <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            roleId: <?php echo json_encode((int)$user['role_id']); ?>,
            employeeId: <?php echo json_encode($user['employee_id'] ?? $user['id'] ?? $user['full_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            fullName: <?php echo json_encode($user['full_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            departmentId: <?php echo json_encode((int)$user['department_id']); ?>
        };
    </script>
    <script type="module" src="js/assignment.js?v=1"></script>
</body>
</html>

