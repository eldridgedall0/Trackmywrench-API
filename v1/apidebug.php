<?php
/**
 * GarageMinder API - Dispatch Debugger
 * Simulates a real API request through the middleware chain.
 * Upload to: gm/api/v1/apidebug.php
 * DELETE after debugging!
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: text/plain');
        echo "\n\n=== FATAL ERROR ===\n";
        echo "Type: {$error['type']}\n";
        echo "Message: {$error['message']}\n";
        echo "File: {$error['file']}\n";
        echo "Line: {$error['line']}\n";
    }
});

header('Content-Type: text/plain; charset=utf-8');

echo "=== GarageMinder API Dispatch Debugger ===\n\n";

// Step 1: Load config
echo "1. Loading config... ";
try {
    if (!defined('GM_API')) define('GM_API', true);
    if (!defined('GM_API_START')) define('GM_API_START', microtime(true));
    require_once __DIR__ . '/config/api_config.php';
    echo "OK (API_PREFIX=" . API_PREFIX . ")\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n"; exit;
}

// Step 2: Register autoloader
echo "2. Registering autoloader... ";
spl_autoload_register(function (string $class) {
    $prefix = 'GarageMinder\\API\\';
    $baseDir = __DIR__ . '/';
    if (strpos($class, $prefix) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $map = ['Core\\' => 'core/', 'Middleware\\' => 'middleware/', 'Models\\' => 'models/', 'Endpoints\\' => 'endpoints/'];
    foreach ($map as $nsPrefix => $dir) {
        if (strpos($relativeClass, $nsPrefix) === 0) {
            $classPath = substr($relativeClass, strlen($nsPrefix));
            $file = $baseDir . $dir . str_replace('\\', '/', $classPath) . '.php';
            if (file_exists($file)) { require_once $file; return; }
            $parts = explode('\\', $classPath);
            if (count($parts) > 1) { $parts[0] = strtolower($parts[0]); $file = $baseDir . $dir . implode('/', $parts) . '.php'; if (file_exists($file)) { require_once $file; return; } }
        }
    }
});
echo "OK\n";

// Step 3: Test middleware chain manually (same order as real dispatch)
echo "\n=== Testing middleware chain for POST /auth/login ===\n\n";

// 3a: CorsMiddleware
echo "3a. CorsMiddleware... ";
try {
    $cors = new \GarageMinder\API\Middleware\CorsMiddleware();
    echo "OK (instantiated)\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 3b: LoggingMiddleware (creates Logger → connects to GM DB)
echo "3b. LoggingMiddleware... ";
try {
    $logging = new \GarageMinder\API\Middleware\LoggingMiddleware();
    echo "OK (instantiated)\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 3c: Logger (connects to DB on construct)
echo "3c. Logger direct test... ";
try {
    $logger = new \GarageMinder\API\Core\Logger();
    echo "OK\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 3d: RateLimitMiddleware
echo "3d. RateLimitMiddleware... ";
try {
    $rateLimit = new \GarageMinder\API\Middleware\RateLimitMiddleware();
    echo "OK (instantiated)\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// 3e: RateLimiter (connects to DB on construct)
echo "3e. RateLimiter direct test... ";
try {
    $rateLimiter = new \GarageMinder\API\Core\RateLimiter();
    echo "OK\n";
    
    // Actually run a rate limit check
    echo "3f. RateLimiter check... ";
    $result = $rateLimiter->check('127.0.0.1-debug', 'ip', '/auth/login');
    echo "OK (allowed=" . ($result['allowed'] ? 'true' : 'false') . ", remaining={$result['remaining']})\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Step 4: Test Request object
echo "\n4. Request object... ";
try {
    $request = new \GarageMinder\API\Core\Request();
    echo "OK (method=" . $request->getMethod() . ", path=" . $request->getPath() . ")\n";
    echo "   URI: " . ($_SERVER['REQUEST_URI'] ?? 'none') . "\n";
    echo "   SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'none') . "\n";
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}

// Step 5: Test Router setup
echo "\n5. Router setup... ";
try {
    $router = new \GarageMinder\API\Core\Router();
    
    // Register just the login route
    $router->use(\GarageMinder\API\Middleware\CorsMiddleware::class);
    $router->use(\GarageMinder\API\Middleware\LoggingMiddleware::class);
    
    $router->group('', [\GarageMinder\API\Middleware\RateLimitMiddleware::class], function ($r) {
        $r->post('/auth/login', \GarageMinder\API\Endpoints\Auth\LoginEndpoint::class);
    });
    
    $routes = $router->getRoutes();
    echo "OK (" . count($routes) . " routes)\n";
    foreach ($routes as $route) {
        echo "   {$route['method']} {$route['path']} → {$route['endpoint']}\n";
    }
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Step 6: Database insert/execute test
echo "\n6. Database method test... ";
try {
    $db = \GarageMinder\API\Core\Database::getInstance();
    echo "getInstance OK\n";
    
    // Test fetchAll
    echo "   fetchAll... ";
    $tables = $db->fetchAll("SHOW TABLES");
    echo "OK (" . count($tables) . " tables)\n";
    
    // Test fetchOne
    echo "   fetchOne... ";
    $one = $db->fetchOne("SELECT 1 as test");
    echo "OK (test=" . ($one['test'] ?? 'null') . ")\n";
    
    // Test fetchColumn
    echo "   fetchColumn... ";
    $col = $db->fetchColumn("SELECT DATABASE()");
    echo "OK (db=" . $col . ")\n";
    
    // Test insert (if method exists)
    echo "   insert method exists... ";
    if (method_exists($db, 'insert')) {
        echo "YES\n";
    } else {
        echo "NO - this will cause failures in Logger and RateLimiter!\n";
    }
    
    // Test execute (if method exists)
    echo "   execute method exists... ";
    if (method_exists($db, 'execute')) {
        echo "YES\n";
    } else {
        echo "NO - this will cause failures in RateLimiter!\n";
    }
    
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}

// Step 7: Check Database class has all needed methods
echo "\n7. Database class methods:\n";
$dbMethods = get_class_methods(\GarageMinder\API\Core\Database::class);
foreach ($dbMethods as $m) {
    echo "   - $m()\n";
}

echo "\n=== DONE ===\n";
echo "If all steps passed, the 500 error is in the actual dispatch flow.\n";
echo "Try the debuglogin.php page to test the full login independently.\n";
