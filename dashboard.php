<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Only role_id 3 (admin) can access Dashboard
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
    <title>Admin Dashboard - News Room</title>
    <link rel="stylesheet" href="style.css?v=6">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #1a1a1a; color: #fff; overflow-y: auto !important; }
        .admin-app { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        
        .admin-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #333;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: #222;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #333;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
        }

        .stat-card .title { color: #888; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .stat-card .value { font-size: 36px; font-weight: bold; color: #fff; line-height: 1; }
        .stat-card .subtext { color: #555; font-size: 13px; margin-top: 8px; }

        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        .chart-card {
            background: #222;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #333;
        }

        .chart-card h3 {
            margin-top: 0; margin-bottom: 20px; color: #fff; font-size: 16px; font-weight: 500;
        }

        .list-card {
            background: #222;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid #333;
        }

        .list-card h3 { margin-top: 0; margin-bottom: 20px; color: #fff; font-size: 16px; font-weight: 500; }
        .list-item { padding: 12px 0; border-bottom: 1px solid #333; display: flex; justify-content: space-between; align-items: center; }
        .list-item:last-child { border-bottom: none; }
        .list-item-title { font-weight: bold; color: #fff; }
        .list-item-meta { color: #888; font-size: 13px; }

        .app-header { background: #1a1a1a; padding: 16px 24px; border-bottom: 1px solid #333; display: flex; justify-content: space-between; }
        .nav-link { color: #fff; text-decoration: none; margin-right: 20px; font-weight: 500; }
        .nav-link:hover { color: #4caf50; }
        
        .nav-tab { background: transparent; border: none; color: #888; font-size: 16px; padding: 12px 24px; cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -2px; font-weight: 500; font-family: inherit; transition: 0.3s; outline: none; }
        .nav-tab:hover { color: #fff; }
        .nav-tab.active { color: #4caf50; border-bottom-color: #4caf50; }
        
        @media (max-width: 768px) {
            .charts-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <?php $active_menu = 'admin'; require_once 'top_menu.php'; ?>

    <div class="admin-app">
        <div class="admin-header" style="margin-bottom: 0; border-bottom:none; padding-bottom:0;">
            <div>
                <h2 style="margin:0 0 8px 0;">Executive Dashboard</h2>
                <div style="color: #888; font-size: 14px;">Live System Overview</div>
            </div>
        </div>

        <div class="tabs-container" style="border-bottom: 1px solid #333; margin-bottom: 24px; margin-top: 16px; display:flex;">
            <button class="nav-tab active" id="tab-btn-live" onclick="switchTab('live')">
                <i class="fa-solid fa-satellite-dish"></i> Live Operations
            </button>
            <button class="nav-tab" id="tab-btn-kpi" onclick="switchTab('kpi')">
                <i class="fa-solid fa-chart-pie"></i> System KPI Summary
            </button>
        </div>

        <!-- ================= LIVE SECTION ================= -->
        <div id="section-live">
            <!-- Alerts -->
            <div id="bottleneck-alert" style="display:none; background: rgba(255, 152, 0, 0.1); border-left: 4px solid #ff9800; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
                <i class="fa-solid fa-triangle-exclamation" style="color:#ff9800; margin-right:8px;"></i> <b style="color:#ff9800;">Action Needed:</b> <span id="bottleneck-text"></span>
            </div>
            <div id="equipment-alert" style="display:none; background: rgba(244, 67, 54, 0.1); border-left: 4px solid #f44336; padding: 16px; margin-bottom: 24px; border-radius: 4px;">
                <i class="fa-solid fa-box-open" style="color:#f44336; margin-right:8px;"></i> <b style="color:#f44336;">Out of Stock Alerts:</b> <span id="equipment-text"></span>
            </div>

            <!-- Live Monitoring Station -->
            <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr; gap: 32px; min-height: 70vh;">
            <div class="stat-card" style="padding: 24px; display: flex; flex-direction: column;">
                <div class="title" style="color:#4caf50; font-size:18px; border-bottom:2px solid #333; padding-bottom:16px; margin-bottom:16px;"><i class="fa-solid fa-users"></i> Online Users</div>
                <div id="list-online" style="font-size:15px; margin-top:0; color:#aaa; flex-grow:1; overflow-y:auto; padding-right:8px; max-height: calc(35vh - 20px);">Loading...</div>
            </div>
            <div class="stat-card" style="padding: 24px; display: flex; flex-direction: column;">
                <div class="title" style="color:#ff9800; font-size:18px; border-bottom:2px solid #333; padding-bottom:16px; margin-bottom:16px;"><i class="fa-solid fa-pen-nib"></i> Active Stories</div>
                <div id="list-authors" style="font-size:15px; margin-top:0; color:#aaa; flex-grow:1; overflow-y:auto; padding-right:8px; max-height: calc(35vh - 20px);">Loading...</div>
            </div>
            <div class="stat-card" style="padding: 24px; display: flex; flex-direction: column;">
                <div class="title" style="color:#2196f3; font-size:18px; border-bottom:2px solid #333; padding-bottom:16px; margin-bottom:16px;"><i class="fa-solid fa-bars-staggered"></i> Active Rundowns</div>
                <div id="list-rundowns" style="font-size:15px; margin-top:0; color:#aaa; flex-grow:1; overflow-y:auto; padding-right:8px; max-height: calc(35vh - 20px);">Loading...</div>
            </div>
            <div class="stat-card" style="padding: 24px; display: flex; flex-direction: column;">
                <div class="title" style="color:#e91e63; font-size:18px; border-bottom:2px solid #333; padding-bottom:16px; margin-bottom:16px;"><i class="fa-solid fa-truck-fast"></i> Today's Field Teams</div>
                <div id="list-teams" style="font-size:15px; margin-top:0; color:#aaa; flex-grow:1; overflow-y:auto; padding-right:8px; max-height: calc(35vh - 20px);">Loading...</div>
            </div>
        </div>

        </div>
        </div> <!-- End Live Section -->

        <!-- ================= KPI SECTION ================= -->
        <div id="section-kpi" style="display:none;">
        <!-- KPI Cards -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="title">Total Active Stories</div>
                <div class="value" id="kpi-stories">0</div>
                <div class="subtext">Excludes deleted drafts</div>
            </div>
            <div class="stat-card">
                <div class="title">Total Assignments</div>
                <div class="value" id="kpi-assignments">0</div>
                <div class="subtext">Active dispatch log</div>
            </div>
            <div class="stat-card">
                <div class="title">Equipment Used</div>
                <div class="value" id="kpi-equipment">0 / 0</div>
                <div class="subtext">Units out from inventory</div>
            </div>
            <div class="stat-card">
                <div class="title">Registered Users</div>
                <div class="value" id="kpi-users">0</div>
                <div class="subtext">All departments combined</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-container">
            <div class="chart-card">
                <h3>Story Status Breakdown</h3>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>
            <div class="chart-card">
                <h3>Assignment Lifecycle</h3>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="assignmentChart"></canvas>
                </div>
            </div>
            <div class="chart-card" style="grid-column: 1 / -1;">
                <h3>Active Stories by Department</h3>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="deptChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Lists -->
        <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); margin-top:32px;">
            <div class="list-card">
                <h3>Upcoming Broadcasts <span style="font-size:12px; color:#888; float:right; font-weight:normal;">TRT vs Target</span></h3>
                <div id="upcoming-list">
                    <div style="padding: 20px; color: #888; text-align: center;">Loading upcoming broadcasts...</div>
                </div>
            </div>
            <div class="list-card">
                <h3>Top Reporters</h3>
                <div id="reporters-list">
                    <div style="padding: 20px; color: #888; text-align: center;">Loading reporters...</div>
                </div>
            </div>
            <div class="list-card">
                <h3>Recent Activity Feed</h3>
                <div id="activity-list">
                    <div style="padding: 20px; color: #888; text-align: center;">Loading activities...</div>
                </div>
            </div>
        </div>

        </div>
        </div> <!-- End KPI Section -->

    </div>

    <script>
        let currentTab = 'live';
        window.kpiLoaded = false;

        document.addEventListener('DOMContentLoaded', () => {
            pollDashboardLive();
            
            // Smart Polling Background Engine
            setInterval(() => {
                if (currentTab === 'live') {
                    pollDashboardLive();
                }
            }, 6000); // 6 seconds for optimal performance
        });

        function switchTab(target) {
            currentTab = target;
            document.querySelectorAll('.nav-tab').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-btn-' + target).classList.add('active');
            
            if (target === 'live') {
                document.getElementById('section-kpi').style.display = 'none';
                document.getElementById('section-live').style.display = 'block';
                pollDashboardLive();
            } else {
                document.getElementById('section-live').style.display = 'none';
                document.getElementById('section-kpi').style.display = 'block';
                if (!window.kpiLoaded) {
                    loadDashboardKPI();
                }
            }
        }

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

        const formatThaiDate = (dateStr) => {
            if (!dateStr) return '';
            const dTime = new Date(dateStr.replace(/-/g, "/"));
            const months = ["ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
            return `${dTime.getHours().toString().padStart(2, '0')}:${dTime.getMinutes().toString().padStart(2, '0')} น. — ${dTime.getDate()} ${months[dTime.getMonth()]} ${dTime.getFullYear() + 543}`;
        };

        // Chart instances
        let statusChartInst = null;
        let deptChartInst = null;
        let assignChartInst = null;

        // Chart defaults
        Chart.defaults.color = '#888';
        Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui';
        
        async function pollDashboardLive() {
            try {
                const res = await fetch('api.php?action=get_dashboard_live');
                const json = await res.json();
                if (json.success) {
                    renderAlerts(json.data);
                    renderLiveLists(json.data);
                }
            } catch (e) {
                console.error("Live Polling error: ", e);
            }
        }

        async function loadDashboardKPI() {
            try {
                const res = await fetch('api.php?action=get_dashboard_stats');
                const json = await res.json();
                
                if (json.success) {
                    const data = json.data;
                    window.kpiLoaded = true;
                    
                    document.getElementById('kpi-stories').innerText = data.total_stories || 0;
                    document.getElementById('kpi-users').innerText = data.total_users || 0;
                    document.getElementById('kpi-assignments').innerText = data.total_assignments || 0;
                    document.getElementById('kpi-equipment').innerText = `${data.borrowed_equipment || 0} / ${data.total_equipment || 0}`;

                    renderStatusChart(data.status_counts);
                    renderAssignmentChart(data.assignment_counts);
                    renderDeptChart(data.dept_counts);
                    renderUpcomingRundowns(data.upcoming_rundowns);
                    renderReporters(data.top_reporters);
                    renderActivity(data.recent_activity);
                }
            } catch (e) {
                console.error("Dashboard KPI error: ", e);
            }
        }

        function renderAlerts(data) {
            let bText = [];
            if (data.bottleneck_reviews > 0) bText.push(`${data.bottleneck_reviews} stories stuck in REVIEW`);
            if (data.bottleneck_assignments > 0) bText.push(`${data.bottleneck_assignments} assignments pending APPROVAL`);
            if (bText.length > 0) {
                document.getElementById('bottleneck-text').innerText = bText.join(' | ');
                document.getElementById('bottleneck-alert').style.display = 'block';
            }

            if (data.critical_equipment && data.critical_equipment.length > 0) {
                document.getElementById('equipment-text').innerText = data.critical_equipment.join(', ') + ' have 0 units available.';
                document.getElementById('equipment-alert').style.display = 'block';
            }
        }

        function renderLiveLists(data) {
            document.getElementById('list-online').innerHTML = !data.online_users || data.online_users.length === 0 ? '<div style="text-align:center; padding:20px;">No users online</div>' : 
                data.online_users.map(u => `<div style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #333; display:flex; justify-content:space-between; align-items:center;"><b style="color:#fff; font-size:16px;">${escapeHTML(u.full_name)}</b> <span style="color:#666; font-size:13px;">${escapeHTML(u.dept_name || 'Admin')}</span></div>`).join('');
            
            document.getElementById('list-authors').innerHTML = !data.active_stories || data.active_stories.length === 0 ? '<div style="text-align:center; padding:20px;">No active writers</div>' : 
                data.active_stories.map(s => `<div style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #333;"><b style="color:#fff; font-size:16px;">${escapeHTML(s.title || 'Untitled')}</b><br><span style="color:#ff9800; font-size:13px; margin-top:4px; display:inline-block;"><i class="fa-solid fa-lock" style="font-size:11px;"></i> Opened by: ${escapeHTML(s.editor)}</span></div>`).join('');
            
            document.getElementById('list-rundowns').innerHTML = !data.active_rundowns || data.active_rundowns.length === 0 ? '<div style="text-align:center; padding:20px;">No active rundowns</div>' : 
                data.active_rundowns.map(r => `<div style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #333;"><b style="color:#fff; font-size:16px;">${escapeHTML(r.title || 'Unnamed Rundown')}</b><br><span style="color:#2196f3; font-size:13px; margin-top:4px; display:inline-block;"><i class="fa-solid fa-eye" style="font-size:11px;"></i> Viewed by: ${escapeHTML(r.editor)}</span></div>`).join('');
            
            document.getElementById('list-teams').innerHTML = !data.today_assignments || data.today_assignments.length === 0 ? '<div style="text-align:center; padding:20px;">No field assignments today</div>' : 
                data.today_assignments.map(t => `<div style="margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid #333;"><b style="color:#fff; font-size:16px;">${escapeHTML(t.assignee)}</b><br><span style="color:#e91e63; font-size:13px; margin-top:4px; display:inline-block;"><i class="fa-solid fa-location-dot" style="font-size:11px;"></i> ${escapeHTML(t.location)} (${escapeHTML(t.time).substring(0,5)})</span></div>`).join('');
        }

        function renderStatusChart(statusMap) {
            const ctx = document.getElementById('statusChart').getContext('2d');
            if (statusChartInst) statusChartInst.destroy();

            const ObjectEntries = statusMap ? Object.entries(statusMap) : [];
            const labels = [];
            const dataCounts = [];
            let bgColors = [];

            const colorMap = {
                'DRAFT': '#555555',
                'READY': '#2196f3',
                'REVIEW': '#ff9800',
                'APPROVED': '#4caf50'
            };

            for (const [status, count] of ObjectEntries) {
                labels.push(status);
                dataCounts.push(count);
                bgColors.push(colorMap[status] || '#777');
            }

            if (labels.length === 0) {
                labels.push('No Data');
                dataCounts.push(1);
                bgColors.push('#333');
            }

            statusChartInst = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataCounts,
                        backgroundColor: bgColors,
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    },
                    cutout: '70%'
                }
            });
        }

        function renderAssignmentChart(statusMap) {
            const ctx = document.getElementById('assignmentChart').getContext('2d');
            if (assignChartInst) assignChartInst.destroy();

            const ObjectEntries = statusMap ? Object.entries(statusMap) : [];
            const labels = [];
            const dataCounts = [];
            let bgColors = [];

            const colorMap = {
                'PENDING': '#ff9800',
                'APPROVED': '#4caf50',
                'REJECTED': '#f44336'
            };

            for (const [status, count] of ObjectEntries) {
                labels.push(status);
                dataCounts.push(count);
                bgColors.push(colorMap[status] || '#777');
            }

            if (labels.length === 0) {
                labels.push('No Data');
                dataCounts.push(1);
                bgColors.push('#333');
            }

            assignChartInst = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataCounts,
                        backgroundColor: bgColors,
                        borderWidth: 0,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    },
                    cutout: '70%'
                }
            });
        }

        function renderDeptChart(deptMap) {
            const ctx = document.getElementById('deptChart').getContext('2d');
            if (deptChartInst) deptChartInst.destroy();

            const ObjectEntries = deptMap ? Object.entries(deptMap) : [];
            const labels = [];
            const dataCounts = [];

            for (const [dept, count] of ObjectEntries) {
                labels.push(dept || 'Unassigned');
                dataCounts.push(count);
            }

            if (labels.length === 0) {
                labels.push('No Data');
                dataCounts.push(0);
            }

            deptChartInst = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Active Stories',
                        data: dataCounts,
                        backgroundColor: '#4caf50',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#333' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }

        function renderUpcomingRundowns(rundowns) {
            const container = document.getElementById('upcoming-list');
            container.innerHTML = '';
            
            if (!rundowns || rundowns.length === 0) {
                container.innerHTML = '<div style="padding: 20px; color: #888; text-align: center;">No scheduled broadcasts found.</div>';
                return;
            }

            rundowns.forEach(r => {
                const item = document.createElement('div');
                item.className = 'list-item';
                
                const currMin = Math.ceil(parseInt(r.current_trt || 0) / 60);
                const tgtMin = Math.floor(parseInt(r.target_trt || 0) / 60);
                const color = currMin > tgtMin ? '#f44336' : (currMin < tgtMin - 5 ? '#ff9800' : '#4caf50');

                item.innerHTML = `
                    <div>
                        <div class="list-item-title">${escapeHTML(r.name)}</div>
                        <div class="list-item-meta">${formatThaiDate(r.broadcast_time)}</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="display:inline-block; padding: 4px 10px; background: #333; border-radius: 20px; font-size: 12px; color: ${color}; white-space:nowrap;">
                            ${currMin} / ${tgtMin} นาที
                        </div>
                        <div style="margin-top:4px;"><a href="rundown.php?id=${r.id}" style="font-size:12px; color:#2196f3; text-decoration:none;">Open</a></div>
                    </div>
                `;
                container.appendChild(item);
            });
        }

        function renderReporters(reporters) {
            const container = document.getElementById('reporters-list');
            container.innerHTML = '';
            if (!reporters || reporters.length === 0) return container.innerHTML = '<div style="padding:20px; text-align:center; color:#888;">No data</div>';
            
            reporters.forEach((r, i) => {
                const w = document.createElement('div');
                w.className = 'list-item';
                const rate = r.count > 0 ? Math.round((r.approved_count / r.count) * 100) : 0;
                w.innerHTML = `<div><span style="color:#555; font-weight:bold; margin-right:8px;">#${i+1}</span> <span style="color:#fff; font-weight:500;">${escapeHTML(r.author_id)}</span></div>
                               <div style="background:#333; padding:4px 10px; border-radius:12px; font-size:12px; color:#bbb; text-align:right;">
                                   <b>${r.count}</b> stories<br>
                                   <span style="color:#4caf50; font-size:10px;">${rate}% Approval | Avg: ${Math.round(r.avg_time)}s</span>
                               </div>`;
                container.appendChild(w);
            });
        }

        function renderActivity(activities) {
            const container = document.getElementById('activity-list');
            container.innerHTML = '';
            if (!activities || activities.length === 0) return container.innerHTML = '<div style="padding:20px; text-align:center; color:#888;">No data</div>';
            
            activities.forEach(a => {
                const w = document.createElement('div');
                w.className = 'list-item';
                w.style.flexDirection = 'column';
                w.style.alignItems = 'flex-start';
                
                const typeColor = a.type === 'Story' ? '#2196f3' : '#ff9800';
                
                w.innerHTML = `
                <div style="width:100%; display:flex; justify-content:space-between; align-items:center;">
                    <div style="font-size:12px; color:#aaa; margin-bottom:4px;">
                        <span style="color:${typeColor}; font-weight:600;">[${a.type}]</span> <i class="fa-regular fa-clock" style="margin-left:4px;"></i> ${formatThaiDate(a.timestamp)}
                    </div>
                    <div style="font-size:11px; background:#333; padding:2px 8px; border-radius:8px; color:#ccc;">${a.status}</div>
                </div>
                <div style="font-size:14px; color:#fff; font-weight:500; margin-top:4px;">${escapeHTML(a.title || 'Untitled')}</div>
                `;
                container.appendChild(w);
            });
        }
    </script>
</body>
</html>

