<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role_id'] != 3) {
    header("Location: index.php");
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
    <title>System Logs - News Room</title>
    <link rel="stylesheet" href="style.css?v=5">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #121212; color: #fff; overflow-y: auto !important; }
        .log-app { max-width: 1100px; margin: 0 auto; padding: 40px 20px; }
        
        .log-header {
            display: flex; justify-content: space-between; align-items: flex-end;
            margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #333;
        }
        .filter-group { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .log-select, .log-search {
            padding: 10px 15px; background: #222; border: 1px solid #444; color: #fff; 
            border-radius: 8px; font-family: inherit; font-size: 14px;
            outline: none; transition: border-color 0.2s;
        }
        .log-select:focus, .log-search:focus { border-color: #4caf50; }

        /* Custom Checkbox Dropdown */
        .chk-dropdown-wrapper { position: relative; display: inline-block; }
        .chk-dropdown-btn { 
            padding: 10px 15px; background: #222; border: 1px solid #444; color: #fff; 
            border-radius: 8px; font-family: inherit; font-size: 14px; cursor: pointer;
            user-select: none; display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .chk-dropdown-menu {
            display: none; position: absolute; top: calc(100% + 5px); left: 0; 
            background: #2a2a2a; border: 1px solid #444; border-radius: 8px; 
            padding: 15px; z-index: 1000; min-width: 220px; box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            flex-direction: column; gap: 10px;
        }
        .chk-dropdown-menu.show { display: flex; }
        .chk-dropdown-menu label {
            display: flex; align-items: center; gap: 12px; cursor: pointer; color: #ccc; font-size: 14px;
        }
        .chk-dropdown-menu input[type="checkbox"] { cursor: pointer; width: 16px; height: 16px; accent-color: #4caf50; }

        /* Unified Center Timeline */
        .timeline-container { position: relative; margin: 40px auto; padding: 0; box-sizing: border-box; }
        .timeline-container::after {
            content: ''; position: absolute; width: 4px; background: #333; top: 0; bottom: 0; left: 50%; margin-left: -2px;
        }
        
        /* Clearfix to contain floats */
        .timeline-container::after, .log-item::after { display: table; clear: both; }

        .log-item {
            padding: 15px 40px; position: relative; background: inherit; width: 50%; box-sizing: border-box;
            opacity: 0; transform: translateY(20px); transition: all 0.5s ease;
            display: flex; flex-direction: column;
        }
        .log-item.show { opacity: 1; transform: translateY(0); }
        
        .log-item.left { left: 0; align-items: flex-end; }
        .log-item.right { left: 50%; align-items: flex-start; }

        /* The Badge / Dot with Icon */
        .timeline-badge {
            position: absolute; top: 25px; width: 36px; height: 36px; border-radius: 50%;
            z-index: 10; display: flex; align-items: center; justify-content: center;
            background: #222; border: 4px solid #121212; font-size: 15px; color: #fff;
            box-shadow: 0 0 0 2px #333;
        }
        .log-item.left .timeline-badge { right: -18px; }
        .log-item.right .timeline-badge { left: -18px; }

        /* Colors based on level */
        .log-item.level-ERROR .timeline-badge { background: #f44336; box-shadow: 0 0 0 2px #f44336; border-color: #121212; }
        .log-item.level-WARNING .timeline-badge { background: #ff9800; box-shadow: 0 0 0 2px #ff9800; }
        .log-item.level-INFO .timeline-badge { background: #4caf50; box-shadow: 0 0 0 2px #4caf50; }

        /* The Card */
        .log-card {
            background: #1e1e1e; border: 1px solid #333; border-radius: 8px; padding: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.15); display: flex; flex-direction: column; gap: 10px;
            position: relative; width: 100%; max-width: 480px; box-sizing: border-box; transition: all 0.3s ease;
        }
        .log-card.suspicious { border-color: #f44336; box-shadow: 0 0 15px rgba(244, 67, 54, 0.4); background: #2a1111; }
        .log-card.new-ip { border-color: #ff9800; box-shadow: 0 0 15px rgba(255, 152, 0, 0.4); }

        /* Arrows pointing to the center */
        .log-item.left .log-card::after {
            content: " "; position: absolute; top: 15px; right: -9px;
            border-width: 9px 0 9px 9px; border-style: solid; border-color: transparent transparent transparent #1e1e1e; z-index: 2;
        }
        .log-item.left .log-card::before {
            content: " "; position: absolute; top: 14px; right: -10px;
            border-width: 10px 0 10px 10px; border-style: solid; border-color: transparent transparent transparent #333; z-index: 1;
        }
        .log-item.right .log-card::after {
            content: " "; position: absolute; top: 15px; left: -9px;
            border-width: 9px 9px 9px 0; border-style: solid; border-color: transparent #1e1e1e transparent transparent; z-index: 2;
        }
        .log-item.right .log-card::before {
            content: " "; position: absolute; top: 14px; left: -10px;
            border-width: 10px 10px 10px 0; border-style: solid; border-color: transparent #333 transparent transparent; z-index: 1;
        }

        .log-meta { display: flex; align-items: center; gap: 12px; font-size: 12px; color: #aaa; flex-wrap: wrap; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; text-transform: uppercase; }
        .badge-info { background: rgba(33, 150, 243, 0.1); color: #2196f3; }
        .badge-warning { background: rgba(255, 152, 0, 0.1); color: #ff9800; }
        .badge-error { background: rgba(244, 67, 54, 0.1); color: #f44336; }

        .log-user { color: #4caf50; font-weight: 500; }
        .log-time { color: #888; font-family: 'Inter', sans-serif; }
        .log-ip { color: #666; font-size: 11px; }

        .log-action { font-weight: 600; color: #fff; font-size: 15px; margin-top: 4px; }
        .log-details { color: #ccc; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
        
        .no-data { text-align: center; color: #666; padding: 40px; font-style: italic; width: 100%; clear: both; }

        /* Mobile specific adjustments */
        @media screen and (max-width: 768px) {
            .timeline-container::after { left: 31px; }
            .log-item { width: 100%; padding-left: 70px; padding-right: 10px; display: block; }
            .log-item.left, .log-item.right { left: 0; align-items: flex-start; }
            .log-item.left .timeline-badge, .log-item.right .timeline-badge { left: 13px; }
            .log-item.left .log-card::before { left: -10px; border-width: 10px 10px 10px 0; border-color: transparent #333 transparent transparent; }
            .log-item.left .log-card::after { left: -9px; border-width: 9px 9px 9px 0; border-color: transparent #1e1e1e transparent transparent; }
        }

        /* Top Navigation Header Styles */
        .app-header { background: #1a1a1a; padding: 16px 24px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; }
        .nav-link { color: #fff; text-decoration: none; margin-right: 20px; font-weight: 500; }
        .nav-link:hover { color: #4caf50; }
    </style>
</head>
<body>

    <?php $active_menu = 'admin'; require_once 'top_menu.php'; ?>

    <div class="log-app">
        <div class="log-header">
            <div>
                <h2 style="margin: 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-list-check" style="color: #4caf50;"></i> System Audit Logs
                </h2>
                <p style="color: #888; margin-top: 8px; font-size: 14px;">Monitor real-time system activities, event paths, and master data modifications chronologically.</p>
            </div>
            <div class="filter-group">
                <select id="file-select" class="log-select" style="min-width: 140px;" onchange="loadLogContent()"></select>
                
                <div class="chk-dropdown-wrapper">
                    <div class="chk-dropdown-btn" onclick="toggleDropdown('dd-level-menu')">Select Levels ▾</div>
                    <div class="chk-dropdown-menu" id="dd-level-menu">
                        <label><input type="checkbox" value="INFO" onchange="filterLogs()" checked> INFO Only</label>
                        <label><input type="checkbox" value="WARNING" onchange="filterLogs()" checked> WARNING Only</label>
                        <label><input type="checkbox" value="ERROR" onchange="filterLogs()" checked> ERROR / LOCKOUT</label>
                    </div>
                </div>

                <div class="chk-dropdown-wrapper">
                    <div class="chk-dropdown-btn" onclick="toggleDropdown('dd-module-menu')">Select Topics ▾</div>
                    <div class="chk-dropdown-menu" id="dd-module-menu">
                        <label><input type="checkbox" value="STORY" onchange="filterLogs()" checked> Story</label>
                        <label><input type="checkbox" value="RUNDOWN" onchange="filterLogs()" checked> Rundown</label>
                        <label><input type="checkbox" value="ASSIGNMENT" onchange="filterLogs()" checked> Assignment</label>
                        <label><input type="checkbox" value="USER" onchange="filterLogs()" checked> User / Auth</label>
                        <label><input type="checkbox" value="EQUIPMENT" onchange="filterLogs()" checked> Equipment</label>
                        <label><input type="checkbox" value="ADMIN" onchange="filterLogs()" checked> Master Data</label>
                    </div>
                </div>

                <input type="text" id="search-input" class="log-search" placeholder="Search logs/IPs/Users..." style="flex-grow: 1;" oninput="filterLogs()">
            </div>
        </div>

        <div style="background: #1e1e1e; border: 1px solid #333; border-radius: 8px; padding: 20px; margin-bottom: 30px;">
            <h3 style="margin-top:0; font-size:16px; color:#aaa;">Failed Login Attempts (per hour)</h3>
            <canvas id="failedLoginsChart" height="60"></canvas>
        </div>

        <div class="timeline-container" id="timeline">
            <div class="no-data">Loading logs...</div>
        </div>
    </div>

    <script>
        let allLogs = [];
        
        document.addEventListener('DOMContentLoaded', async () => {
            await loadLogFiles();
        });

        async function loadLogFiles() {
            try {
                const res = await fetch('api.php?action=get_log_files');
                const json = await res.json();
                if (json.success && json.data.length > 0) {
                    const select = document.getElementById('file-select');
                    select.innerHTML = json.data.map(f => `<option value="${f}">${f}</option>`).join('');
                    loadLogContent(); // Load content of the first file
                } else {
                    document.getElementById('timeline').innerHTML = `<div class="no-data">No log files found yet.</div>`;
                }
            } catch (e) {}
        }

        async function loadLogContent() {
            const filename = document.getElementById('file-select').value;
            if (!filename) return;

            document.getElementById('timeline').innerHTML = `<div class="no-data"><i class="fa-solid fa-spinner fa-spin"></i> Fetching logs from server...</div>`;

            try {
                const res = await fetch(`api.php?action=get_log_content&file=${encodeURIComponent(filename)}`);
                const json = await res.json();
                if (json.success) {
                    allLogs = json.data;
                    renderChart(allLogs);
                    renderLogs(allLogs);
                } else {
                    document.getElementById('timeline').innerHTML = `<div class="no-data" style="color:#f44336">${json.error}</div>`;
                }
            } catch(e) {}
        }

        let chartInstance = null;
        function renderChart(logs) {
            const failedLogins = logs.filter(L => L.action === 'FAILED_LOGIN' || L.action === 'LOCKOUT');
            const counts = {};
            failedLogins.forEach(L => {
                // time format is YYYY-MM-DD HH:MM:SS
                const hour = L.time.substring(0, 13) + ':00';
                counts[hour] = (counts[hour] || 0) + 1;
            });
            const labels = Object.keys(counts).sort();
            const data = labels.map(l => counts[l]);

            const ctx = document.getElementById('failedLoginsChart').getContext('2d');
            if (chartInstance) chartInstance.destroy();
            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Failed Logins',
                        data: data,
                        backgroundColor: 'rgba(244, 67, 54, 0.6)',
                        borderColor: '#f44336',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, ticks: { color: '#888', stepSize: 1 } }, x: { ticks: { color: '#888' } } },
                    plugins: { legend: { display: false } }
                }
            });
        }

        function toggleDropdown(id) {
            document.querySelectorAll('.chk-dropdown-menu').forEach(el => { if (el.id !== id) el.classList.remove('show'); });
            document.getElementById(id).classList.toggle('show');
        }
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.chk-dropdown-wrapper')) {
                document.querySelectorAll('.chk-dropdown-menu').forEach(el => el.classList.remove('show'));
            }
        });

        function filterLogs() {
            const term = document.getElementById('search-input').value.toLowerCase();
            const checkedLevels = Array.from(document.querySelectorAll('#dd-level-menu input:checked')).map(e => e.value);
            const checkedModules = Array.from(document.querySelectorAll('#dd-module-menu input:checked')).map(e => e.value);

            const filtered = allLogs.filter(L => {
                const searchStr = `${L.level} ${L.action} ${L.user} ${L.details} ${L.ip}`.toLowerCase();
                if (term && !searchStr.includes(term)) return false;

                let levelMatch = false;
                if (checkedLevels.includes('ERROR') && (L.level === 'ERROR' || L.level === 'LOCKOUT')) levelMatch = true;
                if (checkedLevels.includes(L.level)) levelMatch = true;
                if (!levelMatch) return false;

                let modMatch = false;
                const act = String(L.action).toUpperCase();
                if (checkedModules.includes('STORY') && (act.includes('STORY') || act.includes('BIN'))) modMatch = true;
                if (checkedModules.includes('RUNDOWN') && act.includes('RUNDOWN')) modMatch = true;
                if (checkedModules.includes('ASSIGNMENT') && act.includes('ASSIGN')) modMatch = true;
                if (checkedModules.includes('USER') && (act.includes('USER') || act.includes('LOG'))) modMatch = true;
                if (checkedModules.includes('EQUIPMENT') && (act.includes('EQUIPT') || act.includes('EQUIPMENT'))) modMatch = true;
                if (checkedModules.includes('ADMIN') && (act.includes('PROGRAM') || act.includes('DEPART'))) modMatch = true;
                
                if (!modMatch) {
                    if (checkedModules.length === 0) return false;
                    const isUncategorized = !(act.includes('STORY') || act.includes('BIN') || act.includes('RUNDOWN') || act.includes('ASSIGN') || act.includes('USER') || act.includes('LOG') || act.includes('EQUIPT') || act.includes('EQUIPMENT') || act.includes('PROGRAM') || act.includes('DEPART'));
                    if (!isUncategorized) return false;
                }

                return true;
            });
            renderLogs(filtered);
        }

        function renderLogs(logsArray) {
            const container = document.getElementById('timeline');
            container.innerHTML = '';

            if (logsArray.length === 0) {
                container.innerHTML = `<div class="no-data">No matching log entries found for this search.</div>`;
                return;
            }

            // Cap at 400 to prevent overloading DOM animations
            const displayLogs = logsArray.slice(0, 400);

            let html = '';
            displayLogs.forEach((L, index) => {
                // Determine Left (Error/Warning) or Right (Info)
                let alignClass = 'right';
                if (L.level === 'ERROR' || L.level === 'WARNING' || L.level === 'LOCKOUT') {
                    alignClass = 'left';
                }

                // Determine Badge Class
                let bagdeClass = 'badge-info';
                if (L.level === 'WARNING') bagdeClass = 'badge-warning';
                if (L.level === 'ERROR' || L.level === 'LOCKOUT') bagdeClass = 'badge-error';
                
                // Determine Icon based on Action Name
                let iconClass = 'fa-solid fa-circle-info';
                const act = String(L.action).toUpperCase();
                if (act.includes('STORY') || act.includes('BIN')) iconClass = 'fa-solid fa-file-lines';
                else if (act.includes('RUNDOWN')) iconClass = 'fa-solid fa-list-ol';
                else if (act.includes('ASSIGN')) iconClass = 'fa-solid fa-briefcase';
                else if (act.includes('USER') || act.includes('LOG')) iconClass = 'fa-solid fa-user';
                else if (act.includes('EQUIPT') || act.includes('EQUIPMENT')) iconClass = 'fa-solid fa-camera';
                else if (act.includes('PROGRAM') || act.includes('DEPART')) iconClass = 'fa-solid fa-building';

                let cardClass = '';
                let alertHtml = '';
                
                // Highlight Suspicious Actions
                if (act === 'RESTORE_STORY_VERSION' || act === 'DELETE_USER' || act === 'DELETE_STORY') {
                    cardClass = 'suspicious';
                    alertHtml = '<div style="color:#f44336; font-size:12px; font-weight:bold; margin-bottom:5px;"><i class="fa-solid fa-triangle-exclamation"></i> HIGH RISK ACTION</div>';
                }

                // New IP Detection for Login
                if (act === 'LOGIN_SUCCESS') {
                    // Check if this IP was seen for this user previously (in chronological order, i.e., from bottom of array to this index)
                    // Wait, logs are newest first. So we look at logs after this index.
                    const previousLogs = logsArray.slice(index + 1);
                    const ipSeenBefore = previousLogs.some(prev => prev.user === L.user && prev.ip === L.ip && prev.action === 'LOGIN_SUCCESS');
                    if (!ipSeenBefore && previousLogs.length > 0) { // Only flag if we have some history
                        cardClass = 'new-ip';
                        alertHtml = '<div style="color:#ff9800; font-size:12px; font-weight:bold; margin-bottom:5px;"><i class="fa-solid fa-location-crosshairs"></i> LOGIN FROM NEW IP</div>';
                    }
                }

                html += `
                <div class="log-item ${alignClass} level-${L.level}" id="log-item-${index}">
                    <div class="timeline-badge"><i class="${iconClass}"></i></div>
                    <div class="log-card ${cardClass}">
                        ${alertHtml}
                        <div class="log-meta">
                            <span class="badge ${bagdeClass}">${L.level}</span>
                            <span class="log-time"><i class="fa-regular fa-clock"></i> ${L.time}</span>
                            <span class="log-user"><i class="fa-solid fa-user-shield"></i> ${L.user}</span>
                            <span class="log-ip"><i class="fa-solid fa-network-wired"></i> ${L.ip}</span>
                        </div>
                        <div class="log-action">${L.action}</div>
                        <div class="log-details">${escapeHTML(L.details)}</div>
                    </div>
                </div>`;
            });

            container.innerHTML = html;

            // Trigger CSS Animations sequentially
            displayLogs.forEach((_, index) => {
                setTimeout(() => {
                    const el = document.getElementById(`log-item-${index}`);
                    if (el) el.classList.add('show');
                }, index * 20); // 20ms stagger cascade
            });
            
            if (logsArray.length > 400) {
                container.insertAdjacentHTML('beforeend', `<div class="no-data" style="margin-top:20px;">Showing top 400 entries. Please use search to filter.</div>`);
            }
        }

        const escapeHTML = (str) => {
            if (!str) return '';
            return String(str).replace(/[&<>'"]/g, 
                tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
            );
        };
    </script>
</body>
</html>

