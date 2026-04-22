<?php
session_start();
require_once __DIR__ . '/logger.php';
if (isset($_SESSION['user'])) {
    write_log('LOGOUT', 'Logged out successfully');
}
session_unset();
session_destroy();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
header("Location: login.php");
exit;
?>
