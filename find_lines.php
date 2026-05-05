<?php
$lines = file('api_legacy.php');
foreach ($lines as $i => $line) {
    if (strpos($line, '} elseif ($method ===') === 0 || strpos($line, "if (\$method === 'POST' && \$action === 'create_rundown')") === 0 || strpos($line, "if (\$method === 'GET' && \$action === 'get_all_users')") === 0) {
        echo ($i + 1) . ": " . trim($line) . "\n";
    }
}
