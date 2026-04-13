<?php
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
    <title>Rundown - News Room</title>
    <link rel="stylesheet" href="style.css?v=4">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #1a1a1a; color: #fff; overflow-y: auto !important; }
        .rundown-app { max-width: 1200px; margin: 0 auto; padding: 20px; }
        
        /* Dashboard Selection */
        .dashboard-select { text-align: center; margin-top: 50px; }
        
        /* Master Header Card */
        .master-header {
            background: linear-gradient(145deg, #272727, #1e1e1e);
            padding: 24px;
            border-radius: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .header-title { font-size: 26px; font-weight: 700; margin-bottom: 4px; letter-spacing: -0.5px; }
        .header-meta { font-size: 14px; color: #999; }
        .stats-boxes { display: flex; gap: 40px; }
        .stat-box { text-align: center; padding: 0 20px; border-left: 1px solid #444; }
        .stat-box:first-child { border-left: none; }
        .stat-label { font-size: 12px; font-weight: bold; color: #888; text-transform: uppercase; margin-bottom: 4px; }
        .stat-value { font-size: 28px; font-family: 'Inter', sans-serif; font-weight: 600; }
        .stat-sub { font-size: 13px; margin-top: 4px; }
        .val-red { color: #f44336; }
        .val-green { color: #4caf50; }
        .val-white { color: #fff; }

        /* Rundown Table */
        .table-header {
            display: grid;
            grid-template-columns: 40px 40px 300px 80px 150px 100px 80px 150px 120px;
            padding: 12px 16px;
            color: #888;
            font-size: 13px;
            font-weight: 600;
            border-bottom: 1px solid #333;
        }
        .table-row {
            display: grid;
            grid-template-columns: 40px 40px 300px 80px 150px 100px 80px 150px 120px;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            background-color: #222;
            transition: all 0.3s ease;
            margin-bottom: 4px;
            border-radius: 8px;
        }
        .table-row:hover { background-color: #2b2b2b; transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4); }
        .table-row.dropped { opacity: 0.5; }
        .table-row.dropped .headline-text { text-decoration: line-through; }
        
        .drag-handle { color: #555; cursor: grab; font-size: 20px; text-align: center; }
        .row-num { color: #888; font-weight: 600; text-align: center; }
        
        .headline-text { font-size: 16px; font-weight: 600; color: #fff; margin-bottom: 4px; }
        .headline-sub { font-size: 12px; color: #777; }
        
        .format-badge {
            background-color: #3f51b5; color: #fff; padding: 4px 10px; border-radius: 12px;
            font-size: 11px; font-weight: bold; text-align: center; display: inline-block;
        }
        .format-LIVE { background-color: #f44336; }
        .format-VO { background-color: #8bc34a; color: #000; }
        .format-SOT { background-color: #2196f3; }
        .format-PKG { background-color: #9c27b0; }

        .status-dot {
            width: 10px; height: 10px; border-radius: 50%; display: inline-block;
            background-color: #777;
        }
        .status-APPROVED { background-color: #4caf50; }
        .status-READY { background-color: #ff9800; }
        
        .trt-bar-container { display: flex; align-items: center; gap: 10px; }
        .trt-bar-bg { flex: 1; height: 4px; background: #444; border-radius: 2px; position: relative; }
        .trt-bar-fill { position: absolute; left: 0; top: 0; height: 100%; border-radius: 2px; }
        .trt-time { font-family: 'Inter', sans-serif; font-weight: 600; width: 45px; text-align: right; }

        .row-actions { display: flex; gap: 8px; justify-content: flex-end; }
        .btn-outline {
            background: transparent; border: 1px solid #555; color: #ccc;
            padding: 4px 12px; border-radius: 16px; font-size: 12px; cursor: pointer;
        }
        .btn-outline:hover { background: #333; color: #fff; }

        /* Draggable active */
        .dragging { opacity: 0.9; background-color: #3f3f3f; box-shadow: 0 8px 20px rgba(0,0,0,0.6); transform: scale(1.02); z-index: 10; }
        .over { border-top: 2px solid #4caf50; }

        /* Top Nav replication */
        .app-header { 
            background: rgba(26, 26, 26, 0.9); 
            backdrop-filter: blur(10px);
            padding: 16px 24px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.05); 
            display: flex; 
            justify-content: space-between; 
            position: sticky; top: 0; z-index: 100;
        }
        .nav-link { color: #fff; text-decoration: none; margin-right: 20px; font-weight: 500; transition: color 0.2s ease; }
        .nav-link:hover { color: #4caf50; }
        /* Premium Search Modal */
        .search-modal-box {
            background: linear-gradient(145deg, #272727, #1a1a1a);
            border-radius: 16px;
            max-width: 600px;
            width: 90%;
            padding: 30px;
            margin: 100px auto;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7);
        }
        .premium-search-input {
            width: 100%;
            background-color: #1a1a1a;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 16px;
            font-family: inherit;
            outline: none;
            transition: all 0.3s ease;
            box-sizing: border-box;
            margin-bottom: 24px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.5);
        }
        .premium-search-input:focus {
            border-color: #4caf50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2), inset 0 2px 4px rgba(0,0,0,0.5);
            background-color: #222;
        }
        .search-modal-results {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            background: #111;
            box-shadow: inset 0 4px 10px rgba(0,0,0,0.6);
        }
    </style>
</head>
<body>

    <!-- App Header -->
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
                <div class="nav-item"><a href="rundown.php" class="nav-link" style="color: #4caf50;">Rundown</a></div>
                <div class="nav-item"><a href="#" class="nav-link">Assignment</a></div>
                <div class="nav-item dropdown">
                    <span class="nav-link">Admin ▾</span>
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

    <div class="rundown-app">

        <!-- Splash / Selection Screen -->
        <div id="selection-screen" class="dashboard-select" style="display: none;">
            <h2>Rundown Dashboard</h2>
            <div style="margin: 30px 0;">
                <?php if ($user['role_id'] == 3): ?>
                    <button id="btn-create-new" class="btn btn-primary" style="padding: 12px 24px; font-size: 16px;">+ Create New Rundown</button>
                <?php else: ?>
                    <p style="color: #888;">Select an active rundown below to view. (Only Admin can create rundowns).</p>
                <?php endif; ?>
            </div>
            
            <table style="width: 100%; max-width: 800px; margin: 0 auto; text-align: left; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 1px solid #444; color: #888;">
                        <th style="padding: 12px;">Program Name</th>
                        <th style="padding: 12px;">Broadcast Target</th>
                        <th style="padding: 12px;">TRT Target</th>
                        <th style="padding: 12px;">Action</th>
                    </tr>
                </thead>
                <tbody id="rundown-list-body">
                    <!-- Loaded dynamically -->
                </tbody>
            </table>
        </div>

        <!-- Active Rundown Board -->
        <div id="board-screen" style="display: none;">
            <div class="master-header">
                <div>
                    <div id="rd-title" class="header-title">Loading...</div>
                    <div class="header-meta">Administrator · <span id="rd-story-count">0</span> stories</div>
                </div>
                
                <div class="stats-boxes">
                    <div class="stat-box">
                        <div class="stat-label">Total TRT</div>
                        <div id="rd-trt-value" class="stat-value val-white">00:00</div>
                        <div id="rd-trt-sub" class="stat-sub val-red">...</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">ออกอากาศใน</div>
                        <div id="rd-countdown" class="stat-value val-white">--:--</div>
                        <div class="stat-sub val-white">countdown</div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 12px; align-items: center;">
                    <?php if ($user['role_id'] == 3): ?>
                        <button id="btn-add-story" title="Add Story" class="btn btn-primary" style="background:#4caf50; border:none; padding:10px 16px; border-radius:8px;"><i class="fa-solid fa-plus"></i></button>
                        <button id="btn-add-break" title="Add Commercial Break" class="btn btn-secondary" style="background:#555; border:none; padding:10px 16px; border-radius:8px;"><i class="fa-solid fa-film"></i></button>
                        <button id="btn-lock-board" title="Lock Rundown" class="btn btn-secondary" style="padding:10px 16px; border-radius:8px;"><i class="fa-solid fa-lock"></i></button>
                    <?php endif; ?>
                    <button class="btn btn-secondary" title="Print Rundown" style="padding:10px 16px; border-radius:8px;"><i class="fa-solid fa-print"></i></button>
                </div>
            </div>

            <div class="table-header">
                <div></div>
                <div style="text-align: center;">#</div>
                <div>หัวข่าว</div>
                <div>รูปแบบ</div>
                <div>ผู้สื่อข่าว</div>
                <div>สังกัด</div>
                <div>สถานะ</div>
                <div>TRT</div>
                <div></div>
            </div>

            <div id="rundown-rows" style="margin-bottom: 50px;">
                <!-- Rows injected via JS -->
            </div>
        </div>

    </div>

    <!-- Live Story Search Modal -->
    <div id="story-search-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index:9999;">
        <div class="search-modal-box">
            <h3 style="margin-top:0; font-size: 22px; font-weight: 600; margin-bottom: 20px; color: #fff;">Search Stories</h3>
            <input type="text" id="story-search-input" class="premium-search-input" placeholder="Type keyword to search (Excludes Drafts)..." autofocus>
            <div id="story-search-results" class="search-modal-results"></div>
            <div style="text-align:right; margin-top:24px;">
                <button class="btn btn-secondary" style="padding:10px 20px; border-radius:8px;" onclick="closeStorySearchModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        window.RUNDOWN_ENV = {
            csrfToken: <?php echo json_encode($csrf_token); ?>,
            roleId: <?php echo $user['role_id']; ?>
        };
    </script>
    <script src="js/rundown.js?v=2"></script>
</body>
</html>
