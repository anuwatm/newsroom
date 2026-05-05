<?php
namespace App\Controllers;

use PDO;
use Exception;

class RundownController extends Controller {

    public function createRundown() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied: Only Main Editor can create a Rundown.');
    }
    
    $program_id = $data['program_id'] ?? null;
    $name = trim($data['name'] ?? 'New Rundown');
    if (mb_strlen($name, 'UTF-8') > 255) {
        $this->jsonResponse(false, [], 'Name exceeds maximum length of 255 characters');
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

    }

    public function getRundowns() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $user = $_SESSION['user'];
    if ($user['role_id'] == 1 || $user['role_id'] == 4) { // Restrict Reporters and Rewriters
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    $stmt = $db->query("SELECT * FROM rundowns ORDER BY broadcast_time DESC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    }

    public function getRundownData() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $user = $_SESSION['user'];
    if ($user['role_id'] == 1 || $user['role_id'] == 4) { // Restrict Reporters and Rewriters
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $rundownId = intval($_GET['id'] ?? 0);
    if (!$rundownId) {
        $this->jsonResponse(false, [], 'Missing rundown ID');
    }
    
    $stmt = $db->prepare("SELECT * FROM rundowns WHERE id=?");
    $stmt->execute([$rundownId]);
    $rundown = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rundown) {
        $this->jsonResponse(false, [], 'Rundown not found');
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

    }

    public function addRundownStory() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $rundownId = $data['rundown_id'] ?? null;
    $storyId = $data['story_id'] ?? null;
    $user = $_SESSION['user'];
    
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    if (!$rundownId || !$storyId) {
        $this->jsonResponse(false, [], 'Missing IDs');
    }

    $stmtLock = $db->prepare("SELECT is_locked FROM rundowns WHERE id=?");
    $stmtLock->execute([$rundownId]);
    if ($stmtLock->fetchColumn() == 1) {
        $this->jsonResponse(false, [], 'Rundown is locked');
    }
    
    $stmtDup = $db->prepare("SELECT COUNT(*) FROM rundown_stories WHERE rundown_id=? AND story_id=?");
    $stmtDup->execute([$rundownId, $storyId]);
    if ($stmtDup->fetchColumn() > 0) {
        $this->jsonResponse(false, [], 'Story is already in this rundown.');
    }
    
    // Check if story is approved
    $stmtCheck = $db->prepare("SELECT status FROM stories WHERE id=?");
    $stmtCheck->execute([$storyId]);
    $story = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if (!$story || $story['status'] !== 'APPROVED') {
        $this->jsonResponse(false, [], 'Only APPROVED stories can be added to the rundown.');
    }

    $stmtMax = $db->prepare("SELECT MAX(order_index) FROM rundown_stories WHERE rundown_id=?");
    $stmtMax->execute([$rundownId]);
    $maxOrder = intval($stmtMax->fetchColumn()) + 1;
    
    $stmt = $db->prepare("INSERT INTO rundown_stories (rundown_id, story_id, order_index) VALUES (?, ?, ?)");
    $stmt->execute([$rundownId, $storyId, $maxOrder]);
    
    write_log('ADD_RUNDOWN_STORY', "Added story ID {$storyId} into rundown ID {$rundownId} at pos {$maxOrder}");
    echo json_encode(['success' => true]);

    }

    public function addRundownBreak() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $rundownId = $data['rundown_id'] ?? null;
    $duration = intval($data['duration'] ?? 180);
    $user = $_SESSION['user'];
    
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    if (!$rundownId) {
        $this->jsonResponse(false, [], 'Missing IDs');
    }

    $stmtLock = $db->prepare("SELECT is_locked FROM rundowns WHERE id=?");
    $stmtLock->execute([$rundownId]);
    if ($stmtLock->fetchColumn() == 1) {
        $this->jsonResponse(false, [], 'Rundown is locked');
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

    }

    public function updateRundownOrder() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $ids = $data['ids'] ?? [];
    $rundownId = $data['rundown_id'] ?? null;
    
    if (!$rundownId) {
        $this->jsonResponse(false, [], 'Missing rundown ID');
    }
    
    $stmtLock = $db->prepare("SELECT is_locked FROM rundowns WHERE id=?");
    $stmtLock->execute([$rundownId]);
    if ($stmtLock->fetchColumn() == 1) {
        $this->jsonResponse(false, [], 'Rundown is locked');
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

    }

    public function toggleRundownStoryDrop() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $rsId = $data['id'] ?? null;
    $isDropped = $data['is_dropped'] ?? 0;
    
    if ($rsId) {
        $stmtLock = $db->prepare("SELECT r.is_locked FROM rundowns r JOIN rundown_stories rs ON r.id = rs.rundown_id WHERE rs.id=?");
        $stmtLock->execute([$rsId]);
        if ($stmtLock->fetchColumn() == 1) {
            $this->jsonResponse(false, [], 'Rundown is locked');
        }

        $stmt = $db->prepare("UPDATE rundown_stories SET is_dropped=? WHERE id=?");
        $stmt->execute([$isDropped, $rsId]);
    }
    write_log('TOGGLE_DROP_STORY', "Toggled drop status for rundown_story ID {$rsId} to {$isDropped}");
    echo json_encode(['success' => true]);

    }

    public function toggleLockRundown() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
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

    }

    public function getPrograms() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $stmt = $db->query("SELECT * FROM programs ORDER BY name ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    }

    public function saveProgram() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $id = $data['id'] ?? null;
    $name = trim($data['name'] ?? '');
    $duration = intval($data['duration'] ?? 0);
    $breakCount = intval($data['break_count'] ?? 0);
    
    if (empty($name)) {
        $this->jsonResponse(false, [], 'Name cannot be empty');
    }
    if (mb_strlen($name, 'UTF-8') > 255) {
        $this->jsonResponse(false, [], 'Name exceeds maximum length of 255 characters');
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

    }

    public function deleteProgram() {
        $db = $this->db;
        $_SESSION['user'] = $this->user;
    $data = $this->getJsonPayload();
    if (!isset($data['csrf_token']) || $data['csrf_token'] !== $_SESSION['csrf_token']) {
        $this->jsonResponse(false, [], 'Invalid CSRF token.');
    }
    
    $user = $_SESSION['user'];
    if ($user['role_id'] != 3) {
        $this->jsonResponse(false, [], 'Permission Denied');
    }
    
    $id = $data['id'] ?? null;
    if ($id) {
        $stmt = $db->prepare("DELETE FROM programs WHERE id=?");
        $stmt->execute([$id]);
    }
    write_log('DELETE_PROGRAM', "Deleted master program ID {$id}");
    echo json_encode(['success' => true]);

    }


}
