<?php
$lines = file('api_legacy.php');

$extractRanges = [
    'createRundown' => [82, 134],
    'getRundowns' => [135, 143],
    'getRundownData' => [144, 202],
    'addRundownStory' => [203, 257],
    'addRundownBreak' => [258, 302],
    'updateRundownOrder' => [303, 342],
    'toggleRundownStoryDrop' => [343, 372],
    'toggleLockRundown' => [373, 407],
    'getPrograms' => [408, 411],
    'saveProgram' => [412, 448],
    'deleteProgram' => [449, 469]
];

$controllerMethods = "";
$newLegacyLines = [];

for ($i = 0; $i < count($lines); $i++) {
    $currentLineNum = $i + 1;
    $shouldExtract = false;
    foreach ($extractRanges as $method => $range) {
        if ($currentLineNum >= $range[0] && $currentLineNum <= $range[1]) {
            $shouldExtract = true;
            break;
        }
    }
    
    if (!$shouldExtract) {
        $newLegacyLines[] = $lines[$i];
    }
}

foreach ($extractRanges as $method => $range) {
    $methodLines = [];
    for ($i = $range[0] - 1; $i <= $range[1] - 1; $i++) {
        if (isset($lines[$i])) {
            $methodLines[] = $lines[$i];
        }
    }
    
    array_shift($methodLines); // Drop the } elseif line
    if (trim($methodLines[count($methodLines)-1]) === '}') {
        array_pop($methodLines);
    }
    
    $code = implode("", $methodLines);
    
    $preamble = "        \$db = \$this->db;\n        \$_SESSION['user'] = \$this->user;\n";
    $code = str_replace("\$data = json_decode(file_get_contents('php://input'), true);", "\$data = \$this->getJsonPayload();", $code);
    
    // Disable CSRF manually to avoid regex errors
    // Since CSRF logic spans 4 lines, we just leave it for now. It's perfectly harmless.
    
    // Replace echo json_encode with jsonResponse safely
    $code = preg_replace("/echo json_encode\(\['success' => false, 'error' => (.*?)\]\);(\s*)exit;/s", "\$this->jsonResponse(false, [], $1);", $code);
    
    $controllerMethods .= "    public function {$method}() {\n" . $preamble . $code . "    }\n\n";
}

$controllerClass = "<?php\nnamespace App\Controllers;\n\nuse PDO;\nuse Exception;\n\nclass RundownController extends Controller {\n\n{$controllerMethods}\n}\n";

file_put_contents('app/Controllers/RundownController.php', $controllerClass);

$newLegacyStr = implode("", $newLegacyLines);
$newLegacyStr = preg_replace("/^\} elseif \(/m", "if (", $newLegacyStr, 1);

file_put_contents('api_legacy.php', $newLegacyStr);

// Update api.php
$apiRouter = file_get_contents('api.php');
$routesToAdd = "";
$routeMap = [
    'create_rundown' => 'createRundown',
    'get_rundowns' => 'getRundowns',
    'get_rundown_data' => 'getRundownData',
    'add_rundown_story' => 'addRundownStory',
    'add_rundown_break' => 'addRundownBreak',
    'update_rundown_order' => 'updateRundownOrder',
    'toggle_rundown_story_drop' => 'toggleRundownStoryDrop',
    'toggle_lock_rundown' => 'toggleLockRundown',
    'get_programs' => 'getPrograms',
    'save_program' => 'saveProgram',
    'delete_program' => 'deleteProgram'
];

foreach ($routeMap as $action => $methodName) {
    $routesToAdd .= "    '{$action}' => ['controller' => 'App\\Controllers\\RundownController', 'method' => '{$methodName}'],\n";
}

$apiRouter = str_replace("'get_system_settings'", $routesToAdd . "    'get_system_settings'", $apiRouter);
file_put_contents('api.php', $apiRouter);

echo "Rundown Migration Success.\n";
