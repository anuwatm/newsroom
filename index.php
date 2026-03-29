<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>News Room</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Print-only header -->
    <div class="print-header">
        <h1 id="print-slug-text">Story Slug</h1>
        <div class="print-meta">
            <span><strong>Reporter:</strong> <span id="print-reporter-text">-</span></span>
            <span><strong>Department:</strong> <span id="print-department-text">-</span></span>
        </div>
    </div>

    <!-- App Header -->
    <div class="app-header">
        <div class="header-left">
            <div class="app-title">News Room</div>
            <nav class="top-nav">
                <div class="nav-item dropdown">
                    <span class="nav-link">Story ▾</span>
                    <div class="dropdown-menu">
                        <a href="#" id="nav-new-story">New Story</a>
                        <a href="#" id="nav-find-story">Find Story</a>
                    </div>
                </div>
                <div class="nav-item"><a href="#" class="nav-link">Rundown</a></div>
                <div class="nav-item"><a href="#" class="nav-link">Assignment</a></div>
                <div class="nav-item"><a href="#" class="nav-link">Admin</a></div>
            </nav>
        </div>
        <div class="user-info-bar">
            <div class="user-avatar">
                <?php echo mb_substr($user['full_name'], 0, 1, 'UTF-8'); ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                <div class="user-role"><?php echo htmlspecialchars($user['department_name'] . ' • ' . $user['role_name']); ?></div>
            </div>
            <a href="logout.php" class="btn-logout" title="Logout">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                Sign Out
            </a>
        </div>
    </div>

    <div class="top-bar">
        <div class="meta-section">
            <input type="text" id="meta-slug" placeholder="Story Slug e.g. ไฟไหม้โรงงาน_สมุทรปราการ" class="meta-input slug-input">
            <div class="meta-row">
                <select id="meta-department" class="meta-input">
                    <!-- Loaded via JS -->
                </select>
                <input type="text" id="meta-reporter" placeholder="Reporter" class="meta-input" list="reporter-list">
                <datalist id="reporter-list"></datalist>
                <select id="meta-status" class="meta-input">
                    <option value="DRAFT">DRAFT</option>
                    <option value="READY">READY</option>
                    <option value="REVIEW">REVIEW</option>
                    <option value="APPROVED">APPROVED</option>
                </select>
            </div>
        </div>
        <div class="controls-section">
            <div class="time-display">
                <span class="time-label">TOTAL EST. TIME</span>
                <span id="total-time">00:00</span>
            </div>
            <div class="btn-group">
                <button id="btn-print" class="btn btn-secondary">PRINT STORY</button>
                <button id="btn-save" class="btn btn-primary">SAVE STORY</button>
            </div>
        </div>
    </div>

    <div class="editor-container">
        <div class="editor-header">
            <div class="col-left">PRODUCTION CUES</div>
            <div class="col-right">READ TEXT <span class="wpm-setting">(Read Rate: 3 words/sec)</span></div>
        </div>
        <div id="script-body">
            <!-- Rows injected by JS -->
        </div>
        <div class="add-row-container" style="padding: 16px 24px;">
            <button id="btn-add-row" class="btn btn-secondary" style="width: 100%; border: 2px dashed var(--border); padding: 12px; font-size: 14px; text-transform: uppercase;">
                + Add New Row
            </button>
        </div>
    </div>

    <!-- Template for a new row -->
    <template id="row-template">
        <div class="script-row">
            <div class="col-left">
                <div class="cue-blocks"></div>
                <button type="button" class="btn-add-cue btn btn-secondary" style="width: 100%; margin-top: 8px; font-size: 12px; padding: 6px;">+ Add Cue</button>
            </div>
            <div class="col-right">
                <textarea class="read-input" placeholder="Enter Anchor Text here..."></textarea>
                <div class="row-footer">
                    <span class="row-stats"><span class="word-count">0</span> words | Est. Time: <span class="row-time">0</span>s</span>
                    <button class="btn-remove-row" title="Delete Row">✕</button>
                </div>
            </div>
        </div>
    </template>

    <!-- Template for a single cue block -->
    <template id="cue-template">
        <div class="cue-block">
            <select class="cue-type meta-input">
                <option value="CAM">CAM</option>
                <option value="CG">CG</option>
                <option value="VO">VO</option>
                <option value="SOT">SOT</option>
                <option value="LIVE">LIVE</option>
                <option value="AUDIO">AUDIO</option>
                <option value="RAW">RAW Text</option>
            </select>
            <input type="text" class="cue-detail meta-input" placeholder="Details...">
            <button type="button" class="btn-remove-cue" title="Remove Cue">×</button>
        </div>
    </template>

    <!-- Archive Sidebar -->
    <div id="archive-sidebar" class="archive-sidebar">
        <div class="archive-header">
            <h2>Archive Search</h2>
            <button id="btn-close-archive" class="btn" style="background:transparent; color:#fff; font-size:24px; border:none; padding:0;">&times;</button>
        </div>
        <div class="archive-search">
            <select id="search-department" class="meta-input" style="width: 100%; margin-bottom: 12px;">
                <option value="">ทุกสังกัด (All)</option>
            </select>
            <input type="text" id="search-keyword" class="meta-input" placeholder="ค้นหาจากหัวข้อ หรือ เนื้อหา..." style="width: 100%; margin-bottom: 12px; box-sizing: border-box;">
            <button id="btn-do-search" class="btn btn-primary" style="width: 100%;">ค้นหา (Search)</button>
        </div>
        <div id="archive-results" class="archive-results">
            <div style="text-align:center; color: var(--text-secondary); margin-top: 20px;">ระบุคำค้นหาแล้วกด "ค้นหา"</div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div id="preview-modal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="preview-title">Preview Title</h2>
                <button id="btn-close-preview" class="btn" style="background:transparent; color:#fff; font-size:24px; border:none; padding:0;">&times;</button>
            </div>
            <div id="preview-body" class="modal-body">
                <!-- Content injected here -->
            </div>
            <div class="modal-footer">
                <button id="btn-load-preview" class="btn btn-primary">เปิดแก้ไขข่าวนีี้</button>
                <button id="btn-cancel-preview" class="btn btn-secondary">ปิด</button>
            </div>
        </div>
    </div>

    <!-- Autocomplete Popup for Structured Blocks -->
    <ul id="cmd-autocomplete" class="cmd-autocomplete" style="display: none;">
        <li data-val="CG 1 บรรทัด">CG 1 บรรทัด</li>
        <li data-val="CG 2 บรรทัด">CG 2 บรรทัด</li>
        <li data-val="VO">VO</li>
        <li data-val="SOT">SOT</li>
        <li data-val="LIVE">LIVE</li>
        <li data-val="AUDIO">AUDIO</li>
        <li data-val="VTR">VTR</li>
        <li data-val="PHONE">PHONE</li>
    </ul>

    <script>
        window.currentUser = {
            fullName: <?php echo json_encode($user['full_name']); ?>,
            roleId: <?php echo json_encode($user['role_id']); ?>,
            roleName: <?php echo json_encode($user['role_name']); ?>,
            departmentId: <?php echo json_encode($user['department_id']); ?>,
            departmentName: <?php echo json_encode($user['department_name']); ?>
        };
    </script>
    <script src="app.js?v=11"></script>
</body>
</html>