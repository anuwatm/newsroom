<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'session_guard.php';
if (!isset($_SESSION['user'])) { die("Unauthorized"); }
require 'db.php';
$id = $_GET['id'] ?? null;
if ($id) $id = intval($id);
if (!$id) die("Missing Story ID");

$stmt = $db->prepare("SELECT s.*, d.name as department_name FROM stories s LEFT JOIN departments d ON s.department_id = d.id WHERE s.id = ?");
$stmt->execute([$id]);
$story = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$story) die("Story Not Found");

if ($story['is_deleted'] == 1) die("Story is deleted");

$user = $_SESSION['user'];
$role_id = $user['role_id'];
$user_dept = $user['department_id'];

if (($role_id == 1 || $role_id == 2) && $story['department_id'] != $user_dept) {
    die("Permission Denied: You cannot print stories from other departments.");
}

$version = $story['current_version'] ?? 1;
$file_path = "data/stories/story_" . $id . "_v" . $version . ".json";
$cues = [];
if (file_exists($file_path)) {
    $cues = json_decode(file_get_contents($file_path), true);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Print - <?php echo htmlspecialchars($story['slug']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-thai: 'Sarabun', sans-serif;
        }
        body { 
            background: #fff !important; 
            color: #000 !important; 
            font-family: var(--font-thai); 
            margin: 20px;
        }
        .print-header { 
            display: block; 
            border-bottom: 2px solid #000; 
            margin-bottom: 20px; 
            padding-bottom: 10px; 
        }
        .print-header h1 { 
            font-family: var(--font-thai); 
            font-size: 24pt; 
            margin-bottom: 10px; 
            margin-top: 0;
        }
        .print-meta { 
            display: flex; 
            gap: 30px; 
            font-size: 14pt; 
            font-family: var(--font-thai); 
        }
        .editor-container { 
            background: #fff; 
        }
        .script-row { 
            border: 1px solid #000; 
            display: flex; 
            margin-bottom: -1px; 
            padding: 12px; 
            page-break-inside: auto;
            break-inside: auto;
        }
        .col-left { 
            flex: 0 0 30%; 
            padding-right: 24px; 
        }
        .col-right { 
            flex: 1; 
            padding-left: 24px; 
            border-left: 1px solid #000; 
        }
        .print-cue-text { 
            font-size: 14pt; 
            line-height: 1.4; 
            white-space: pre-wrap; 
            word-break: break-word; 
            color: #000; 
            margin-bottom: 8px; 
        }
        .print-read-text { 
            font-family: var(--font-thai); 
            font-size: 18pt; 
            font-weight: 500; 
            line-height: 1.5; 
            white-space: pre-wrap; 
            word-break: break-word; 
            text-transform: uppercase; 
            color: #000; 
        }
        @media print {
            @page { size: A4; margin: 20mm 15mm; }
            body { 
                margin: 0 !important; 
                padding: 20mm 15mm !important; 
                box-sizing: border-box; 
            }
            .print-header { page-break-after: avoid; }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h1><?php echo htmlspecialchars($story['slug']); ?></h1>
        <div class="print-meta">
            <span><strong>Reporter:</strong> <?php echo htmlspecialchars($story['reporter'] ?? '-'); ?></span>
            <span><strong>Department:</strong> <?php echo htmlspecialchars($story['department_name'] ?? '-'); ?></span>
        </div>
    </div>
    
    <div class="editor-container">
        <div id="script-body">
            <?php if (empty($cues)): ?>
                <p style="font-size:14pt;">No script content available for this story.</p>
            <?php else: ?>
                <?php foreach ($cues as $row): ?>
                <div class="script-row">
                    <div class="col-left">
                        <?php if (!empty($row['leftColumn']['cues'])): ?>
                            <?php foreach ($row['leftColumn']['cues'] as $cue): ?>
                            <div class="cue-block">
                                <div class="print-cue-text"><strong>[<?php echo htmlspecialchars($cue['type'] ?? 'VC'); ?>]</strong><br/><?php echo nl2br(htmlspecialchars($cue['value'] ?? '')); ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-right">
                        <div class="print-read-text"><?php echo htmlspecialchars($row['rightColumn']['text'] ?? ''); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        window.onload = () => {
            setTimeout(() => {
                window.print();
                setTimeout(() => {
                    window.close();
                }, 500);
            }, 300);
        };
    </script>
</body>
</html>

