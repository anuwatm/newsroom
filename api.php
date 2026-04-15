<?php
/**
 * api.php
 * Handles JSON requests to save and load stories from SQLite
 */

require_once 'db.php';
header('Content-Type: application/json');

// Ensure data directory exists
$dataDir = __DIR__ . '/data/stories';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Ensure migrations for new columns
try {
    $db->exec("CREATE TABLE IF NOT EXISTS rundowns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        broadcast_time DATETIME NOT NULL,
        target_trt INTEGER NOT NULL DEFAULT 0,
        is_locked INTEGER DEFAULT 0,
        created_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS rundown_stories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        rundown_id INTEGER NOT NULL,
        story_id INTEGER NOT NULL,
        order_index INTEGER NOT NULL DEFAULT 0,
        is_dropped INTEGER DEFAULT 0,
        FOREIGN KEY(rundown_id) REFERENCES rundowns(id) ON DELETE CASCADE,
        FOREIGN KEY(story_id) REFERENCES stories(id) ON DELETE CASCADE
    )");
} catch (Exception $e) {}

try {
    $db->exec("ALTER TABLE rundown_stories ADD COLUMN is_break INTEGER DEFAULT 0");
} catch (Exception $e) {}
try {
    $db->exec("ALTER TABLE rundown_stories ADD COLUMN break_duration INTEGER DEFAULT 0");
} catch (Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS programs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        duration INTEGER NOT NULL DEFAULT 0,
        break_count INTEGER NOT NULL DEFAULT 0
    )");
} catch (Exception $e) {}

try {
    $db->exec("ALTER TABLE rundowns ADD COLUMN program_id INTEGER");
} catch (Exception $e) {}

try {
    $db->exec("ALTER TABLE stories ADD COLUMN current_version INTEGER DEFAULT 0");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN keywords TEXT");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN keyword_soundex TEXT");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN is_deleted INTEGER DEFAULT 0");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN locked_by TEXT");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN locked_at DATETIME");
} catch (Exception $e) { /* Column already exists */ }

try {
    $db->exec("ALTER TABLE stories ADD COLUMN author_id TEXT");
    $db->exec("UPDATE stories SET author_id = reporter WHERE author_id IS NULL");
} catch (Exception $e) { /* Column already exists */ }

