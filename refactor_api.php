<?php
// refactor_api.php
$apiFile = 'api.php';
$content = file_get_contents($apiFile);

// We need to build a simple router.
$newRouterContent = "<?php\n// api.php - MVC Router\nsession_start();\nrequire_once 'app/Core/Database.php';\nrequire_once 'app/Controllers/Controller.php';\n\n// Basic autoloader for controllers\nspl_autoload_register(function (\$class) {\n    \$file = __DIR__ . '/' . str_replace('\\\\', '/', \$class) . '.php';\n    if (file_exists(\$file)) require_once \$file;\n});\n\n\$action = \$_GET['action'] ?? '';\n\$method = \$_SERVER['REQUEST_METHOD'];\n\n// Include legacy api for now\nrequire_once 'api_legacy.php';\n";

// Rename old api to api_legacy
rename('api.php', 'api_legacy.php');
file_put_contents('api.php', $newRouterContent);

echo "api.php renamed to api_legacy.php and new router created.\n";
