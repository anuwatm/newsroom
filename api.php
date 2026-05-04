<?php
/**
 * api.php
 * Handles JSON requests to save and load stories from SQLite
 */

require_once 'db.php';
header('Content-Type: application/json');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

// --- API Rate Limiting ---
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $forwarded_ip = trim(reset($forwarded));
    if (filter_var($forwarded_ip, FILTER_VALIDATE_IP)) {
        $ip = $forwarded_ip;
    }
}

$currentTime = time();
if (mt_rand(1, 100) <= 5) {
    $db->exec("DELETE FROM api_rate_limits WHERE last_reset < " . ($currentTime - 3600));
}

$db->prepare("UPDATE api_rate_limits SET hits = hits + 1 WHERE ip = ? AND ? - last_reset <= 60")->execute([$ip, $currentTime]);
$stmt = $db->prepare("SELECT hits, last_reset FROM api_rate_limits WHERE ip = ?");
$stmt->execute([$ip]);
$rl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rl) {
    $db->prepare("INSERT INTO api_rate_limits (ip, hits, last_reset) VALUES (?, 1, ?)")->execute([$ip, $currentTime]);
} elseif ($currentTime - $rl['last_reset'] > 60) {
    $db->prepare("UPDATE api_rate_limits SET hits = 1, last_reset = ? WHERE ip = ?")->execute([$currentTime, $ip]);
} elseif ($rl['hits'] > 200) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded (200 requests per minute)']);
    exit;
}
// -------------------------

// Data directory check
$dataDir = __DIR__ . '/data/stories';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Schema creation moved to db.php

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'session_guard.php';
if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

