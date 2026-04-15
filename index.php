<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];

// Generate CSRF token if not exists
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
    <title>News Room</title>
    <link rel="stylesheet" href="style.css?v=3">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    <?php $active_menu = 'story'; require_once 'top_menu.php'; ?>

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
                <option value="CAM">CAM (ตัดหน้าผู้ประกาศ)</option>
                <option value="VO">VO (เสียงบรรยาย)</option>
                <option value="SOT">SOT (เสียงสัมภาษณ์)</option>
                <option value="VOSOT">VOSOT (บรรยายเข้าสัมภาษณ์)</option>
                <option value="PKG">PKG (รายงานข่าวสมบูรณ์)</option>
                <option value="OC">OC (หน้าผู้ประกาศ)</option>
                <option value="CG">CG (กราฟิก/ชื่อ)</option>
                <option value="FS">FS (กราฟิกเต็มจอ)</option>
                <option value="OTS">OTS (กราฟิกข้างไหล่)</option>
                <option value="BUG">BUG (โลโก้มุมจอ)</option>
                <option value="OUTCUE">OUTCUE (จบด้วยคำว่า...)</option>
                <option value="TRT">TRT (ความยาวรวม)</option>
                <option value="NATS">NATS (เสียงบรรยากาศ)</option>
                <option value="DISS">DISS (ภาพจางซ้อน)</option>
                <option value="LIVE">LIVE (สด)</option>
                <option value="PHONE">PHONE (โฟนอิน)</option>
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

    <!-- My Story Sidebar -->
    <div id="mystory-sidebar" class="archive-sidebar mystory-sidebar">
        <div class="archive-header">
            <h2>My Story</h2>
            <button id="btn-close-mystory" class="btn" style="background:transparent; color:#fff; font-size:24px; border:none; padding:0;">&times;</button>
        </div>
        <div class="archive-tabs">
            <button id="tab-my-story" class="tab-btn active">My Story</button>
            <button id="tab-my-bin" class="tab-btn">My Bin</button>
        </div>
        <div id="mystory-results" class="archive-results">
            <div style="text-align:center; color: var(--text-secondary); margin-top: 20px;">Loading...</div>
        </div>
    </div>

    <!-- Preview Window -->
    <div id="preview-modal" class="floating-window">
        <div class="modal-header" id="preview-header" title="Drag to move">
            <h2 id="preview-title">Preview Title</h2>
            <div style="display: flex; gap: 16px; align-items: center;">
                <button id="btn-min-preview" class="btn" style="background:transparent; color:#fff; font-size:28px; border:none; padding:0; line-height: 0.8;" title="ย่อหน้าต่าง">&minus;</button>
                <button id="btn-max-preview" class="btn" style="background:transparent; color:#fff; font-size:20px; border:none; padding:0; line-height: 1;" title="เต็มจอ">&#9744;</button>
                <button id="btn-close-preview" class="btn" style="background:transparent; color:#fff; font-size:28px; border:none; padding:0; line-height: 0.8;" title="ปิด">&times;</button>
            </div>
        </div>
        <div id="preview-body" class="modal-body">
            <!-- Content injected here -->
        </div>
        <div class="modal-footer">
            <button id="btn-load-preview" class="btn btn-primary">เปิดแก้ไขข่าวนีี้</button>
            <button id="btn-cancel-preview" class="btn btn-secondary">ปิด</button>
        </div>
    </div>

    <!-- Autocomplete Popup for Structured Blocks -->
    <ul id="cmd-autocomplete" class="cmd-autocomplete" style="display: none;">
        <li data-val="VO"><strong>VO</strong> <small>- เสียงบรรยายภาพ</small></li>
        <li data-val="SOT"><strong>SOT</strong> <small>- เสียงสัมภาษณ์</small></li>
        <li data-val="VOSOT"><strong>VOSOT</strong> <small>- บรรยายเข้าสัมภาษณ์</small></li>
        <li data-val="PKG"><strong>PKG</strong> <small>- รายงานสกู๊ปข่าว</small></li>
        <li data-val="OC"><strong>OC</strong> <small>- หน้าผู้ประกาศ</small></li>
        <li data-val="CG 1 บรรทัด"><strong>CG 1 บรรทัด</strong> <small>- แถบชื่อผู้สัมภาษณ์</small></li>
        <li data-val="CG 2 บรรทัด"><strong>CG 2 บรรทัด</strong> <small>- แถบชื่อและตำแหน่ง</small></li>
        <li data-val="FS"><strong>FS</strong> <small>- กราฟิกเต็มหน้าจอ</small></li>
        <li data-val="OTS"><strong>OTS</strong> <small>- กราฟิกข้างไหล่ (Over the Shoulder)</small></li>
        <li data-val="BUG"><strong>BUG</strong> <small>- โลโก้มุมจอ</small></li>
        <li data-val="OUTCUE"><strong>OUTCUE</strong> <small>- คำสุดท้ายให้พูดตาม (OC)</small></li>
        <li data-val="TRT"><strong>TRT</strong> <small>- ระยะเวลารวม</small></li>
        <li data-val="NATS"><strong>NATS</strong> <small>- เสียงบรรยากาศแวดล้อมจริง</small></li>
        <li data-val="DISS"><strong>DISS</strong> <small>- เปลี่ยนภาพแบบจางซ้อน</small></li>
        <li data-val="LIVE"><strong>LIVE</strong> <small>- ถ่ายทอดสด</small></li>
        <li data-val="PHONE"><strong>PHONE</strong> <small>- โฟนอินพูดคุย</small></li>
    </ul>

    <script>
        window.currentUser = {
            fullName: <?php echo json_encode($user['full_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            roleId: <?php echo json_encode($user['role_id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            roleName: <?php echo json_encode($user['role_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            departmentId: <?php echo json_encode($user['department_id'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            departmentName: <?php echo json_encode($user['department_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            csrfToken: <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        };
    </script>
    <script type="module" src="js/main.js?v=3"></script>
</body>
</html>