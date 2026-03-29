<?php
/**
 * api.php
 * Handles JSON requests to save and load stories from SQLite
 */

require_once 'db.php';
header('Content-Type: application/json');

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
        $db->beginTransaction();
        
        $storyId = $data['id'] ?? null;
        $meta = $data['metadata'];
        
        $user = $_SESSION['user'];
        $role_id = $user['role_id'];
        $user_dept = $user['department_id'];
        $target_dept = $meta['department'];

        // 1. Permission Check: Can edit this department?
        if ($role_id == 1 || $role_id == 2) { // 1: Reporter, 2: Editor
            if ($target_dept != $user_dept) {
                echo json_encode(['success' => false, 'error' => 'Permission Denied: You can only create/edit stories in your own department.']);
                exit;
            }
        }

        // 2. Permission Check: Can approve?
        if ($meta['status'] === 'APPROVED') {
            if ($role_id == 1 || $role_id == 4) { // 1: Reporter, 4: Rewriter
                echo json_encode(['success' => false, 'error' => 'Permission Denied: You do not have permission to approve stories.']);
                exit;
            }
        }
        
        if ($storyId) {
            // Update existing story
            $stmt = $db->prepare("UPDATE stories SET slug=?, format=?, reporter=?, anchor=?, department_id=?, status=?, estimated_time=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $storyId]);
            
            // Delete old rows before inserting new ones
            $db->prepare("DELETE FROM story_rows WHERE story_id=?")->execute([$storyId]);
        } else {
            // Insert new story
            $stmt = $db->prepare("INSERT INTO stories (slug, format, reporter, anchor, department_id, status, estimated_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time']]);
            $storyId = $db->lastInsertId();
        }

        // Insert new rows
        $stmtRow = $db->prepare("INSERT INTO story_rows (story_id, type, left_column_json, right_column_text, word_count, estimated_read_time, row_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $index => $row) {
                // Ensure default values if undefined
                $type = $row['type'] ?? 'ON_CAMERA';
                $leftCol = isset($row['leftColumn']) ? json_encode($row['leftColumn']) : '{"cues":[]}';
                $text = check_right_col($row, 'text', '');
                $wc = check_right_col($row, 'wordCount', 0);
                $rt = check_right_col($row, 'readTimeSeconds', 0);

                $stmtRow->execute([
                    $storyId, 
                    $type, 
                    $leftCol, 
                    $text, 
                    $wc, 
                    $rt, 
                    $index
                ]);
            }
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'story_id' => $storyId]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }

} elseif ($method === 'GET' && $action === 'get_story') {
    $storyId = $_GET['id'] ?? null;
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
    
    $stmtRows = $db->prepare("SELECT * FROM story_rows WHERE story_id=? ORDER BY row_order ASC");
    $stmtRows->execute([$storyId]);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
    
    $content = array_map(function($row) {
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
    
    echo json_encode([
        'success' => true, 
        'data' => [
            'id' => $story['id'],
            'metadata' => [
                'slug' => $story['slug'],
                'format' => $story['format'],
                'reporter' => $story['reporter'],
                'anchor' => $story['anchor'],
                'department' => $story['department_id'],
                'status' => $story['status'],
                'estimated_time' => $story['estimated_time']
            ],
            'content' => $content
        ]
    ]);
} elseif ($method === 'GET' && $action === 'get_departments') {
    $stmt = $db->query("SELECT id, name FROM departments ORDER BY id ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'GET' && $action === 'get_users') {
    $stmt = $db->query("SELECT id, full_name as name FROM users ORDER BY full_name ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'GET' && $action === 'search_stories') {
    $dept_id = $_GET['department_id'] ?? '';
    $keyword = $_GET['keyword'] ?? '';
    $kw = "%$keyword%";
    
    $query = "SELECT DISTINCT s.id, s.slug, s.updated_at, d.name as department_name, s.status 
              FROM stories s
              LEFT JOIN departments d ON s.department_id = d.id
              LEFT JOIN story_rows sr ON s.id = sr.story_id
              WHERE 1=1";
    $params = [];
    
    if ($dept_id !== '') {
        $query .= " AND s.department_id = ?";
        $params[] = $dept_id;
    }
    
    if ($keyword !== '') {
        $query .= " AND (s.slug LIKE ? OR sr.right_column_text LIKE ?)";
        $params[] = $kw;
        $params[] = $kw;
    }
    
    $query .= " ORDER BY s.updated_at DESC LIMIT 50";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid Action']);
}

function check_right_col($row, $key, $default) {
    if (isset($row['rightColumn']) && isset($row['rightColumn'][$key])) {
        return $row['rightColumn'][$key];
    }
    return $default;
}
