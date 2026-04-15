<?php
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
            grid-template-columns: 1fr 2fr;
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
        
        @media (max-width: 768px) {
            .charts-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <?php $active_menu = 'admin'; require_once 'top_menu.php'; ?>

    <div class="admin-app">
        <div class="admin-header">
            <h2>Admin Dashboard</h2>
            <div style="color: #888; font-size: 14px;">Live System Overview</div>
        </div>

        <!-- KPI Cards -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="title">Total Active Stories</div>
                <div class="value" id="kpi-stories">0</div>
                <div class="subtext">Excludes deleted drafts</div>
            </div>
            <div class="stat-card">
                <div class="title">Active Users</div>
                <div class="value" id="kpi-users">0</div>
                <div class="subtext">Registered across departments</div>
            </div>
            <div class="stat-card">
                <div class="title">Scheduled Rundowns</div>
                <div class="value" id="kpi-rundowns">0</div>
                <div class="subtext">Queued in the system</div>
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
                <h3>Active Stories by Department</h3>
                <div style="position: relative; height: 250px; width: 100%;">
                    <canvas id="deptChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Lists -->
        <div class="list-card">
            <h3>Upcoming Broadcasts</h3>
            <div id="upcoming-list">
                <div style="padding: 20px; color: #888; text-align: center;">Loading upcoming broadcasts...</div>
            </div>
        </div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', loadDashboardData);

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

        // Chart defaults
        Chart.defaults.color = '#888';
        Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui';
        
        async function loadDashboardData() {
            try {
                const res = await fetch('api.php?action=get_dashboard_stats');
                const json = await res.json();
                
                if (json.success) {
                    const data = json.data;
                    
                    document.getElementById('kpi-stories').innerText = data.total_stories || 0;
                    document.getElementById('kpi-users').innerText = data.total_users || 0;
                    document.getElementById('kpi-rundowns').innerText = data.total_rundowns || 0;

                    renderStatusChart(data.status_counts);
                    renderDeptChart(data.dept_counts);
                    renderUpcomingRundowns(data.upcoming_rundowns);
                }
            } catch (e) {
                console.error("Dashboard error: ", e);
            }
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
                
                const durMin = Math.floor(parseInt(r.target_trt) / 60);

                item.innerHTML = `
                    <div>
                        <div class="list-item-title">${escapeHTML(r.name)}</div>
                        <div class="list-item-meta">Broadcast Time: ${formatThaiDate(r.broadcast_time)}</div>
                    </div>
                    <div>
                        <span style="display:inline-block; padding: 4px 10px; background: #333; border-radius: 20px; font-size: 12px; color: #ccc;">
                            ${durMin} นาที
                        </span>
                        <a href="rundown.php?id=${r.id}" class="btn-sm btn-edit" style="text-decoration:none; margin-left:12px;">Open</a>
                    </div>
                `;
                container.appendChild(item);
            });
        }
    </script>
</body>
</html>
