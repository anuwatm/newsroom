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
    $db->exec("ALTER TABLE stories ADD COLUMN current_version INTEGER DEFAULT 0");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN keywords TEXT");
} catch (Exception $e) { /* Column already exists */ }
try {
    $db->exec("ALTER TABLE stories ADD COLUMN keyword_soundex TEXT");
} catch (Exception $e) { /* Column already exists */ }

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
            // Update existing story
            $stmt = $db->prepare("SELECT current_version FROM stories WHERE id=?");
            $stmt->execute([$storyId]);
            $currentStory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $currentVersion = $currentStory['current_version'] ?? 0;
            // หากเป็น Auto-save ให้ใช้เวอร์ชันเก่าเซฟทับ หากผู้ใช้กด Save ให้ขึ้นเวอร์ชันใหม่
            $newVersion = ($is_autosave && $currentVersion > 0) ? $currentVersion : $currentVersion + 1;

            $stmt = $db->prepare("UPDATE stories SET slug=?, format=?, reporter=?, anchor=?, department_id=?, status=?, estimated_time=?, current_version=?, keywords=?, keyword_soundex=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $newVersion, $keywords, $soundexStr, $storyId]);
        } else {
            // Insert new story
            $stmt = $db->prepare("INSERT INTO stories (slug, format, reporter, anchor, department_id, status, estimated_time, current_version, keywords, keyword_soundex) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmt->execute([$meta['slug'], $meta['format'], $meta['reporter'], $meta['anchor'], $meta['department'], $meta['status'], $meta['estimated_time'], $keywords, $soundexStr]);
            $storyId = $db->lastInsertId();
        }

        // Save content to a versioned text file
        if (isset($data['content']) && is_array($data['content'])) {
            $filePath = $dataDir . '/story_' . $storyId . '_v' . $newVersion . '.json';
            file_put_contents($filePath, json_encode($data['content'], JSON_UNESCAPED_UNICODE));
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
    $stmt = $db->query("SELECT id, full_name as name FROM users ORDER BY full_name ASC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} elseif ($method === 'GET' && $action === 'search_stories') {
    $dept_id = $_GET['department_id'] ?? '';
    $keyword = $_GET['keyword'] ?? '';
    $kw = "%$keyword%";

    $query = "SELECT DISTINCT s.id, s.slug, s.updated_at, d.name as department_name, s.status 
              FROM stories s
              LEFT JOIN departments d ON s.department_id = d.id
              WHERE 1=1";
    $params = [];

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
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid Action']);
}

function check_right_col($row, $key, $default)
{
    if (isset($row['rightColumn']) && isset($row['rightColumn'][$key])) {
        return $row['rightColumn'][$key];
    }
    return $default;
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
    return mb_substr($result, 0, 8, 'UTF-8');
}
