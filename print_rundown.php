<?php
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'session_guard.php';
require_once 'db.php';

if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit;
}
$user = $_SESSION['user'];
$role_id = intval($user['role_id']);
if (!in_array($role_id, [2, 3])) {
    die("Permission Denied: Only Editors can print rundown.");
}

$id = intval($_GET['id'] ?? 0);
if (!$id) die("No rundown ID provided.");

$stmt = $db->prepare("SELECT * FROM rundowns WHERE id = ?");
$stmt->execute([$id]);
$rundown = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$rundown) die("Rundown not found.");

$stmtStories = $db->prepare("
    SELECT r.*, s.slug as title, s.format, s.estimated_time as trt, s.status, s.reporter, d.name as dept_name, s.current_version, s.id as story_id
    FROM rundown_stories r
    JOIN stories s ON r.story_id = s.id
    LEFT JOIN departments d ON s.department_id = d.id
    WHERE r.rundown_id = ?
    ORDER BY r.order_index ASC
");
$stmtStories->execute([$id]);
$items = $stmtStories->fetchAll(PDO::FETCH_ASSOC);

// Calculate total TRT
$totalTrtSeconds = 0;
foreach ($items as $itm) {
    if ($itm['is_break']) {
        $totalTrtSeconds += ($itm['break_duration'] ?? 180);
    } else {
        $totalTrtSeconds += intval($itm['trt']);
    }
}
$totalTrtMinutes = floor($totalTrtSeconds / 60);
$totalTrtSecsRem = $totalTrtSeconds % 60;
$totalTrtFormatted = sprintf("%02d:%02d", $totalTrtMinutes, $totalTrtSecsRem);

function formatTrt($secs) {
    $m = floor($secs / 60);
    $s = $secs % 60;
    return sprintf("%02d:%02d", $m, $s);
}

function extractFirstCueType($contentJson) {
    if (!$contentJson) return '';
    $arr = json_decode($contentJson, true);
    if (is_array($arr) && count($arr) > 0) {
        $row = $arr[0];
        $cues = $row['leftColumn']['cues'] ?? [];
        if (!empty($cues)) {
            return $cues[0]['type'] ?? '';
        }
    }
    return '';
}

if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rundown_' . $id . '_' . date('Ymd_His') . '.csv');
    // Output BOM for Excel UTF-8 compatibility
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Order', 'Format', 'Slug/Title', 'Director Cues', 'Reporter', 'TRT']);
    
    $order = 1;
    foreach ($items as $itm) {
        if ($itm['is_break']) {
            $breakTrt = $itm['break_duration'] ?? 180;
            fputcsv($output, [$order++, 'COMMERCIAL', '*** COMMERCIAL BREAK ***', '-', '-', formatTrt($breakTrt)]);
        } else {
            $format = $itm['format'] ?? 'N/A';
            $contentJson = '';
            $version = $itm['current_version'] ?? 1;
            $storyId = $itm['story_id'] ?? 0;
            $file_path = __DIR__ . "/data/stories/story_{$storyId}_v{$version}.json";
            if (file_exists($file_path)) {
                $contentJson = file_get_contents($file_path);
            }
            $dirCues = extractFirstCueType($contentJson);
            fputcsv($output, [$order++, $format, $itm['title'], $dirCues, $itm['reporter'] . ' (' . $itm['dept_name'] . ')', formatTrt($itm['trt'])]);
        }
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Print Rundown - <?php echo htmlspecialchars($rundown['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Sarabun', sans-serif; background: #fff; color: #000; padding: 20px; font-size: 11pt; }
        .print-container { max-width: 1000px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20pt; }
        .details-box { display: flex; flex-wrap: wrap; margin-bottom: 20px; border: 1px solid #000; padding: 10px; }
        .details-box div { width: 33%; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 6px; text-align: left; }
        th { background: #f0f0f0; font-weight: bold; }
        tr.break-row td { background: #e0e0e0; font-weight: bold; font-style: italic; }
        .printbtn { padding: 10px 20px; font-size: 16px; cursor: pointer; background:#4caf50; color:#fff; border:none; border-radius:4px; float:right; margin-left: 10px; }
        .csvbtn { padding: 10px 20px; font-size: 16px; cursor: pointer; background:#2196f3; color:#fff; border:none; border-radius:4px; float:right; text-decoration: none; }
        @media print {
            body { padding: 0; }
            .printbtn, .csvbtn { display: none; }
        }
    </style>
</head>
<body onload="window.print()">
    <div class="print-container">
        <button class="printbtn" onclick="window.print()">🖨 พิมพ์ (Print)</button>
        <a href="?id=<?php echo $id; ?>&action=export" class="csvbtn">📊 Export CSV</a>
        <div style="clear:both;"></div>

        <div class="header">
            <h1>Rundown: <?php echo htmlspecialchars($rundown['name']); ?></h1>
            <p>Target Broadcast: <?php echo htmlspecialchars($rundown['broadcast_time']); ?> | Print Time: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>

        <div class="details-box">
            <div><b>Target Duration:</b> N/A</div>
            <div><b>Total TRT:</b> <?php echo $totalTrtFormatted; ?></div>
            <div><b>Breaks:</b> <?php echo $rundown['commercial_break_count'] ?? 0; ?></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="5%">Order</th>
                    <th width="10%">Format</th>
                    <th width="35%">Slug / Title</th>
                    <th width="20%">Director Cues</th>
                    <th width="15%">Reporter</th>
                    <th width="15%">TRT</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $order = 1;
                foreach ($items as $itm): 
                    if ($itm['is_break']):
                        $breakTrt = $itm['break_duration'] ?? 180;
                ?>
                <tr class="break-row">
                    <td><?php echo $order++; ?></td>
                    <td>COMMERCIAL</td>
                    <td>*** COMMERCIAL BREAK ***</td>
                    <td>-</td>
                    <td>-</td>
                    <td><?php echo formatTrt($breakTrt); ?></td>
                </tr>
                <?php else: 
                    $format = $itm['format'] ?? 'N/A';
                    $contentJson = '';
                    $version = $itm['current_version'] ?? 1;
                    $storyId = $itm['story_id'] ?? 0;
                    $file_path = __DIR__ . "/data/stories/story_{$storyId}_v{$version}.json";
                    if (file_exists($file_path)) {
                        $contentJson = file_get_contents($file_path);
                    }
                    $dirCues = extractFirstCueType($contentJson);
                ?>
                <tr>
                    <td><?php echo $order++; ?></td>
                    <td><b><?php echo htmlspecialchars($format); ?></b></td>
                    <td><?php echo htmlspecialchars($itm['title']); ?></td>
                    <td><?php echo htmlspecialchars($dirCues); ?></td>
                    <td><?php echo htmlspecialchars($itm['reporter']); ?> <br><small>(<?php echo htmlspecialchars($itm['dept_name']); ?>)</small></td>
                    <td><?php echo formatTrt($itm['trt']); ?></td>
                </tr>
                <?php endif; endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

