<?php
function write_log($action, $details = '', $level = 'INFO') {
    $log_dir = __DIR__ . '/data/log';
    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            return;
        }
        @file_put_contents($log_dir . '/.htaccess', "Deny from all\n");
    }

    $date = date('Y-m-d');
    $time = date('Y-m-d H:i:s');
    $filepath = $log_dir . "/newsroom_{$date}.log";

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwarded_ip = trim(end($forwarded));
        if (filter_var($forwarded_ip, FILTER_VALIDATE_IP)) {
            $ip = $forwarded_ip;
        }
    }

    if (isset($_SESSION['user'])) {
        $user_id = $_SESSION['user']['employee_id'] ?? 'Unknown';
        $role = $_SESSION['user']['role_name'] ?? 'Unknown';
        $user_info = "Emp: {$user_id}/{$role}";
    } else {
        $user_info = "Guest";
    }

    // Strip newlines from details to keep single-line format
    $details = str_replace(["\r", "\n"], ' ', $details);
    
    if (mb_strlen($details, 'UTF-8') > 1000) {
        $details = mb_substr($details, 0, 1000, 'UTF-8') . '... [TRUNCATED]';
    }

    $log_entry = "[$time] [$level] [$ip] [$user_info] [$action] $details" . PHP_EOL;
    @file_put_contents($filepath, $log_entry, FILE_APPEND | LOCK_EX);
}