// Global Presence Heartbeat
try {
    $stmtHeartbeat = $db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE employee_id = ?");
    $stmtHeartbeat->execute([$_SESSION['user']['employee_id']]);
} catch(Exception $e) {}

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
            // Update existing story
            $stmt = $db->prepare("SELECT current_version, status, author_id FROM stories WHERE id=?");
            $stmt->execute([$storyId]);
            $currentStory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $currentVersion = $currentStory['current_version'] ?? 0;
            $oldStatus = $currentStory['status'] ?? 'DRAFT';
            $authorId = $currentStory['author_id'] ?? null;
            // หากเป็น Auto-save ให้ใช้เวอร์ชันเก่าเซฟทับ หากผู้ใช้กด Save ให้ขึ้นเวอร์ชันใหม่
            $newVersion = ($is_autosave && $currentVersion > 0) ? $currentVersion : $currentVersion + 1;

            $stmt = $db->prepare("UPDATE stories SET slug=?, format=?, reporter=?, anchor=?, department_id=?, status=?, estimated_time=?, current_version=?, keywords=?, keyword_soundex=?, assignment_id=?, locked_by=?, locked_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $newVersion, $keywords, $soundexStr, $meta['assignment'] ?: null, ($user['employee_id'] ?? $user['id'] ?? $user['full_name']), $storyId]);
            
            if ($oldStatus !== $meta['status'] && in_array($meta['status'], ['APPROVED', 'REJECTED']) && $authorId) {
                $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")->execute([
                    $authorId,
                    "Your story #{$storyId} has been {$meta['status']}",
                    "index.php?id={$storyId}"
                ]);
            }
        } else {
            // Insert new story
            $authorId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
            $stmt = $db->prepare("INSERT INTO stories (slug, format, reporter, anchor, department_id, status, estimated_time, current_version, keywords, keyword_soundex, assignment_id, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $keywords, $soundexStr, $meta['assignment'] ?: null, $authorId]);
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
        if (!$is_autosave) {
            write_log('SAVE_STORY', "Saved story ID: {$storyId} (Slug: " . ($meta['slug'] ?? 'Unknown') . ")");
        }
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
                'assignment' => $story['assignment_id'],
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
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    if ($role_id == 1 || $role_id == 4) {
        $stmt = $db->prepare("SELECT employee_id as id, full_name as name FROM users WHERE department_id = ? ORDER BY full_name ASC");
        $stmt->execute([$user['department_id']]);
    } else {
        $stmt = $db->query("SELECT employee_id as id, full_name as name FROM users ORDER BY full_name ASC");
    }
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
        write_log('MOVE_TO_BIN', "Moved story ID {$storyId} to bin");
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
    $name = trim($data['name'] ?? 'New Rundown');
    if (mb_strlen($name, 'UTF-8') > 255) {
        echo json_encode(['success' => false, 'error' => 'Name exceeds maximum length of 255 characters']);
        exit;
    }
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
        
        $db->beginTransaction();
        for ($i = 0; $i < $breakCount; $i++) {
            $stmtRunStore->execute([$rundownId, $breakStoryId, $i + 1]);
        }
        $db->commit();
    }
    
    write_log('CREATE_RUNDOWN', "Created rundown ID: {$rundownId} ({$name})");
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
    
    $rundownId = intval($_GET['id'] ?? 0);
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

    try {
        $user_fullname = $_SESSION['user']['full_name'];
        $stmtLock = $db->prepare("SELECT is_locked, locked_by, locked_at FROM rundowns WHERE id=?");
        $stmtLock->execute([$rundownId]);
        $rLock = $stmtLock->fetch(PDO::FETCH_ASSOC);
        
        $can_lock = true;
        if ($rLock['is_locked'] == 1) {
            $can_lock = false;
        } elseif (!empty($rLock['locked_by']) && $rLock['locked_by'] !== $user_fullname) {
            if (!empty($rLock['locked_at']) && (time() - strtotime($rLock['locked_at'])) < 300) {
                $can_lock = false;
            }
        }
        
        if ($can_lock) {
            $db->prepare("UPDATE rundowns SET locked_by = ?, locked_at = CURRENT_TIMESTAMP WHERE id = ? AND (locked_by IS NULL OR locked_by = '' OR locked_by = ?)")
               ->execute([$user_fullname, $rundownId, $user_fullname]);
        }
    } catch(Exception $e) {}
    
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
    
    write_log('ADD_RUNDOWN_STORY', "Added story ID {$storyId} into rundown ID {$rundownId} at pos {$maxOrder}");
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
    
    write_log('ADD_RUNDOWN_BREAK', "Injected {$duration}s break into rundown ID {$rundownId}");
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
    $itemCount = count($ids);
    write_log('UPDATE_RUNDOWN_ORDER', "Re-arranged and saved order layout for {$itemCount} items in rundown ID {$rundownId}");
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
    write_log('TOGGLE_DROP_STORY', "Toggled drop status for rundown_story ID {$rsId} to {$isDropped}");
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
        $stmt = $db->prepare("UPDATE rundowns SET is_locked=?, locked_by=?, locked_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$isLocked, ($isLocked ? ($user['employee_id'] ?? $user['full_name']) : null), $rundownId]);

        if ($isLocked == 1) {
            // Snapshot the rundown
            $stmtRS = $db->prepare("SELECT rs.*, s.slug, s.status, s.format, s.estimated_time, s.updated_at, s.reporter, d.name as department_name FROM rundown_stories rs LEFT JOIN stories s ON rs.story_id = s.id LEFT JOIN departments d ON s.department_id = d.id WHERE rs.rundown_id = ? ORDER BY rs.order_index ASC");
            $stmtRS->execute([$rundownId]);
            $stories = $stmtRS->fetchAll(PDO::FETCH_ASSOC);
            $snapshotData = json_encode($stories, JSON_UNESCAPED_UNICODE);
            
            $db->prepare("INSERT INTO rundown_snapshots (rundown_id, snapshot_data, created_by) VALUES (?, ?, ?)")
               ->execute([$rundownId, $snapshotData, $user['employee_id'] ?? $user['full_name']]);
        }
    }
    $statusText = $isLocked ? 'LOCKED' : 'UNLOCKED';
    write_log('TOGGLE_LOCK_RUNDOWN', "{$statusText} rundown ID {$rundownId}");
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
    if (mb_strlen($name, 'UTF-8') > 255) {
        echo json_encode(['success' => false, 'error' => 'Name exceeds maximum length of 255 characters']);
        exit;
    }

    if ($id) {
        $stmt = $db->prepare("UPDATE programs SET name=?, duration=?, break_count=? WHERE id=?");
        $stmt->execute([$name, $duration, $breakCount, $id]);
    } else {
        $stmt = $db->prepare("INSERT INTO programs (name, duration, break_count) VALUES (?, ?, ?)");
        $stmt->execute([$name, $duration, $breakCount]);
    }
    write_log('SAVE_PROGRAM', "Saved master program config: {$name}");
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
    write_log('DELETE_PROGRAM', "Deleted master program ID {$id}");
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

    if (!empty($password)) {
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 8 characters and contain both letters and numbers']);
            exit;
        }
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
    write_log('SAVE_USER', "Configured user account mapping for employee ID: {$employee_id}");
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
    write_log('DELETE_USER', "Deleted user account mapping for employee ID: {$employee_id}");
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
    if (mb_strlen($name, 'UTF-8') > 255) {
        echo json_encode(['success' => false, 'error' => 'Department name exceeds maximum length of 255 characters']);
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
    write_log('SAVE_DEPARTMENT', "Saved configuration for department: {$name}");
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

        $stmtCheck3 = $db->prepare("SELECT COUNT(*) FROM assignments WHERE department_id=?");
        $stmtCheck3->execute([$id]);
        if ($stmtCheck3->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete: Department is assigned to existing Assignments.']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM departments WHERE id=?");
        $stmt->execute([$id]);
    }
    write_log('DELETE_DEPARTMENT', "Deleted department ID: {$id}");
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

    // 2 & 3. Total active stories & Status breakdown
    $stmtStatus = $db->query("SELECT status, COUNT(*) as count FROM stories WHERE is_deleted = 0 AND format != 'BREAK' GROUP BY status");
    $statusCounts = [];
    $totalStories = 0;
    while ($row = $stmtStatus->fetch(PDO::FETCH_ASSOC)) {
        $statusCounts[$row['status']] = (int)$row['count'];
        $totalStories += (int)$row['count'];
    }
    $stats['status_counts'] = $statusCounts;
    $stats['total_stories'] = $totalStories;

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

    // 6. Upcoming broadcasts with TRT Calculation
    $stmtRun = $db->query("
        SELECT r.id, r.name, r.broadcast_time, r.target_trt,
               COALESCE(trt.script_time, 0) + COALESCE(trt.total_break, 0) as current_trt
        FROM rundowns r 
        LEFT JOIN (
            SELECT rs.rundown_id, 
                   SUM(CASE WHEN rs.is_break = 0 THEN s.estimated_time ELSE 0 END) as script_time, 
                   SUM(rs.break_duration) as total_break 
            FROM rundown_stories rs 
            LEFT JOIN stories s ON rs.story_id = s.id 
            WHERE rs.is_dropped = 0 
            GROUP BY rs.rundown_id
        ) as trt ON r.id = trt.rundown_id
        ORDER BY r.broadcast_time DESC 
        LIMIT 5
    ");
    $stats['upcoming_rundowns'] = $stmtRun->fetchAll(PDO::FETCH_ASSOC);

    // 7 & 8. Total assignments & Assignment status breakdown
    $stmtAssStat = $db->query("SELECT status, COUNT(*) as count FROM assignments GROUP BY status");
    $astatusCounts = [];
    $totalAssignments = 0;
    while ($row = $stmtAssStat->fetch(PDO::FETCH_ASSOC)) {
        $astatusCounts[$row['status']] = (int)$row['count'];
        $totalAssignments += (int)$row['count'];
    }
    $stats['assignment_counts'] = $astatusCounts;
    $stats['total_assignments'] = $totalAssignments;

    // 9. Equipment stats & Critical Alerts
    $stmtEq1 = $db->query("SELECT SUM(total_units) FROM equipment_master WHERE is_active = 1");
    $stats['total_equipment'] = (int)$stmtEq1->fetchColumn();

    $stmtEq2 = $db->query("SELECT SUM(ae.quantity) FROM assignment_equipment ae JOIN assignments a ON ae.assignment_id = a.id WHERE a.status IN ('PENDING', 'APPROVED')");
    $stats['borrowed_equipment'] = (int)$stmtEq2->fetchColumn();

    // 10. Reporter Productivity (Top 5 Authors)
    $stmtTopRep = $db->query("
        SELECT author_id, 
               COUNT(*) as count,
               SUM(CASE WHEN status='APPROVED' THEN 1 ELSE 0 END) as approved_count,
               AVG(estimated_time) as avg_time
        FROM stories 
        WHERE is_deleted = 0 AND author_id IS NOT NULL AND author_id != '' 
        GROUP BY author_id 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $stats['top_reporters'] = $stmtTopRep->fetchAll(PDO::FETCH_ASSOC);

    // 11. Recent Activity Feed (Stories + Assignments)
    $stmtAct1 = $db->query("SELECT 'Story' as type, slug as title, updated_at as timestamp, status, updated_at FROM stories WHERE is_deleted = 0 ORDER BY updated_at DESC LIMIT 5");
    $act1 = $stmtAct1->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtAct2 = $db->query("SELECT 'Assignment' as type, title, updated_at as timestamp, status, updated_at FROM assignments ORDER BY updated_at DESC LIMIT 5");
    $act2 = $stmtAct2->fetchAll(PDO::FETCH_ASSOC);

    $all_activity = array_merge($act1, $act2);
    usort($all_activity, function($a, $b) {
        return strtotime($b['updated_at']) - strtotime($a['updated_at']);
    });
    $stats['recent_activity'] = array_slice($all_activity, 0, 8);

    echo json_encode(['success' => true, 'data' => $stats]);
    exit;

} elseif ($method === 'GET' && $action === 'get_dashboard_live') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']);
        exit;
    }

    $live = [];

    // Critical equipment (0 units left)
    $stmtCritEq = $db->query("
        SELECT em.name, em.total_units, 
               COALESCE((SELECT SUM(quantity) FROM assignment_equipment ae JOIN assignments a ON ae.assignment_id = a.id WHERE a.status IN ('PENDING', 'APPROVED') AND ae.equipment_name = em.name), 0) as borrowed
        FROM equipment_master em
        WHERE em.is_active = 1
    ");
    $criticalEq = [];
    while ($row = $stmtCritEq->fetch(PDO::FETCH_ASSOC)) {
        if (($row['total_units'] - $row['borrowed']) <= 0) {
            $criticalEq[] = $row['name'];
        }
    }
    $live['critical_equipment'] = $criticalEq;

    // Workflow Bottlenecks
    $live['bottleneck_reviews'] = (int)$db->query("SELECT COUNT(*) FROM stories WHERE status = 'REVIEW'")->fetchColumn();
    $live['bottleneck_assignments'] = (int)$db->query("SELECT COUNT(*) FROM assignments WHERE status = 'PENDING'")->fetchColumn();

    // Live Monitoring - Online Users
    $stmtOnl = $db->query("SELECT u.full_name, d.name as dept_name, u.last_seen 
                           FROM users u LEFT JOIN departments d ON u.department_id = d.id 
                           WHERE u.last_seen > datetime('now', '-5 minutes')
                           ORDER BY u.last_seen DESC LIMIT 10");
    $live['online_users'] = $stmtOnl->fetchAll(PDO::FETCH_ASSOC);

    // Live Monitoring - Active Stories
    $stmtActS = $db->query("SELECT slug as title, locked_by as editor, locked_at 
                            FROM stories 
                            WHERE is_deleted = 0 AND locked_by IS NOT NULL 
                            AND locked_by != '' 
                            AND locked_at > datetime('now', '-5 minutes')
                            ORDER BY locked_at DESC");
    $live['active_stories'] = $stmtActS->fetchAll(PDO::FETCH_ASSOC);

    // Live Monitoring - Active Rundowns
    $stmtActR = $db->query("SELECT name as title, locked_by as editor, locked_at 
                            FROM rundowns 
                            WHERE locked_by IS NOT NULL 
                            AND locked_by != '' 
                            AND locked_at > datetime('now', '-5 minutes')
                            ORDER BY locked_at DESC");
    $live['active_rundowns'] = $stmtActR->fetchAll(PDO::FETCH_ASSOC);

    // Live Monitoring - Today's Field Assignments
    $stmtTodayA = $db->query("SELECT a.title, a.reporter_name as assignee, at.location_name as location, at.start_time as time 
                              FROM assignments a 
                              JOIN assignment_trips at ON a.id = at.assignment_id 
                              WHERE a.status = 'APPROVED' 
                              AND at.trip_date = date('now')
                              ORDER BY at.start_time ASC");
    $live['today_assignments'] = $stmtTodayA->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $live]);
    exit;





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
        $dept = $user_dept; // Force scope to user's assigned department
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

    $assIds = array_column($assignments, 'id');
    if (!empty($assIds)) {
        $inPart = implode(',', array_fill(0, count($assIds), '?'));
        
        $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id IN ($inPart) ORDER BY trip_date ASC, start_time ASC");
        $stmtT->execute($assIds);
        $allTrips = $stmtT->fetchAll(PDO::FETCH_ASSOC);
        $tripsByAss = [];
        foreach ($allTrips as $t) {
            $tripsByAss[$t['assignment_id']][] = $t;
        }

        $stmtE = $db->prepare("SELECT e.*, m.name FROM assignment_equipment e LEFT JOIN equipment_master m ON e.equipment_name = m.name WHERE e.assignment_id IN ($inPart)");
        $stmtE->execute($assIds);
        $allEq = $stmtE->fetchAll(PDO::FETCH_ASSOC);
        $eqByAss = [];
        foreach ($allEq as $e) {
            $eqByAss[$e['assignment_id']][] = $e;
        }

        foreach ($assignments as &$ass) {
            $ass['trips'] = $tripsByAss[$ass['id']] ?? [];
            $ass['equipment'] = $eqByAss[$ass['id']] ?? [];
        }
    } else {
        foreach ($assignments as &$ass) {
            $ass['trips'] = [];
            $ass['equipment'] = [];
        }
    }
    echo json_encode(['success' => true, 'data' => $assignments]);

} elseif ($method === 'GET' && $action === 'get_assignment_detail') {
    $id = intval($_GET['id'] ?? 0);
    $stmt = $db->prepare("SELECT a.*, d.name as department_name FROM assignments a LEFT JOIN departments d ON a.department_id = d.id WHERE a.id = ?");
    $stmt->execute([$id]);
    $ass = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ass) {
        $user = $_SESSION['user'];
        $role_id = intval($user['role_id']);
        $user_emp_id = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $has_permission = true;

        if ($role_id == 1 || $role_id == 4) {
            if ($ass['reporter_id'] !== $user_emp_id && $ass['created_by'] !== $user_emp_id) {
                $has_permission = false;
            }
        } elseif ($role_id == 2) {
            if ($ass['department_id'] != $user['department_id']) {
                $has_permission = false;
            }
        }

        if (!$has_permission) {
            echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
        }

        $stmtT = $db->prepare("SELECT * FROM assignment_trips WHERE assignment_id = ? ORDER BY trip_date ASC, start_time ASC");
        $stmtT->execute([$id]);
        $ass['trips'] = $stmtT->fetchAll(PDO::FETCH_ASSOC);

        $stmtE = $db->prepare("SELECT * FROM assignment_equipment WHERE assignment_id = ?");
        $stmtE->execute([$id]);
        $ass['equipment'] = $stmtE->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtS = $db->prepare("SELECT id, slug, status FROM stories WHERE assignment_id = ? AND is_deleted = 0");
        $stmtS->execute([$id]);
        $ass['linked_stories'] = $stmtS->fetchAll(PDO::FETCH_ASSOC);
        
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
    if (empty($title)) {
        echo json_encode(['success' => false, 'error' => 'Title is required.']); exit;
    }
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
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['trip_date']) || !preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $t['start_time'])) {
                throw new Exception("Invalid date or time format.");
            }
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
        write_log('CREATE_ASSIGNMENT', "Created assignment ID {$assignmentId} ({$title})");
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
        $title = trim($data['title'] ?? '');
        if (empty($title)) throw new Exception('Title is required.');
        $stmtU = $db->prepare("UPDATE assignments SET title=?, description=?, reporter_id=?, reporter_name=?, department_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmtU->execute([$title, $data['description'] ?? '', $data['reporter_id'], $data['reporter_name'], $data['department_id'], $assignmentId]);
        
        $stmtDelTrips = $db->prepare("DELETE FROM assignment_trips WHERE assignment_id = ?");
        $stmtDelTrips->execute([$assignmentId]);
        $stmtDelEq = $db->prepare("DELETE FROM assignment_equipment WHERE assignment_id = ?");
        $stmtDelEq->execute([$assignmentId]);
        
        $trips = $data['trips'] ?? [];
        if (empty($trips)) throw new Exception('At least 1 trip required.');
        $stmtT = $db->prepare("INSERT INTO assignment_trips (assignment_id, trip_date, start_time, end_time, location_name, location_detail, order_index) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($trips as $i => $t) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $t['trip_date']) || !preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $t['start_time'])) {
                throw new Exception("Invalid date or time format.");
            }
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
        write_log('UPDATE_ASSIGNMENT', "Updated assignment ID {$assignmentId} ({$title})");
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
        echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
    }
    
    if ($role_id != 3) {
        if ($ass['created_by'] !== $user_emp_id || $ass['status'] !== 'PENDING') {
            echo json_encode(['success' => false, 'error' => 'Permission Denied: Can only delete your own PENDING assignments.']); exit;
        }
    }
    
    $stmtDel = $db->prepare("UPDATE assignments SET status='DELETED' WHERE id=?");
    $stmtDel->execute([$id]);
    write_log('DELETE_ASSIGNMENT', "Marked assignment ID {$id} as DELETED");
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
    if (!$ass) {
        echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
    }
    if ($role_id == 2 && $ass['department_id'] != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    if ($ass['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'Must be PENDING']); exit;
    }
    
    $stmtA = $db->prepare("UPDATE assignments SET status='APPROVED', approved_by=?, approved_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmtA->execute([$user['employee_id'] ?? $user['id'] ?? $user['full_name'], $id]);
    write_log('APPROVE_ASSIGNMENT', "Approved assignment ID {$id}");
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
    if (!$ass) {
        echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
    }
    if ($role_id == 2 && $ass['department_id'] != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    if ($ass['status'] !== 'PENDING') {
        echo json_encode(['success' => false, 'error' => 'Must be PENDING']); exit;
    }
    
    $stmtA = $db->prepare("UPDATE assignments SET status='REJECTED', rejection_note=? WHERE id=?");
    $stmtA->execute([$note, $id]);
    write_log('REJECT_ASSIGNMENT', "Rejected assignment ID {$id}");
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
    
    if (!$ass) {
        echo json_encode(['success' => false, 'error' => 'Assignment not found']); exit;
    }

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
    write_log('COMPLETE_ASSIGNMENT', "Completed assignment ID {$id}");
    echo json_encode(['success' => true]);

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
        write_log('SAVE_EQUIPMENT', "Saved equipment entry: {$name}");
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Duplicate item name or server error']);
    }

} elseif ($method === 'GET' && $action === 'check_equipment_availability') {
    $date = $_GET['date'] ?? '';
    $equipment = $_GET['equipment'] ?? '';
    $qty = intval($_GET['qty'] ?? 1);
    
    if (empty($date) || empty($equipment)) {
        echo json_encode(['success' => false, 'error' => 'Missing date or equipment name']); exit;
    }
    
    $stmtMaster = $db->prepare("SELECT total_units FROM equipment_master WHERE name = ? AND is_active = 1");
    $stmtMaster->execute([$equipment]);
    $total_units = $stmtMaster->fetchColumn();
    if ($total_units === false) {
        echo json_encode(['success' => false, 'error' => 'Equipment not found or inactive']); exit;
    }
    
    // Find how many are already requested for that day
    $stmtUsed = $db->prepare("
        SELECT SUM(ae.quantity) 
        FROM assignment_equipment ae 
        JOIN assignments a ON ae.assignment_id = a.id 
        WHERE ae.equipment_name = ? 
        AND a.status IN ('PENDING', 'APPROVED')
        AND EXISTS (SELECT 1 FROM assignment_trips t WHERE t.assignment_id = a.id AND t.trip_date = ?)
    ");
    $stmtUsed->execute([$equipment, $date]);
    $used_units = intval($stmtUsed->fetchColumn() ?: 0);
    
    $remaining = $total_units - $used_units;
    $available = ($remaining >= $qty);
    
    echo json_encode(['success' => true, 'available' => $available, 'remaining' => $remaining, 'total' => $total_units]);

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
            write_log('DELETE_EQUIPMENT', "Deleted equipment ID {$id} ({$name})");
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
    $fDept = $_GET['department_id'] ?? '';

    $where = ["a.status != 'DELETED'", "t.trip_date LIKE ?"];
    $params = [$month . '-%'];

    if ($role_id == 2) {
        $fDept = $user_dept; // Force scope to user's assigned department
    }

    if ($fDept) {
        $where[] = "a.department_id = ?";
        $params[] = $fDept;
    }

    if ($role_id == 1 || $role_id == 4) {
        $where[] = "a.reporter_id = ?";
        $params[] = $user_emp_id;
    }

    $whereStr = implode(" AND ", $where);
    $sql = "SELECT t.trip_date as date, a.id as assignment_id, a.title, a.status, a.reporter_name, t.location_name, a.department_id
            FROM assignment_trips t
            JOIN assignments a ON t.assignment_id = a.id
            WHERE $whereStr";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assIds = array_unique(array_column($trips, 'assignment_id'));
    if (!empty($assIds)) {
        $inPart = implode(',', array_fill(0, count($assIds), '?'));
        
        $stmtEQ = $db->prepare("SELECT assignment_id, equipment_name FROM assignment_equipment WHERE assignment_id IN ($inPart)");
        $stmtEQ->execute(array_values($assIds));
        $allEq = $stmtEQ->fetchAll(PDO::FETCH_ASSOC);
        $eqByAss = [];
        foreach ($allEq as $e) {
            $eqByAss[$e['assignment_id']][] = $e['equipment_name'];
        }

        foreach ($trips as &$t) {
            $t['equipment'] = $eqByAss[$t['assignment_id']] ?? [];
        }
    } else {
        foreach ($trips as &$t) {
            $t['equipment'] = [];
        }
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

} elseif ($method === 'GET' && $action === 'get_log_files') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $log_dir = __DIR__ . '/data/log';
    $files = [];
    if (is_dir($log_dir)) {
        $found = glob($log_dir . '/*.log');
        foreach ($found as $filepath) {
            $files[] = basename($filepath);
        }
    }
    rsort($files); // newest first
    echo json_encode(['success' => true, 'data' => $files]);

} elseif ($method === 'GET' && $action === 'get_log_content') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $filename = $_GET['file'] ?? '';
    // Security check to prevent directory traversal
    if (empty($filename) || preg_match('/[^a-zA-Z0-9_\-\.]/', $filename) || strpos($filename, '..') !== false) {
        echo json_encode(['success' => false, 'error' => 'Invalid filename']); exit;
    }
    
    $filepath = __DIR__ . '/data/log/' . $filename;
    if (!file_exists($filepath)) {
        echo json_encode(['success' => false, 'error' => 'File not found']); exit;
    }

    $lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $parsed_logs = [];
    foreach ($lines as $line) {
        if (preg_match('/^\[(.*?)\] \[(.*?)\] \[(.*?)\] \[(.*?)\] \[(.*?)\] (.*)$/', $line, $matches)) {
            $parsed_logs[] = [
                'time' => $matches[1],
                'level' => $matches[2],
                'ip' => $matches[3],
                'user' => $matches[4],
                'action' => $matches[5],
                'details' => $matches[6]
            ];
        } else {
            $parsed_logs[] = [
                'time' => '',
                'level' => 'INFO',
                'ip' => '',
                'user' => '',
                'action' => 'RAW',
                'details' => $line
            ];
        }
    }
    // Return chronological order (first event at the top)
    echo json_encode(['success' => true, 'data' => $parsed_logs]);

} elseif ($method === 'GET' && $action === 'get_story_versions') {
    $storyId = intval($_GET['id'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }

    $versions = [];
    $dataDir = __DIR__ . '/data/stories';
    if ($storyId > 0 && is_dir($dataDir)) {
        $files = glob($dataDir . "/story_{$storyId}_v*.json");
        foreach ($files as $f) {
            if (preg_match('/_v(\d+)\.json$/', $f, $m)) {
                $versions[] = ['version' => intval($m[1]), 'file' => basename($f), 'time' => filemtime($f)];
            }
        }
    }
    rsort($versions);
    echo json_encode(['success' => true, 'data' => $versions]);

} elseif ($method === 'GET' && $action === 'get_story_version_data') {
    $storyId = intval($_GET['id'] ?? 0);
    $version = intval($_GET['version'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    
    $filePath = __DIR__ . "/data/stories/story_{$storyId}_v{$version}.json";
    if (!file_exists($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Version not found']); exit;
    }
    $content = json_decode(file_get_contents($filePath), true);
    echo json_encode(['success' => true, 'data' => $content]);

} elseif ($method === 'POST' && $action === 'restore_story_version') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $storyId = intval($data['id'] ?? 0);
    $version = intval($data['version'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    
    $filePath = __DIR__ . "/data/stories/story_{$storyId}_v{$version}.json";
    if (!file_exists($filePath)) {
        echo json_encode(['success' => false, 'error' => 'Version not found']); exit;
    }
    
    $content = json_decode(file_get_contents($filePath), true);
    $db->prepare("UPDATE stories SET current_version=? WHERE id=?")->execute([$version, $storyId]);
    write_log('RESTORE_VERSION', "Restored story ID {$storyId} to version {$version}");
    echo json_encode(['success' => true, 'content' => $content]);

} elseif ($method === 'GET' && $action === 'get_story_comments') {
    $storyId = intval($_GET['id'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }

    $stmt = $db->prepare("SELECT c.*, u.full_name as author_name, r.name as role_name FROM story_comments c LEFT JOIN users u ON c.user_id = u.employee_id LEFT JOIN roles r ON u.role_id = r.id WHERE c.story_id = ? ORDER BY c.created_at ASC");
    $stmt->execute([$storyId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST' && $action === 'add_story_comment') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $storyId = intval($data['id'] ?? 0);
    $msg = trim($data['message'] ?? '');
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $role_id = intval($user['role_id']);
    
    if (!$storyId || empty($msg)) {
        echo json_encode(['success' => false, 'error' => 'Missing data']); exit;
    }
    if (mb_strlen($msg) > 1000) {
        echo json_encode(['success' => false, 'error' => 'Comment is too long (max 1000 characters)']); exit;
    }
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }

    $stmt = $db->prepare("INSERT INTO story_comments (story_id, user_id, message) VALUES (?, ?, ?)");
    $stmt->execute([$storyId, $empId, $msg]);
    
    // Parse @mentions
    preg_match_all('/@([a-zA-Z0-9_]+)/', $msg, $matches);
    if (!empty($matches[1])) {
        foreach (array_unique($matches[1]) as $mUser) {
            $stmtM = $db->prepare("SELECT employee_id FROM users WHERE employee_id = ?");
            $stmtM->execute([$mUser]);
            if ($stmtM->fetchColumn()) {
                $db->prepare("INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)")->execute([
                    $mUser,
                    "{$empId} mentioned you in story #{$storyId}",
                    "index.php?id={$storyId}"
                ]);
            }
        }
    }

    write_log('ADD_COMMENT', "Added comment to story ID {$storyId}");
    echo json_encode(['success' => true]);


} elseif ($method === 'GET' && $action === 'get_system_settings') {
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    echo json_encode(['success' => true, 'data' => $settings]);

} elseif ($method === 'POST' && $action === 'save_system_settings') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        echo json_encode(['success' => false, 'error' => 'Permission Denied']); exit;
    }
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value");
    $db->beginTransaction();
    foreach ($data['settings'] as $key => $val) {
        $stmt->execute([$key, strval($val)]);
    }
    $db->commit();
    write_log('SAVE_SETTINGS', 'Updated system settings');
    echo json_encode(['success' => true]);

} elseif ($method === 'GET' && $action === 'get_notifications') {
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$empId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} elseif ($method === 'POST' && $action === 'mark_notification_read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($data['id'] ?? 0);
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    if ($id === 0) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$empId]);
    } else {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $empId]);
    }
    echo json_encode(['success' => true]);

} elseif ($method === 'POST' && $action === 'ping_viewer') {
    $data = json_decode(file_get_contents('php://input'), true);
    $storyId = intval($data['id'] ?? 0);
    if ($storyId > 0) {
        $user = $_SESSION['user'];
        $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
        $name = $user['full_name'] ?? $empId;
        $now = time();
        $stmt = $db->prepare("INSERT INTO active_viewers (story_id, user_id, user_name, last_seen) VALUES (?, ?, ?, ?) ON CONFLICT(story_id, user_id) DO UPDATE SET last_seen = excluded.last_seen");
        $stmt->execute([$storyId, $empId, $name, $now]);
        $db->exec("DELETE FROM active_viewers WHERE last_seen < " . ($now - 30));
        
        $stmt2 = $db->prepare("SELECT user_name FROM active_viewers WHERE story_id = ? AND user_id != ?");
        $stmt2->execute([$storyId, $empId]);
        echo json_encode(['success' => true, 'viewers' => $stmt2->fetchAll(PDO::FETCH_COLUMN)]);
    } else {
        echo json_encode(['success' => false]);
    }

} elseif ($method === 'POST' && $action === 'publish_to_cms') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
    $storyId = intval($data['id'] ?? 0);
    $headline = trim($data['headline'] ?? '');
    
    if (!$storyId || empty($headline)) {
        echo json_encode(['success' => false, 'error' => 'Missing required data']); exit;
    }
    
    // Simulate publishing to a remote CMS (e.g., WordPress REST API)
    // In a real app, we would make a cURL request here.
    $mockDigitalUrl = "https://news.local/article/" . $storyId . "-" . time();
    
    $stmt = $db->prepare("UPDATE stories SET is_published = 1, digital_url = ? WHERE id = ?");
    $stmt->execute([$mockDigitalUrl, $storyId]);
    
    write_log('PUBLISH_CMS', "Published story ID {$storyId} to digital platform.");
    echo json_encode(['success' => true, 'url' => $mockDigitalUrl]);

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

