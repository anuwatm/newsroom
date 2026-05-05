<?php
// Main Entry Point for Views
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$page = $_GET['page'] ?? 'home';

// Backward compatibility for standalone URLs (e.g. index.php?id=...)
if ($page === 'home' && empty($_GET['page']) && isset($_GET['id'])) {
    $page = 'home';
}

// Basic router
$allowed_pages = [
    'home', 'admin', 'assignment', 'change_password', 'dashboard', 'departments',
    'equipment', 'login', 'logout', 'print_assignment', 'print_rundown', 'print_story',
    'prompter', 'rundown', 'search', 'syslog', 'users'
];

if (in_array($page, $allowed_pages)) {
    require_once __DIR__ . '/views/' . $page . '.php';
} else {
    // 404
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>The page you requested does not exist.</p>";
}
