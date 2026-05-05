<?php
header('Content-Type: application/json');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
require_once 'session_guard.php';

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

require_once 'app/Core/Database.php';

$db = \App\Core\Database::getConnection();

// --- API Rate Limiting ---
$ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
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
    $rl = ['hits' => 1, 'last_reset' => $currentTime];
}

if ($currentTime - $rl['last_reset'] > 60) {
    $db->prepare("UPDATE api_rate_limits SET hits = 1, last_reset = ? WHERE ip = ?")->execute([$currentTime, $ip]);
    $rl['hits'] = 1;
}

if ($rl['hits'] > 200) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded (200 requests per minute)']);
    exit;
}
// -------------------------

// Global Presence Heartbeat
try {
    $stmtHeartbeat = $db->prepare("UPDATE users SET last_seen = CURRENT_TIMESTAMP WHERE employee_id = ?");
    $stmtHeartbeat->execute([$_SESSION['user']['employee_id']]);
} catch(Exception $e) {}

require_once 'app/Controllers/Controller.php';

// Basic autoloader for controllers
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) require_once $file;
});

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Route Definitions
$routes = [
        'save_story' => ['controller' => 'App\Controllers\StoryController', 'method' => 'saveStory'],
    'lock_story' => ['controller' => 'App\Controllers\StoryController', 'method' => 'lockStory'],
    'unlock_story' => ['controller' => 'App\Controllers\StoryController', 'method' => 'unlockStory'],
    'get_story' => ['controller' => 'App\Controllers\StoryController', 'method' => 'getStory'],
    'search_stories' => ['controller' => 'App\Controllers\StoryController', 'method' => 'searchStories'],
    'get_my_stories' => ['controller' => 'App\Controllers\StoryController', 'method' => 'getMyStories'],
    'move_to_bin' => ['controller' => 'App\Controllers\StoryController', 'method' => 'moveToBin'],
    'get_story_versions' => ['controller' => 'App\Controllers\StoryController', 'method' => 'getStoryVersions'],
    'get_story_version_data' => ['controller' => 'App\Controllers\StoryController', 'method' => 'getStoryVersionData'],
    'restore_story_version' => ['controller' => 'App\Controllers\StoryController', 'method' => 'restoreStoryVersion'],
    'add_story_comment' => ['controller' => 'App\Controllers\StoryController', 'method' => 'addStoryComment'],
    'get_story_comments' => ['controller' => 'App\Controllers\StoryController', 'method' => 'getStoryComments'],
    'ping_viewer' => ['controller' => 'App\Controllers\StoryController', 'method' => 'pingViewer'],
    'publish_to_cms' => ['controller' => 'App\Controllers\StoryController', 'method' => 'publishToCms'],
        'create_rundown' => ['controller' => 'App\Controllers\RundownController', 'method' => 'createRundown'],
    'get_rundowns' => ['controller' => 'App\Controllers\RundownController', 'method' => 'getRundowns'],
    'get_rundown_data' => ['controller' => 'App\Controllers\RundownController', 'method' => 'getRundownData'],
    'add_rundown_story' => ['controller' => 'App\Controllers\RundownController', 'method' => 'addRundownStory'],
    'add_rundown_break' => ['controller' => 'App\Controllers\RundownController', 'method' => 'addRundownBreak'],
    'update_rundown_order' => ['controller' => 'App\Controllers\RundownController', 'method' => 'updateRundownOrder'],
    'toggle_rundown_story_drop' => ['controller' => 'App\Controllers\RundownController', 'method' => 'toggleRundownStoryDrop'],
    'toggle_lock_rundown' => ['controller' => 'App\Controllers\RundownController', 'method' => 'toggleLockRundown'],
    'get_programs' => ['controller' => 'App\Controllers\RundownController', 'method' => 'getPrograms'],
    'save_program' => ['controller' => 'App\Controllers\RundownController', 'method' => 'saveProgram'],
    'delete_program' => ['controller' => 'App\Controllers\RundownController', 'method' => 'deleteProgram'],
    'get_system_settings' => ['controller' => 'App\Controllers\SystemController', 'method' => 'getSystemSettings'],
    'save_system_settings' => ['controller' => 'App\Controllers\SystemController', 'method' => 'saveSystemSettings'],
    
    // Assignment Routes
    'get_assignments' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'getAssignments'],
    'get_assignment_detail' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'getAssignmentDetail'],
    'create_assignment' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'createAssignment'],
    'update_assignment' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'updateAssignment'],
    'delete_assignment' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'deleteAssignment'],
    'approve_assignment' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'approveAssignment'],
    'reject_assignment' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'rejectAssignment'],
    'complete_assignment' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'completeAssignment'],
    'get_assignment_badge_count' => ['controller' => 'App\Controllers\AssignmentController', 'method' => 'getBadgeCount'],

    // Equipment Routes
    'get_equipment_master' => ['controller' => 'App\Controllers\EquipmentController', 'method' => 'getActiveEquipment'],
    'get_equipment_master_all' => ['controller' => 'App\Controllers\EquipmentController', 'method' => 'getAllEquipment'],
    'save_equipment' => ['controller' => 'App\Controllers\EquipmentController', 'method' => 'saveEquipment'],
    'delete_equipment' => ['controller' => 'App\Controllers\EquipmentController', 'method' => 'deleteEquipment'],
    'check_equipment_availability' => ['controller' => 'App\Controllers\EquipmentController', 'method' => 'checkAvailability'],
    'get_equipment_conflicts' => ['controller' => 'App\Controllers\EquipmentController', 'method' => 'getConflicts'],

    // User Routes
    'get_users' => ['controller' => 'App\Controllers\UserController', 'method' => 'getUsers'],
    'get_all_users' => ['controller' => 'App\Controllers\UserController', 'method' => 'getAllUsers'],
    'save_user' => ['controller' => 'App\Controllers\UserController', 'method' => 'saveUser'],
    'delete_user' => ['controller' => 'App\Controllers\UserController', 'method' => 'deleteUser'],
    'get_roles' => ['controller' => 'App\Controllers\UserController', 'method' => 'getRoles'],

    // Department Routes
    'get_departments' => ['controller' => 'App\Controllers\DepartmentController', 'method' => 'getDepartments'],
    'save_department' => ['controller' => 'App\Controllers\DepartmentController', 'method' => 'saveDepartment'],
    'delete_department' => ['controller' => 'App\Controllers\DepartmentController', 'method' => 'deleteDepartment'],

    // Dashboard Routes
    'get_dashboard_stats' => ['controller' => 'App\Controllers\DashboardController', 'method' => 'getStats'],
    'get_dashboard_live' => ['controller' => 'App\Controllers\DashboardController', 'method' => 'getLiveStatus'],
    'get_calendar_data' => ['controller' => 'App\Controllers\DashboardController', 'method' => 'getCalendarData'],

    // Notification Routes
    'get_notifications' => ['controller' => 'App\Controllers\NotificationController', 'method' => 'getNotifications'],
    'mark_notification_read' => ['controller' => 'App\Controllers\NotificationController', 'method' => 'markRead'],

    // System Log Routes
    'get_log_files' => ['controller' => 'App\Controllers\SystemController', 'method' => 'getLogFiles'],
    'get_log_content' => ['controller' => 'App\Controllers\SystemController', 'method' => 'getLogContent']
];

if (isset($routes[$action])) {
    $route = $routes[$action];
    $controller = new $route['controller']();
    $methodName = $route['method'];
    $controller->$methodName();
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Endpoint not found or moved.']);
exit;
