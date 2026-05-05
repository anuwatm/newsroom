<?php
namespace App\Controllers;

use PDO;
use Exception;

class StoryController extends Controller {

    public function saveStory() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!$data) {
        $this->jsonResponse(false, [], 'Invalid JSON Payload');
    }

    try {
        // Validate CSRF token
        if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
            $this->jsonResponse(false, [], 'Invalid CSRF token. Security block.');
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
                    $this->jsonResponse(false, [], 'Permission Denied: You can only edit stories within your own department.');
                }
            }
        }
        
        // 1. Permission Check: Can edit this department (for newly created stories, or if somehow changing dept)
        if ($role_id == 1 || $role_id == 2) {
            if ($target_dept != $user_dept) {
                $db->rollBack();
                $this->jsonResponse(false, [], 'Permission Denied: You can only create/edit stories in your own department.');
            }
        }

        // 2. Permission Check: Can approve?
        if ($meta['status'] === 'APPROVED') {
            if ($role_id == 1 || $role_id == 4) { // 1: Reporter, 4: Rewriter
                $db->rollBack();
                $this->jsonResponse(false, [], 'Permission Denied: You do not have permission to approve stories.');
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
                $s2 = $this->thai_soundex($w);
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
                        $this->jsonResponse(false, [], "Story is currently locked by {$locked_by}. Please wait or try again later.");
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
            // à¸«à¸²à¸à¹€à¸›à¹‡à¸™ Auto-save à¹ƒà¸«à¹‰à¹ƒà¸Šà¹‰à¹€à¸§à¸­à¸£à¹Œà¸Šà¸±à¸™à¹€à¸à¹ˆà¸²à¹€à¸‹à¸Ÿà¸—à¸±à¸š à¸«à¸²à¸à¸œà¸¹à¹‰à¹ƒà¸Šà¹‰à¸à¸” Save à¹ƒà¸«à¹‰à¸‚à¸¶à¹‰à¸™à¹€à¸§à¸­à¸£à¹Œà¸Šà¸±à¸™à¹ƒà¸«à¸¡à¹ˆ
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

    }

    public function lockStory() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $storyId = $data['id'] ?? null;
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    if (!$storyId) {
        $this->jsonResponse(false, [], 'Missing story ID');
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
            $this->jsonResponse(false, ['locked' => true, 'locked_by' => 'Permission Denied'], 'Story is locked');
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
            $this->jsonResponse(false, ['locked' => true, 'locked_by' => $locked_by], 'Story is locked');
        }

        // Apply lock
        $stmt = $db->prepare("UPDATE stories SET locked_by=?, locked_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([$empId, $storyId]);
        echo json_encode(['success' => true, 'locked' => false]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Story not found']);
    }

    }

    public function unlockStory() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
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

    }

    public function getStory() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $storyId = $_GET['id'] ?? null;
    if ($storyId) $storyId = intval($storyId);
    
    if (!$storyId) {
        $this->jsonResponse(false, [], 'No story ID provided');
    }

    $stmt = $db->prepare("SELECT * FROM stories WHERE id=?");
    $stmt->execute([$storyId]);
    $story = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$story) {
        $this->jsonResponse(false, [], 'Story not found');
    }

    if ($story['is_deleted'] == 1) {
        $this->jsonResponse(false, [], 'Story has been deleted');
    }

    $user = $_SESSION['user'];
    $role_id = $user['role_id'];
    $user_dept = $user['department_id'];

    if (($role_id == 1 || $role_id == 2) && $story['department_id'] != $user_dept) {
        $this->jsonResponse(false, [], 'Permission Denied: You cannot read stories from other departments.');
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
    }

    public function searchStories() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
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
        
        $t_sound = $this->thai_soundex($keyword);
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
    }

    public function getMyStories() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
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
    }

    public function moveToBin() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $storyId = $data['id'] ?? null;
    $user = $_SESSION['user'];
    $authorId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    
    if (!$storyId) {
        $this->jsonResponse(false, [], 'Missing story ID');
    }
    
    $stmt = $db->prepare("UPDATE stories SET is_deleted = 1 WHERE id = ? AND author_id = ? AND status = 'DRAFT'");
    $stmt->execute([$storyId, $authorId]);
    
    if ($stmt->rowCount() > 0) {
        write_log('MOVE_TO_BIN', "Moved story ID {$storyId} to bin");
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Could not delete story. It may not belong to you or is not a DRAFT.']);
    }

    }

    public function getStoryVersions() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $storyId = intval($_GET['id'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        $this->jsonResponse(false, [], 'Permission Denied');
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

    }

    public function getStoryVersionData() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $storyId = intval($_GET['id'] ?? 0);
    $version = intval($_GET['version'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $filePath = __DIR__ . "/data/stories/story_{$storyId}_v{$version}.json";
    if (!file_exists($filePath)) {
        $this->jsonResponse(false, [], 'Version not found');
    }
    $content = json_decode(file_get_contents($filePath), true);
    echo json_encode(['success' => true, 'data' => $content]);

    }

    public function restoreStoryVersion() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    $storyId = intval($data['id'] ?? 0);
    $version = intval($data['version'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $filePath = __DIR__ . "/data/stories/story_{$storyId}_v{$version}.json";
    if (!file_exists($filePath)) {
        $this->jsonResponse(false, [], 'Version not found');
    }
    
    $content = json_decode(file_get_contents($filePath), true);
    $db->prepare("UPDATE stories SET current_version=? WHERE id=?")->execute([$version, $storyId]);
    write_log('RESTORE_VERSION', "Restored story ID {$storyId} to version {$version}");
    echo json_encode(['success' => true, 'content' => $content]);

    }

    public function getStoryComments() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $storyId = intval($_GET['id'] ?? 0);
    $user = $_SESSION['user'];
    $role_id = intval($user['role_id']);
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }

    $stmt = $db->prepare("SELECT c.*, u.full_name as author_name, r.name as role_name FROM story_comments c LEFT JOIN users u ON c.user_id = u.employee_id LEFT JOIN roles r ON u.role_id = r.id WHERE c.story_id = ? ORDER BY c.created_at ASC");
    $stmt->execute([$storyId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    }

    public function addStoryComment() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    $storyId = intval($data['id'] ?? 0);
    $msg = trim($data['message'] ?? '');
    $user = $_SESSION['user'];
    $empId = $user['employee_id'] ?? $user['id'] ?? $user['full_name'];
    $role_id = intval($user['role_id']);
    
    if (!$storyId || empty($msg)) {
        $this->jsonResponse(false, [], 'Missing data');
    }
    if (mb_strlen($msg) > 1000) {
        $this->jsonResponse(false, [], 'Comment is too long (max 1000 characters)');
    }
    
    $stmtC = $db->prepare("SELECT department_id FROM stories WHERE id = ?");
    $stmtC->execute([$storyId]);
    $storyDept = $stmtC->fetchColumn();
    if ($storyDept !== false && ($role_id == 1 || $role_id == 2) && $storyDept != $user['department_id']) {
        $this->jsonResponse(false, [], 'Permission Denied');
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


    }

    public function pingViewer() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
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

    }

    public function publishToCms() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
        $dataDir = __DIR__ . '/../../data/stories';
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    $storyId = intval($data['id'] ?? 0);
    $headline = trim($data['headline'] ?? '');
    
    if (!$storyId || empty($headline)) {
        $this->jsonResponse(false, [], 'Missing required data');
    }
    
    // Simulate publishing to a remote CMS (e.g., WordPress REST API)
    // In a real app, we would make a cURL request here.
    $mockDigitalUrl = "https://news.local/article/" . $storyId . "-" . time();
    
    $stmt = $db->prepare("UPDATE stories SET is_published = 1, digital_url = ? WHERE id = ?");
    $stmt->execute([$mockDigitalUrl, $storyId]);
    
    write_log('PUBLISH_CMS', "Published story ID {$storyId} to digital platform.");
    echo json_encode(['success' => true, 'url' => $mockDigitalUrl]);
    }

    private function thai_soundex($text) {
        if (empty($text)) return '';
    // Remove vowels, tone marks and special characters
    $text = preg_replace('/[à¸°-à¸¹à¹€-à¹„à¹†à¸¯à¸´-à¸·à¹Œ]/u', '', $text);
    
    // Convert to uppercase phonetic groups based loosely on LK82
    $map = [
        '/[à¸à¸‚à¸„à¸†à¸…à¸ƒ]/u' => 'K',
        '/[à¸ˆà¸‰à¸Šà¸‹à¸Œà¸¨à¸©à¸ª]/u' => 'S',
        '/[à¸”à¸Žà¸•à¸à¸—à¸˜à¸‘à¸’à¸–à¸]/u' => 'T',
        '/[à¸šà¸›à¸žà¸ à¸œà¸à¸Ÿ]/u' => 'P',
        '/[à¸£à¸¥à¸¬à¸“à¸™]/u' => 'N',
        '/[à¸‡]/u' => 'G',
        '/[à¸¡]/u' => 'M',
        '/[à¸§]/u' => 'W',
        '/[à¸¢à¸]/u' => 'Y',
        '/[à¸­à¸«à¸®]/u' => 'H'
    ];
    
    $result = preg_replace(array_keys($map), array_values($map), $text);
    }


}


