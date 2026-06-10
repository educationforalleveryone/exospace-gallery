<?php
// TEMP DEBUG
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(200);
        header('Content-Type: text/html');
        echo '<pre style="background:#1a0000;color:#ff6b6b;padding:20px;font-size:13px">';
        echo "FATAL: {$e['message']}\nFile: {$e['file']}:{$e['line']}";
        echo '</pre>';
    }
});
// END TEMP DEBUG

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