try {
    $db->exec("CREATE TABLE IF NOT EXISTS assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        reporter_id TEXT NOT NULL,
        reporter_name TEXT NOT NULL,
        department_id INTEGER NOT NULL,
        status TEXT DEFAULT 'PENDING',
        approved_by TEXT,
        approved_at DATETIME,
        rejection_note TEXT,
        created_by TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS assignment_trips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
        trip_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME,
        location_name TEXT NOT NULL,
        location_detail TEXT,
        order_index INTEGER DEFAULT 0
    )");
} catch (Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS assignment_equipment (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
        equipment_name TEXT NOT NULL,
        quantity INTEGER DEFAULT 1,
        note TEXT
    )");
} catch (Exception $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS equipment_master (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        category TEXT,
        total_units INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1
    )");
    
    // Seed equipment_master data
    $db->exec("INSERT OR IGNORE INTO equipment_master (name, category, total_units, is_active) VALUES 
        ('กล้องวิดีโอ ENG', 'กล้อง', 5, 1),
        ('ช่างกล้อง ENG', 'บุคลากร', 5, 1),
        ('ไมค์บูม', 'เสียง', 5, 1),
        ('ไมค์คลิป', 'เสียง', 5, 1),
        ('ขาตั้งกล้อง', 'กล้อง', 5, 1),
        ('ไฟLED พกพา', 'ไฟ', 5, 1),
        ('รถ ENG', 'ยานพาหนะ', 5, 1),
        ('ล่าม/ผู้ช่วย', 'บุคลากร', 5, 1)
    ");
} catch (Exception $e) {}

session_start();
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'save_story') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON Payload']);
        exit;
    }

    try {
        // Validate CSRF token
        if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token. Security block.']);
            exit;
        }

        $db->beginTransaction();

        $storyId = $data['id'] ?? null;
        if ($storyId) $storyId = intval($storyId);
        $meta = $data['metadata'];

        $user = $_SESSION['user'];
        $role_id = $user['role_id'];
        $user_dept = $user['department_id'];

        // Bug 5: Target dept must be checked against existing DB value
        $target_dept = $meta['department'];
        if ($storyId) {
            $stmtEx = $db->prepare("SELECT department_id FROM stories WHERE id=?");
            $stmtEx->execute([$storyId]);
            $existingDbStory = $stmtEx->fetch(PDO::FETCH_ASSOC);
            if ($existingDbStory) {
                if (($role_id == 1 || $role_id == 2) && $existingDbStory['department_id'] != $user_dept) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Permission Denied: You can only edit stories within your own department.']);
                    exit;
                }
            }
        }
        
        // 1. Permission Check: Can edit this department (for newly created stories, or if somehow changing dept)
        if ($role_id == 1 || $role_id == 2) {
            if ($target_dept != $user_dept) {
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => 'Permission Denied: You can only create/edit stories in your own department.']);
                exit;
            }
        }

        // 2. Permission Check: Can approve?
        if ($meta['status'] === 'APPROVED') {
            if ($role_id == 1 || $role_id == 4) { // 1: Reporter, 4: Rewriter
                $db->rollBack();
                echo json_encode(['success' => false, 'error' => 'Permission Denied: You do not have permission to approve stories.']);
                exit;
            }
        }

        $is_autosave = isset($data['is_autosave']) ? $data['is_autosave'] : false;
        $newVersion = 1;
        
        $keywords = $meta['keywords'] ?? '';
        $soundexStr = '';
        if (!empty($keywords)) {
            $words = array_map('trim', explode(',', $keywords));
            $s_keys = [];
            foreach ($words as $w) {
                if(empty($w)) continue;
                $s1 = soundex($w);
                $s2 = thai_soundex($w);
                if ($s1) $s_keys[] = $s1;
                if ($s2) $s_keys[] = $s2;
            }
            $soundexStr = implode(' ', array_unique($s_keys));
        }

        if ($storyId) {
            // Verify Lock
            $stmtLock = $db->prepare("SELECT locked_by, locked_at FROM stories WHERE id=?");
            $stmtLock->execute([$storyId]);
            $lockStatus = $stmtLock->fetch(PDO::FETCH_ASSOC);
            if ($lockStatus) {
                $locked_by = $lockStatus['locked_by'];
                $locked_at = $lockStatus['locked_at'];
                $fallbackId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
                if (!empty($locked_by) && $locked_by !== $fallbackId) {
                    if (!empty($locked_at) && (time() - strtotime($locked_at)) < 300) {
                        $db->rollBack();
                        echo json_encode(['success' => false, 'error' => "Story is currently locked by {$locked_by}. Please wait or try again later."]);
                        exit;
                    }
                }
            }

            // Update existing story
            $stmt = $db->prepare("SELECT current_version FROM stories WHERE id=?");
            $stmt->execute([$storyId]);
            $currentStory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $currentVersion = $currentStory['current_version'] ?? 0;
            // หากเป็น Auto-save ให้ใช้เวอร์ชันเก่าเซฟทับ หากผู้ใช้กด Save ให้ขึ้นเวอร์ชันใหม่
            $newVersion = ($is_autosave && $currentVersion > 0) ? $currentVersion : $currentVersion + 1;

            $stmt = $db->prepare("UPDATE stories SET slug=?, format=?, reporter=?, anchor=?, department_id=?, status=?, estimated_time=?, current_version=?, keywords=?, keyword_soundex=?, locked_by=?, locked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $newVersion, $keywords, $soundexStr, ($user['employee_id'] ?? $user['id'] ?? $user['full_name']), $storyId]);
        } else {
            // Insert new story
            $authorId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
            $stmt = $db->prepare("INSERT INTO stories (slug, format, reporter, anchor, department_id, status, estimated_time, current_version, keywords, keyword_soundex, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $keywords, $soundexStr, $authorId]);
            $storyId = $db->lastInsertId();
        }

        // Save content to a versioned text file (Bug 8)
        if (isset($data['content']) && is_array($data['content'])) {
            $filePath = $dataDir . '/story_' . $storyId . '_v' . $newVersion . '.json';
            if (file_put_contents($filePath, json_encode($data['content'], JSON_UNESCAPED_UNICODE)) === false) {
                throw new Exception('Failed to write story data to storage.');
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'story_id' => $storyId]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($method === 'POST' && $action === 'lock_story') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $storyId = $data['id'] ?? null;
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    if (!$storyId) {
        echo json_encode(['success' => false, 'error' => 'Missing story ID']);
        exit;
    }

    // Check current lock status
    $stmt = $db->prepare("SELECT department_id, locked_by, locked_at FROM stories WHERE id=?");
    $stmt->execute([$storyId]);
    $story = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($story) {
        // Permissions validation
        $target_dept = $story['department_id'];
        $role_id = $user['role_id'];
        $user_dept = $user['department_id'];
        if (($role_id == 1 || $role_id == 2) && $target_dept != $user_dept) {
            echo json_encode(['success' => false, 'locked' => true, 'locked_by' => 'Permission Denied']);
            exit;
        }

        $locked_by = $story['locked_by'];
        $locked_at = $story['locked_at'];
        
        $is_locked_by_other = false;
        if (!empty($locked_by) && $locked_by !== $empId) {
            if (!empty($locked_at) && (time() - strtotime($locked_at)) < 300) { // 5 minutes lock duration
                $is_locked_by_other = true;
            }
        }

        if ($is_locked_by_other) {
            echo json_encode(['success' => false, 'locked' => true, 'locked_by' => $locked_by]);
            exit;
        }

        // Apply lock
        $stmt = $db->prepare("UPDATE stories SET locked_by=?, locked_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$empId, $storyId]);
        echo json_encode(['success' => true, 'locked' => false]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Story not found']);
    }

} elseif ($method === 'POST' && $action === 'unlock_story') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $storyId = $data['id'] ?? null;
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    if ($storyId) {
        // Unlock only if currently locked by this user
        $stmt = $db->prepare("UPDATE stories SET locked_by=NULL, locked_at=NULL WHERE id=? AND locked_by=?");
        $stmt->execute([$storyId, $empId]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'GET' && $action === 'get_story') {
    $storyId = $_GET['id'] ?? null;
    if ($storyId) $storyId = intval($storyId);
    
    if (!$storyId) {
        echo json_encode(['success' => false, 'error' => 'No story ID provided']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM stories WHERE id=?");
    $stmt->execute([$storyId]);
    $story = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$story) {
        echo json_encode(['success' => false, 'error' => 'Story not found']);
        exit;
    }

    if ($story['is_deleted'] == 1) {
        echo json_encode(['success' => false, 'error' => 'Story has been deleted']);
        exit;
    }

    $user = $_SESSION['user'];
    $role_id = $user['role_id'];
    $user_dept = $user['department_id'];

    if (($role_id == 1 || $role_id == 2) && $story['department_id'] != $user_dept) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied: You cannot read stories from other departments.']);
        exit;
    }

    $content = [];
    $version = $story['current_version'] ?? 0;
    
    // Check for Hybrid File version first
    $filePath = __DIR__ . '/data/stories/story_' . $storyId . '_v' . $version . '.json';
    if ($version > 0 && file_exists($filePath)) {
        $content = json_decode(file_get_contents($filePath), true);
    } else {
        // Legacy Database Fallback Loader
        $stmtRows = $db->prepare("SELECT * FROM story_rows WHERE story_id=? ORDER BY row_order ASC");
        $stmtRows->execute([$storyId]);
        $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

        $content = array_map(function ($row) {
            return [
                'type' => $row['type'],
                'leftColumn' => json_decode($row['left_column_json'], true),
                'rightColumn' => [
                    'text' => $row['right_column_text'],
                    'wordCount' => $row['word_count'],
                    'readTimeSeconds' => $row['estimated_read_time']
                ]
            ];
        }, $rows);
    }

    $is_locked_by_other = false;
    $locked_by = $story['locked_by'];
    $locked_at = $story['locked_at'];
    $fallbackId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];

    if (!empty($locked_by) && $locked_by !== $fallbackId) {
        if (!empty($locked_at) && (time() - strtotime($locked_at)) < 300) {
            $is_locked_by_other = true;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $story['id'],
            'is_locked' => $is_locked_by_other,
            'locked_by' => $is_locked_by_other ? $locked_by : null,
            'metadata' => [
                'slug' => $story['slug'],
                'format' => $story['format'],
                'reporter' => $story['reporter'],
                'anchor' => $story['anchor'],
                'department' => $story['department_id'],
                'status' => $story['status'],
                'estimated_time' => $story['estimated_time'],
                'keywords' => $story['keywords'] ?? ''
            ],
            'content' => $content
        ]
    ]);
} elseif ($method === 'GET' && $action === 'get_departments') {
    $stmt = $db->query("SELECT id, name FROM departments ORDER BY id ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'GET' && $action === 'get_users') {
    $stmt = $db->query("SELECT employee_id as id, full_name as name FROM users ORDER BY full_name ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'GET' && $action === 'search_stories') {
    $dept_id = $_GET['department_id'] ?? '';
    $keyword = $_GET['keyword'] ?? '';
    $exclude_draft = isset($_GET['exclude_draft']) && $_GET['exclude_draft'] == '1';
    $kw = "%$keyword%";

    $query = "SELECT DISTINCT s.id, s.slug, s.updated_at, d.name as department_name, s.status 
              FROM stories s
              LEFT JOIN departments d ON s.department_id = d.id
              WHERE s.is_deleted = 0";
    $params = [];
    
    if ($exclude_draft) {
        $query .= " AND s.status != 'DRAFT'";
    }

    $user = $_SESSION['user'];
    $role_id = $user['role_id'];
    $user_dept = $user['department_id'];

    if ($role_id == 1 || $role_id == 2) {
        $dept_id = $user_dept; // Force scope to user's assigned department
    }

    if ($dept_id !== '') {
        $query .= " AND s.department_id = ?";
        $params[] = $dept_id;
    }

    if ($keyword !== '') {
        $query .= " AND (s.slug LIKE ? OR s.anchor LIKE ?";
        $params[] = $kw;
        $params[] = $kw;
        
        $t_sound = thai_soundex($keyword);
        if (!empty($t_sound)) {
            $query .= " OR s.keyword_soundex LIKE ?";
            $params[] = "%" . $t_sound . "%";
        }
        
        $e_sound = soundex($keyword);
        // PHP soundex() typically returns 4 chars (e.g. S000). A single letter fallback like "A000" might be too broad if keyword is just 'a', but standard soundex matches will work. We just avoid empty strings.
        if (!empty($e_sound) && strlen($keyword) > 1) {
            $query .= " OR s.keyword_soundex LIKE ?";
            $params[] = "%" . $e_sound . "%";
        }
        
        $query .= ")";
    }

    $query .= " ORDER BY s.updated_at DESC LIMIT 50";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'GET' && $action === 'get_my_stories') {
    $is_bin = isset($_GET['is_bin']) && $_GET['is_bin'] == '1' ? 1 : 0;
    $user = $_SESSION['user'];
    $authorId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    $query = "SELECT s.id, s.slug, s.updated_at, d.name as department_name, s.status 
              FROM stories s
              LEFT JOIN departments d ON s.department_id = d.id
              WHERE s.author_id = ? AND s.is_deleted = ?
              ORDER BY s.updated_at DESC LIMIT 100";
    $stmt = $db->prepare($query);
    $stmt->execute([$authorId, $is_bin]);
    
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'POST' && $action === 'move_to_bin') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $storyId = $data['id'] ?? null;
    $user = $_SESSION['user'];
    $authorId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    if (!$storyId) {
        echo json_encode(['success' => false, 'error' => 'Missing story ID']);
        exit;
    }
    
    $stmt = $db->prepare("UPDATE stories SET is_deleted = 1 WHERE id = ? AND author_id = ? AND status = 'DRAFT'");
    $stmt->execute([$storyId, $authorId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not delete story. It may not belong to you or is not a DRAFT.']);
    }

} elseif ($method === 'POST' && $action === 'create_rundown') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied: Only Main Editor can create a Rundown.']);
        exit;
    }
    
    $program_id = $data['program_id'] ?? null;
    $name = $data['name'] ?? 'New Rundown';
    $broadcast_time_val = trim($data['broadcast_time'] ?? '');
    $bt_timestamp = strtotime($broadcast_time_val);
    if (!$bt_timestamp) {
        $broadcast_time = date('Y-m-d H:i:s');
    } else {
        $broadcast_time = date('Y-m-d H:i:s', $bt_timestamp);
    }
    $target_trt = intval($data['target_trt'] ?? 0);
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    $stmt = $db->prepare("INSERT INTO rundowns (name, broadcast_time, target_trt, created_by, program_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $broadcast_time, $target_trt, $empId, $program_id]);
    $rundownId = $db->lastInsertId();

    $breakCount = intval($data['break_count'] ?? 0);
    if ($breakCount > 0) {
        $db->exec("INSERT INTO stories (slug, format, estimated_time, status, is_deleted)
                   SELECT '--- COMMERCIAL BREAK ---', 'BREAK', 180, 'APPROVED', 1
                   WHERE NOT EXISTS (SELECT 1 FROM stories WHERE format='BREAK' AND slug='--- COMMERCIAL BREAK ---')");
        $stmtCheckBreak = $db->query("SELECT id FROM stories WHERE format='BREAK' AND slug='--- COMMERCIAL BREAK ---' LIMIT 1");
        $breakStoryId = $stmtCheckBreak->fetchColumn();
        
        $stmtRunStore = $db->prepare("INSERT INTO rundown_stories (rundown_id, story_id, order_index, is_break, break_duration) VALUES (?, ?, ?, 1, 180)");
        
        for ($i = 0; $i < $breakCount; $i++) {
            $stmtRunStore->execute([$rundownId, $breakStoryId, $i + 1]);
        }
    }
    
    echo json_encode(['success' => true, 'id' => $rundownId]);

} elseif ($method === 'GET' && $action === 'get_rundowns') {
    $user = $_SESSION['user'];
    if ($user['role_id'] == 1 || $user['role_id'] == 4) { // Restrict Reporters and Rewriters
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    $stmt = $db->query("SELECT * FROM rundowns ORDER BY broadcast_time DESC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'GET' && $action === 'get_rundown_data') {
    $user = $_SESSION['user'];
    if ($user['role_id'] == 1 || $user['role_id'] == 4) { // Restrict Reporters and Rewriters
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $rundownId = $_GET['id'] ?? null;
    if (!$rundownId) {
        echo json_encode(['success' => false, 'error' => 'Missing rundown ID']);
        exit;
    }
    
    $stmt = $db->prepare("SELECT * FROM rundowns WHERE id=?");
    $stmt->execute([$rundownId]);
    $rundown = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rundown) {
        echo json_encode(['success' => false, 'error' => 'Rundown not found']);
        exit;
    }
    
    $stmtStories = $db->prepare("
        SELECT rs.id as rundown_story_id, rs.order_index, rs.is_dropped, rs.is_break, rs.break_duration,
               s.id, s.slug, s.format, s.reporter, s.status, 
               CASE WHEN rs.is_break = 1 THEN rs.break_duration ELSE s.estimated_time END as estimated_time, 
               s.updated_at, d.name as department_name
        FROM rundown_stories rs
        JOIN stories s ON rs.story_id = s.id
        LEFT JOIN departments d ON s.department_id = d.id
        WHERE rs.rundown_id = ?
        ORDER BY rs.order_index ASC
    ");
    $stmtStories->execute([$rundownId]);
    $rundown['stories'] = $stmtStories->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $rundown]);

} elseif ($method === 'POST' && $action === 'add_rundown_story') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $rundownId = $data['rundown_id'] ?? null;
    $storyId = $data['story_id'] ?? null;
    $user = $_SESSION['user'];
    
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    if (!$rundownId || !$storyId) {
        echo json_encode(['success' => false, 'error' => 'Missing IDs']);
        exit;
    }

    $stmtLock = $db->prepare("SELECT is_locked FROM rundowns WHERE id=?");
    $stmtLock->execute([$rundownId]);
    if ($stmtLock->fetchColumn() == 1) {
        echo json_encode(['success' => false, 'error' => 'Rundown is locked']);
        exit;
    }
    
    $stmtDup = $db->prepare("SELECT COUNT(*) FROM rundown_stories WHERE rundown_id=? AND story_id=?");
    $stmtDup->execute([$rundownId, $storyId]);
    if ($stmtDup->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Story is already in this rundown.']);
        exit;
    }
    
    // Check if story is approved
    $stmtCheck = $db->prepare("SELECT status FROM stories WHERE id=?");
    $stmtCheck->execute([$storyId]);
    $story = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$story || $story['status'] !== 'APPROVED') {
        echo json_encode(['success' => false, 'error' => 'Only APPROVED stories can be added to the rundown.']);
        exit;
    }

    $stmtMax = $db->prepare("SELECT MAX(order_index) FROM rundown_stories WHERE rundown_id=?");
    $stmtMax->execute([$rundownId]);
    $maxOrder = intval($stmtMax->fetchColumn()) + 1;
    
    $stmt = $db->prepare("INSERT INTO rundown_stories (rundown_id, story_id, order_index) VALUES (?, ?, ?)");
    $stmt->execute([$rundownId, $storyId, $maxOrder]);
    
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'add_rundown_break') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $rundownId = $data['rundown_id'] ?? null;
    $duration = intval($data['duration'] ?? 180);
    $user = $_SESSION['user'];
    
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    if (!$rundownId) {
        echo json_encode(['success' => false, 'error' => 'Missing IDs']);
        exit;
    }

    $stmtLock = $db->prepare("SELECT is_locked FROM rundowns WHERE id=?");
    $stmtLock->execute([$rundownId]);
    if ($stmtLock->fetchColumn() == 1) {
        echo json_encode(['success' => false, 'error' => 'Rundown is locked']);
        exit;
    }

    // Insert dummy record in stories marked as deleted so it won't show in search
    $db->exec("INSERT INTO stories (slug, format, estimated_time, status, is_deleted)
               SELECT '--- COMMERCIAL BREAK ---', 'BREAK', 180, 'APPROVED', 1
               WHERE NOT EXISTS (SELECT 1 FROM stories WHERE format='BREAK' AND slug='--- COMMERCIAL BREAK ---')");
    $stmtCheckBreak = $db->query("SELECT id FROM stories WHERE format='BREAK' AND slug='--- COMMERCIAL BREAK ---' LIMIT 1");
    $breakStoryId = $stmtCheckBreak->fetchColumn();

    $stmtMax = $db->prepare("SELECT MAX(order_index) FROM rundown_stories WHERE rundown_id=?");
    $stmtMax->execute([$rundownId]);
    $maxOrder = intval($stmtMax->fetchColumn()) + 1;
    
    $stmt = $db->prepare("INSERT INTO rundown_stories (rundown_id, story_id, order_index, is_break, break_duration) VALUES (?, ?, ?, 1, ?)");
    $stmt->execute([$rundownId, $breakStoryId, $maxOrder, $duration]);
    
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'update_rundown_order') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $ids = $data['ids'] ?? [];
    $rundownId = $data['rundown_id'] ?? null;
    
    if (!$rundownId) {
        echo json_encode(['success' => false, 'error' => 'Missing rundown ID']);
        exit;
    }
    
    $stmtLock = $db->prepare("SELECT is_locked FROM rundowns WHERE id=?");
    $stmtLock->execute([$rundownId]);
    if ($stmtLock->fetchColumn() == 1) {
        echo json_encode(['success' => false, 'error' => 'Rundown is locked']);
        exit;
    }
    
    if (!empty($ids)) {
        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE rundown_stories SET order_index=? WHERE id=? AND rundown_id=?");
        foreach ($ids as $index => $rsId) {
            $stmt->execute([$index + 1, $rsId, $rundownId]);
        }
        $db->commit();
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'toggle_rundown_story_drop') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $rsId = $data['id'] ?? null;
    $isDropped = $data['is_dropped'] ?? 0;
    
    if ($rsId) {
        $stmtLock = $db->prepare("SELECT r.is_locked FROM rundowns r JOIN rundown_stories rs ON r.id = rs.rundown_id WHERE rs.id=?");
        $stmtLock->execute([$rsId]);
        if ($stmtLock->fetchColumn() == 1) {
            echo json_encode(['success' => false, 'error' => 'Rundown is locked']);
            exit;
        }

        $stmt = $db->prepare("UPDATE rundown_stories SET is_dropped=? WHERE id=?");
        $stmt->execute([$isDropped, $rsId]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'toggle_lock_rundown') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $rundownId = $data['id'] ?? null;
    $isLocked = intval($data['is_locked'] ?? 1);
    
    if ($rundownId) {
        $stmt = $db->prepare("UPDATE rundowns SET is_locked=? WHERE id=?");
        $stmt->execute([$isLocked, $rundownId]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'GET' && $action === 'get_programs') {
    $stmt = $db->query("SELECT * FROM programs ORDER BY name ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST' && $action === 'save_program') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $id = $data['id'] ?? null;
    $name = trim($data['name'] ?? '');
    $duration = intval($data['duration'] ?? 0);
    $breakCount = intval($data['break_count'] ?? 0);
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name cannot be empty']);
        exit;
    }

    if ($id) {
        $stmt = $db->prepare("UPDATE programs SET name=?, duration=?, break_count=? WHERE id=?");
        $stmt->execute([$name, $duration, $breakCount, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO programs (name, duration, break_count) VALUES (?, ?, ?)");
        $stmt->execute([$name, $duration, $breakCount]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'delete_program') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $id = $data['id'] ?? null;
    if ($id) {
        $stmt = $db->prepare("DELETE FROM programs WHERE id=?");
        $stmt->execute([$id]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'GET' && $action === 'get_all_users') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    $query = "SELECT u.employee_id, u.full_name, u.role_id, u.department_id, 
                     r.name as role_name, d.name as department_name 
              FROM users u
              LEFT JOIN roles r ON u.role_id = r.id
              LEFT JOIN departments d ON u.department_id = d.id
              ORDER BY u.created_at ASC";
    $stmt = $db->query($query);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'GET' && $action === 'get_roles') {
    $stmt = $db->query("SELECT id, name FROM roles ORDER BY id ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST' && $action === 'save_user') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $is_edit = $data['is_edit'] ?? false;
    $employee_id = trim($data['employee_id'] ?? '');
    $full_name = trim($data['full_name'] ?? '');
    $password = $data['password'] ?? '';
    $role_id = intval($data['role_id'] ?? 0);
    $department_id = intval($data['department_id'] ?? 0);
    
    if (empty($employee_id) || empty($full_name) || empty($role_id) || empty($department_id)) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }
    
    if ($is_edit) {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("UPDATE users SET full_name=?, password=?, role_id=?, department_id=? WHERE employee_id=?");
            $stmt->execute([$full_name, $hashed, $role_id, $department_id, $employee_id]);
        } else {
            $stmt = $db->prepare("UPDATE users SET full_name=?, role_id=?, department_id=? WHERE employee_id=?");
            $stmt->execute([$full_name, $role_id, $department_id, $employee_id]);
        }
    } else {
        if (empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Password is required for new users']);
            exit;
        }
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE employee_id=?");
        $stmtCheck->execute([$employee_id]);
        if ($stmtCheck->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Username (Employee ID) already exists']);
            exit;
        }
        
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (employee_id, full_name, password, role_id, department_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$employee_id, $full_name, $hashed, $role_id, $department_id]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'delete_user') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $employee_id = $data['employee_id'] ?? null;
    if ($employee_id) {
        if ($employee_id === $user['employee_id']) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete your own account']);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM users WHERE employee_id=?");
        $stmt->execute([$employee_id]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'save_department') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $id = $data['id'] ?? null;
    $name = trim($data['name'] ?? '');
    
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Department name is required']);
        exit;
    }
    
    // Ensure uniqueness
    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM departments WHERE name=? AND id!=?");
    $stmtCheck->execute([$name, $id ?? 0]);
    if ($stmtCheck->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Department name already exists']);
        exit;
    }
    
    if ($id) {
        $stmt = $db->prepare("UPDATE departments SET name=? WHERE id=?");
        $stmt->execute([$name, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO departments (name) VALUES (?)");
        $stmt->execute([$name]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'delete_department') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
        exit;
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }
    
    $id = $data['id'] ?? null;
    if ($id) {
        // Prevent deletion if connected to stories or users 
        $stmtCheck1 = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id=?");
        $stmtCheck1->execute([$id]);
        if ($stmtCheck1->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete: Department is actively assigned to Users.']);
            exit;
        }

        $stmtCheck2 = $db->prepare("SELECT COUNT(*) FROM stories WHERE department_id=?");
        $stmtCheck2->execute([$id]);
        if ($stmtCheck2->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete: Department is assigned to existing Stories.']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM departments WHERE id=?");
        $stmt->execute([$id]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'GET' && $action === 'get_dashboard_stats') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }

    $stats = [];
    
    // 1. Total users
    $stmtUsers = $db->query("SELECT COUNT(*) FROM users");
    $stats['total_users'] = (int)$stmtUsers->fetchColumn();

    // 2. Total active stories (not deleted)
    $stmtStories = $db->query("SELECT COUNT(*) FROM stories WHERE is_deleted = 0 AND format != 'BREAK'");
    $stats['total_stories'] = (int)$stmtStories->fetchColumn();

    // 3. Status breakdown
    $stmtStatus = $db->query("SELECT status, COUNT(*) as count FROM stories WHERE is_deleted = 0 AND format != 'BREAK' GROUP BY status");
    $statusCounts = [];
    while ($row = $stmtStatus->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[$row['status']] = (int)$row['count'];
    }
    $stats['status_counts'] = $statusCounts;

    // 4. Department breakdown
    $stmtDept = $db->query("SELECT d.name as department_name, COUNT(s.id) as count 
                            FROM stories s 
                            LEFT JOIN departments d ON s.department_id = d.id 
                            WHERE s.is_deleted = 0 AND s.format != 'BREAK' 
                            GROUP BY d.name");
    $deptCounts = [];
    while ($row = $stmtDept->fetch(PDO::FETCH_ASSOC)) {
        $name = empty($row['department_name']) ? 'Unknown' : $row['department_name'];
        $deptCounts[$name] = (int)$row['count'];
    }
    $stats['dept_counts'] = $deptCounts;

    // 5. Total rundowns
    $stmtRundownsTot = $db->query("SELECT COUNT(*) FROM rundowns");
    $stats['total_rundowns'] = (int)$stmtRundownsTot->fetchColumn();

    // 6. Upcoming broadcasts
    $stmtRun = $db->query("SELECT id, name, broadcast_time, target_trt 
                           FROM rundowns 
                           ORDER BY broadcast_time DESC 
                           LIMIT 4");
    $stats['upcoming_rundowns'] = $stmtRun->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $stats]);

} elseif ($method === 'GET' && $action === 'get_assignments') {
    $status = $_GET['status'] ?? '';
    $month = $_GET['month'] ?? ''; // YYYY-MM
    $dept = $_GET['department_id'] ?? '';
    
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $user_dept = $user['department_id'];

    $where = ["1=1"];
    $params = [];

    if ($role_id == 1 || $role_id == 4) {
        $where[] = "a.reporter_id = ?";
        $params[] = $user_emp_id;
    } elseif ($role_id == 2) {
        $where[] = "a.department_id = ?";
        $params[] = $user_dept;
    }
    
    if ($status) {
        $where[] = "a.status = ?";
        $params[] = $status;
    }
    if ($dept) {
        $where[] = "a.department_id = ?";
        $params[] = $dept;
    }
    if ($month) {
        $where[] = "EXISTS (SELECT 1 FROM assignment_trips t WHERE t.assignment_id = a.id AND t.trip_date LIKE ?)";
        $params[] = $month . '-%';
    }
    $where[] = "a.status != 'DELETED'";

    $whereStr = implode(" AND ", $where);
    $stmt = $db->prepare("SELECT a.*, d.name as department_name FROM assignments a LEFT JOIN departments d ON a.department_id = d.id WHERE $whereStr ORDER BY a.created_at DESC");
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assignments as &$ass) {
        $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id = ? ORDER BY trip_date ASC, start_time ASC");
        $stmtT->execute([$ass['id']]);
        $ass['trips'] = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        $stmtE = $db->prepare("SELECT e.*, m.name FROM assignment_equipment e LEFT JOIN equipment_master m ON e.equipment_name = m.name WHERE e.assignment_id = ?");
        $stmtE->execute([$ass['id']]);
        $ass['equipment'] = $stmtE->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['success' => true, 'data' => $assignments]);

} elseif ($method === 'GET' && $action === 'get_assignment_detail') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT a.*, d.name as department_name FROM assignments a LEFT JOIN departments d ON a.department_id = d.id WHERE a.id = ?");
    $stmt->execute([$id]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ass) {
        $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id = ? ORDER BY trip_date ASC, start_time ASC");
        $stmtT->execute([$id]);
        $ass['trips'] = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        $stmtE = $db->prepare("SELECT * FROM assignment_equipment WHERE assignment_id = ?");
        $stmtE->execute([$id]);
        $ass['equipment'] = $stmtE->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $ass]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Assignment not found']);
    }

} elseif ($method === 'POST' && $action === 'create_assignment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }

    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $user_dept = $user['department_id'];

    $title = trim($data['title'] ?? '');
    $reporter_id = $data['reporter_id'] ?? '';
    
    if ($role_id == 1 || $role_id == 4) {
        if ($reporter_id !== $user_emp_id) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only create for yourself.']); exit;
        }
    } elseif ($role_id == 2) {
        if ($data['department_id'] != $user_dept) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only create for your department.']); exit;
        }
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("INSERT INTO assignments (title, description, reporter_id, reporter_name, department_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$title, $data['description'] ?? '', $reporter_id, $data['reporter_name'] ?? '', $data['department_id'] ?? 0, $user_emp_id]);
        $assignmentId = $db->lastInsertId();

        $trips = $data['trips'] ?? [];
        if (empty($trips)) throw new Exception('At least 1 trip required.');
        $stmtT = $db->prepare("INSERT INTO assignment_trips (assignment_id, trip_date, start_time, end_time, location_name, location_detail, order_index) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($trips as $i => $t) {
            $stmtT->execute([$assignmentId, $t['trip_date'], $t['start_time'], $t['end_time'] ?? null, $t['location_name'] ?? '', $t['location_detail'] ?? '', $i]);
        }

        $equip = $data['equipment'] ?? [];
        if (!empty($equip)) {
            $stmtE = $db->prepare("INSERT INTO assignment_equipment (assignment_id, equipment_name, quantity, note) VALUES (?, ?, ?, ?)");
            foreach ($equip as $e) {
                $stmtE->execute([$assignmentId, $e['equipment_name'], intval($e['quantity'] ?? 1), $e['note'] ?? '']);
            }
        }
        $db->commit();
        echo json_encode(['success' => true, 'id' => $assignmentId]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($method === 'POST' && $action === 'update_assignment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $assignmentId = intval($data['id'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$assignmentId]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ass || $ass['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'Can only edit PENDING assignments.']); exit;
    }
    
    if ($role_id == 1 || $role_id == 4) {
        if ($ass['created_by'] !== $user_emp_id) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only edit your own created assignment.']); exit;
        }
    } elseif ($role_id == 2) {
        if ($ass['department_id'] != $user['department_id']) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
    }

    $db->beginTransaction();
    try {
        $stmtU = $db->prepare("UPDATE assignments SET title=?, description=?, reporter_id=?, reporter_name=?, department_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmtU->execute([$data['title'], $data['description'] ?? '', $data['reporter_id'], $data['reporter_name'], $data['department_id'], $assignmentId]);
        
        $db->exec("DELETE FROM assignment_trips WHERE assignment_id = $assignmentId");
        $db->exec("DELETE FROM assignment_equipment WHERE assignment_id = $assignmentId");
        
        $trips = $data['trips'] ?? [];
        if (empty($trips)) throw new Exception('At least 1 trip required.');
        $stmtT = $db->prepare("INSERT INTO assignment_trips (assignment_id, trip_date, start_time, end_time, location_name, location_detail, order_index) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($trips as $i => $t) {
            $stmtT->execute([$assignmentId, $t['trip_date'], $t['start_time'], $t['end_time'] ?? null, $t['location_name'] ?? '', $t['location_detail'] ?? '', $i]);
        }

        $equip = $data['equipment'] ?? [];
        if (!empty($equip)) {
            $stmtE = $db->prepare("INSERT INTO assignment_equipment (assignment_id, equipment_name, quantity, note) VALUES (?, ?, ?, ?)");
            foreach ($equip as $e) {
                $stmtE->execute([$assignmentId, $e['equipment_name'], intval($e['quantity'] ?? 1), $e['note'] ?? '']);
            }
        }
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($method === 'POST' && $action === 'delete_assignment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    
    $id = intval($data['id'] ?? 0);
    $user = $_SESSION['user'];
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $role_id = intval($user['role_id']);

    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ass) {
        echo json_encode(['success' => true]); exit;
    }
    
    if ($role_id != 3) {
        if ($ass['created_by'] !== $user_emp_id || $ass['status'] !== 'PENDING') {
            echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only delete your own PENDING assignments.']); exit;
        }
    }
    
    $stmtDel = $db->prepare("UPDATE assignments SET status='DELETED' WHERE id=?");
    $stmtDel->execute([$id]);
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'approve_assignment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    if ($role_id == 1 || $role_id == 4) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $id = intval($data['id'] ?? 0);
    
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($role_id == 2 && $ass['department_id'] != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    if ($ass['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'Must be PENDING']); exit;
    }
    
    $stmtA = $db->prepare("UPDATE assignments SET status='APPROVED', approved_by=?, approved_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtA->execute([$user['employee_id'] ?? $user['id'] ?? $user['full_name'], $id]);
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'reject_assignment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    if ($role_id == 1 || $role_id == 4) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $id = intval($data['id'] ?? 0);
    $note = trim($data['rejection_note'] ?? '');
    if (!$note) {
        echo json_encode(['success' => false, 'error' => 'Rejection note required']); exit;
    }
    
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($role_id == 2 && $ass['department_id'] != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    
    $stmtA = $db->prepare("UPDATE assignments SET status='REJECTED', rejection_note=? WHERE id=?");
    $stmtA->execute([$note, $id]);
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'complete_assignment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $id = intval($data['id'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    $stmt = $db->prepare("SELECT * FROM assignments WHERE id = ?");
    $stmt->execute([$id]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($role_id == 1 || $role_id == 4) {
        if ($ass['reporter_id'] !== $user_emp_id) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
    } elseif ($role_id == 2) {
        if ($ass['department_id'] != $user['department_id']) {
            echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
        }
    }
    
    if ($ass['status'] !== 'APPROVED') {
        echo json_encode(['success' => false, 'error' => 'Can only complete APPROVED assignment']); exit;
    }
    
    $stmtC = $db->prepare("UPDATE assignments SET status='COMPLETED' WHERE id=?");
    $stmtC->execute([$id]);
    echo json_encode(['success' => true]);

} elseif ($method === 'GET' && $action === 'get_assignments') {
    $month = $_GET['month'] ?? ''; // YYYY-MM
    $dept_id = $_GET['department_id'] ?? '';
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $user_dept = $user['department_id'];

    $where = ["a.status != 'DELETED'"];
    $params = [];

    if ($month !== '') {
        $where[] = "EXISTS (SELECT 1 FROM assignment_trips t WHERE t.assignment_id = a.id AND t.trip_date LIKE ?)";
        $params[] = $month . '-%';
    }

    if ($role_id == 1 || $role_id == 4) {
        $where[] = "a.reporter_id = ?";
        $params[] = $user_emp_id;
    } elseif ($role_id == 2) {
        $where[] = "a.department_id = ?";
        $params[] = $user_dept;
    } else {
        if ($dept_id !== '') {
            $where[] = "a.department_id = ?";
            $params[] = $dept_id;
        }
    }

    $whereStr = implode(" AND ", $where);
    $query = "SELECT a.*, d.name as department_name,
              (SELECT GROUP_CONCAT(equipment_name) FROM assignment_equipment e WHERE e.assignment_id = a.id) as equipment_list
              FROM assignments a
              LEFT JOIN departments d ON a.department_id = d.id
              WHERE $whereStr
              ORDER BY a.updated_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach($assignments as &$a) {
        $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id = ? ORDER BY trip_date ASC, start_time ASC LIMIT 1");
        $stmtT->execute([$a['id']]);
        $firstTrip = $stmtT->fetch(PDO::FETCH_ASSOC);
        $a['first_trip_date'] = $firstTrip ? $firstTrip['trip_date'] : null;
    }

    echo json_encode(['success' => true, 'data' => $assignments]);

} elseif ($method === 'GET' && $action === 'get_equipment_master_all') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $stmt = $db->query("SELECT * FROM equipment_master ORDER BY category, name");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST' && $action === 'save_equipment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $id = intval($data['id'] ?? 0);
    $name = trim($data['name'] ?? '');
    $cat = trim($data['category'] ?? '');
    $qty = intval($data['total_units'] ?? 1);
    $active = intval($data['is_active'] ?? 1);

    if (!$name) {
        echo json_encode(['success' => false, 'error' => 'Equipment name is required']); exit;
    }

    try {
        if ($id) {
            $stmt = $db->prepare("UPDATE equipment_master SET name=?, category=?, total_units=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $cat, $qty, $active, $id]);
        } else {
            $stmt = $db->prepare("INSERT INTO equipment_master (name, category, total_units, is_active) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $cat, $qty, $active]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Duplicate item name or server error']);
    }

} elseif ($method === 'POST' && $action === 'delete_equipment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $id = intval($data['id'] ?? 0);
    try {
        $stmtName = $db->prepare("SELECT name FROM equipment_master WHERE id = ?");
        $stmtName->execute([$id]);
        $name = $stmtName->fetchColumn();
        if ($name) {
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM assignment_equipment WHERE equipment_name = ?");
            $stmtCheck->execute([$name]);
            if ($stmtCheck->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot delete item currently used in assignments. Select "Inactive" status instead.']); exit;
            }
            $stmtDel = $db->prepare("DELETE FROM equipment_master WHERE id = ?");
            $stmtDel->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($method === 'GET' && $action === 'get_equipment_master') {
    $stmt = $db->query("SELECT * FROM equipment_master WHERE is_active = 1");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'GET' && $action === 'get_equipment_conflicts') {
    $date = trim($_GET['date'] ?? '');
    if (!$date) {
         echo json_encode(['success' => true, 'data' => []]); exit;
    }
    $query = "SELECT e.equipment_name, SUM(e.quantity) as used_qty
              FROM assignment_equipment e
              JOIN assignments a ON e.assignment_id = a.id
              JOIN assignment_trips t ON a.id = t.assignment_id
              WHERE t.trip_date = ? AND a.status IN ('APPROVED', 'PENDING')
              GROUP BY e.equipment_name";
    $stmt = $db->prepare($query);
    $stmt->execute([$date]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'GET' && $action === 'get_calendar_data') {
    $month = $_GET['month'] ?? ''; // YYYY-MM
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $user_dept = $user['department_id'];

    $where = ["a.status != 'DELETED'", "t.trip_date LIKE ?"];
    $params = [$month . '-%'];

    if ($role_id == 1 || $role_id == 4) {
        $where[] = "a.reporter_id = ?";
        $params[] = $user_emp_id;
    } elseif ($role_id == 2) {
        $where[] = "a.department_id = ?";
        $params[] = $user_dept;
    }

    $whereStr = implode(" AND ", $where);
    $sql = "SELECT t.trip_date as date, a.id as assignment_id, a.title, a.status, a.reporter_name, t.location_name
            FROM assignment_trips t
            JOIN assignments a ON t.assignment_id = a.id
            WHERE $whereStr";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($trips as &$t) {
        $stmtEQ = $db->prepare("SELECT equipment_name FROM assignment_equipment WHERE assignment_id = ?");
        $stmtEQ->execute([$t['assignment_id']]);
        $t['equipment'] = $stmtEQ->fetchAll(PDO::FETCH_COLUMN); // Array of names
    }

    echo json_encode(['success' => true, 'data' => $trips]);

} elseif ($method === 'GET' && $action === 'get_assignment_badge_count') {
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $user_dept = $user['department_id'];

    if ($role_id == 1 || $role_id == 4) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE reporter_id = ? AND status = 'REJECTED'");
        $stmt->execute([$user_emp_id]);
    } elseif ($role_id == 2) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM assignments WHERE department_id = ? AND status = 'PENDING'");
        $stmt->execute([$user_dept]);
    } elseif ($role_id == 3) {
        $stmt = $db->query("SELECT COUNT(*) FROM assignments WHERE status = 'PENDING'");
    } else {
        echo json_encode(['success' => true, 'count' => 0]); exit;
    }
    echo json_encode(['success' => true, 'count' => $stmt->fetchColumn()]);

} else {
    echo json_encode(['success' => false, 'error' => 'Invalid Action']);
}


function thai_soundex($text) {
    if (empty($text)) return '';
    // Remove vowels, tone marks and special characters
    $text = preg_replace('/[ะ-ูเ-ไๆฯิ-ื์]/u', '', $text);
    
    // Convert to uppercase phonetic groups based loosely on LK82
    $map = [
        '/[กขคฆฅฃ]/u' => 'K',
        '/[จฉชซฌศษส]/u' => 'S',
        '/[ดฎตฏทธฑฒถฐ]/u' => 'T',
        '/[บปพภผฝฟ]/u' => 'P',
        '/[รลฬณน]/u' => 'N',
        '/[ง]/u' => 'G',
        '/[ม]/u' => 'M',
        '/[ว]/u' => 'W',
        '/[ยญ]/u' => 'Y',
        '/[อหฮ]/u' => 'H'
    ];
    
    $result = preg_replace(array_keys($map), array_values($map), $text);
    // Remove double phonetic letters (e.g. KK -> K)
    $result = preg_replace('/(.)\1+/', '$1', $result);
    if (empty($result)) return '';
    // Maximum 6 chars for soundex depth limit to prevent over-matching
    return mb_substr($result, 0, 6, 'UTF-8');
}
