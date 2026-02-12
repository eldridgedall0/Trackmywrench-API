<?php
/**
 * GarageMinder API - FULL Dispatch Test
 * Tests the ENTIRE index.php flow including route registration and dispatch.
 * Upload to: gm/api/v1/apidebug.php
 * DELETE after debugging!
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo "\n\n=== FATAL ERROR ===\n";
        echo json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
});

header('Content-Type: application/json; charset=utf-8');

$log = [];
function s(string $msg) { global $log; $log[] = $msg; }

// ============================================================
// Replicate index.php EXACTLY
// ============================================================

// Step 1: Constants
if (!defined('GM_API')) define('GM_API', true);
if (!defined('GM_API_START')) define('GM_API_START', microtime(true));
s('✅ Constants');

// Step 2: Config
try {
    require_once __DIR__ . '/config/api_config.php';
    s('✅ Config loaded. API_PREFIX=' . API_PREFIX . ' API_DEBUG=' . (API_DEBUG ? 'true' : 'false'));
} catch (Throwable $e) {
    s('❌ Config: ' . $e->getMessage());
    echo json_encode(['log' => $log], JSON_PRETTY_PRINT); exit;
}

// Step 3: Autoloader (same as index.php)
spl_autoload_register(function (string $class) {
    $prefix = 'GarageMinder\\API\\';
    $baseDir = __DIR__ . '/';
    if (strpos($class, $prefix) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $map = [
        'Core\\'       => 'core/',
        'Middleware\\' => 'middleware/',
        'Models\\'     => 'models/',
        'Endpoints\\' => 'endpoints/',
    ];
    foreach ($map as $nsPrefix => $dir) {
        if (strpos($relativeClass, $nsPrefix) === 0) {
            $classPath = substr($relativeClass, strlen($nsPrefix));
            $file = $baseDir . $dir . str_replace('\\', '/', $classPath) . '.php';
            if (file_exists($file)) { require_once $file; return; }
            $parts = explode('\\', $classPath);
            if (count($parts) > 1) {
                $parts[0] = strtolower($parts[0]);
                $file = $baseDir . $dir . implode('/', $parts) . '.php';
                if (file_exists($file)) { require_once $file; return; }
            }
        }
    }
    $file = $baseDir . 'endpoints/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) { require_once $file; }
});
s('✅ Autoloader');

// Step 4: Use statements (same as index.php)
try {
    // These are the exact use statements from index.php
    use GarageMinder\API\Core\{Router, Request, Response};
    use GarageMinder\API\Middleware\{CorsMiddleware, RateLimitMiddleware, AuthMiddleware, AdminMiddleware, LoggingMiddleware};
    use GarageMinder\API\Endpoints\Auth\{LoginEndpoint, TokenExchangeEndpoint, RefreshEndpoint, LogoutEndpoint, VerifyEndpoint};
    use GarageMinder\API\Endpoints\User\{ProfileEndpoint, PreferencesEndpoint};
    use GarageMinder\API\Endpoints\Vehicles\{ListEndpoint as VehicleListEndpoint, DetailEndpoint as VehicleDetailEndpoint, OdometerEndpoint};
    use GarageMinder\API\Endpoints\Vehicles\{RemindersEndpoint as VehicleRemindersEndpoint, RemindersDueEndpoint as VehicleRemindersDueEndpoint};
    use GarageMinder\API\Endpoints\Reminders\{ListEndpoint as ReminderListEndpoint, DueEndpoint as ReminderDueEndpoint, DetailEndpoint as ReminderDetailEndpoint};
    use GarageMinder\API\Endpoints\Sync\{PushEndpoint, StatusEndpoint as SyncStatusEndpoint, DeviceEndpoint};
    use GarageMinder\API\Endpoints\Subscription\{StatusEndpoint as SubscriptionStatusEndpoint};
    use GarageMinder\API\Endpoints\Admin\{TestEndpoint, UsersEndpoint, LogsEndpoint, StatsEndpoint};
    s('✅ Use statements');
} catch (Throwable $e) {
    s('❌ Use statements: ' . $e->getMessage());
    echo json_encode(['log' => $log, 'error' => $e->getMessage()], JSON_PRETTY_PRINT); exit;
}

// Step 5: Build Router (same as index.php)
try {
    $router = new Router();
    $request = new Request();
    s('✅ Router + Request created');
    s('ℹ️ Request: ' . $request->getMethod() . ' ' . $request->getPath());
    s('ℹ️ URI: ' . ($_SERVER['REQUEST_URI'] ?? 'none'));
    s('ℹ️ SCRIPT_NAME: ' . ($_SERVER['SCRIPT_NAME'] ?? 'none'));
} catch (Throwable $e) {
    s('❌ Router/Request: ' . $e->getMessage());
    echo json_encode(['log' => $log, 'error' => ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]], JSON_PRETTY_PRINT); exit;
}

// Step 6: Register global middleware
try {
    $router->use(CorsMiddleware::class);
    $router->use(LoggingMiddleware::class);
    s('✅ Global middleware registered');
} catch (Throwable $e) {
    s('❌ Global middleware: ' . $e->getMessage());
    echo json_encode(['log' => $log, 'error' => ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]], JSON_PRETTY_PRINT); exit;
}

// Step 7: Register ALL routes (same as index.php)
try {
    // Public routes
    $router->group('', [RateLimitMiddleware::class], function (Router $r) {
        $r->post('/auth/login',          LoginEndpoint::class);
        $r->post('/auth/token-exchange', TokenExchangeEndpoint::class);
        $r->post('/auth/refresh',        RefreshEndpoint::class);
    });
    s('✅ Public routes registered');

    // Authenticated routes
    $router->group('', [RateLimitMiddleware::class, AuthMiddleware::class], function (Router $r) {
        $r->post('/auth/logout',  LogoutEndpoint::class);
        $r->get('/auth/verify',   VerifyEndpoint::class);
        $r->get('/user/profile',       ProfileEndpoint::class);
        $r->get('/user/preferences',   PreferencesEndpoint::class);
        $r->put('/user/preferences',   PreferencesEndpoint::class);
        $r->get('/vehicles',                       VehicleListEndpoint::class);
        $r->get('/vehicles/{id}',                  VehicleDetailEndpoint::class);
        $r->put('/vehicles/{id}/odometer',         OdometerEndpoint::class);
        $r->get('/vehicles/{id}/reminders',        VehicleRemindersEndpoint::class);
        $r->get('/vehicles/{id}/reminders/due',    VehicleRemindersDueEndpoint::class);
        $r->get('/reminders',      ReminderListEndpoint::class);
        $r->get('/reminders/due',  ReminderDueEndpoint::class);
        $r->get('/reminders/{id}', ReminderDetailEndpoint::class);
        $r->post('/sync/push',            PushEndpoint::class);
        $r->get('/sync/status',           SyncStatusEndpoint::class);
        $r->post('/sync/register-device', DeviceEndpoint::class);
        $r->get('/subscription/status', SubscriptionStatusEndpoint::class);
    });
    s('✅ Authenticated routes registered');

    // Admin routes
    $router->group('/admin', [RateLimitMiddleware::class, AuthMiddleware::class, AdminMiddleware::class], function (Router $r) {
        $r->get('/test',   TestEndpoint::class);
        $r->post('/test',  TestEndpoint::class);
        $r->get('/users',  UsersEndpoint::class);
        $r->get('/logs',   LogsEndpoint::class);
        $r->get('/stats',  StatsEndpoint::class);
    });
    s('✅ Admin routes registered');
    s('ℹ️ Total routes: ' . count($router->getRoutes()));
} catch (Throwable $e) {
    s('❌ Route registration: ' . $e->getMessage());
    echo json_encode(['log' => $log, 'error' => ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()]], JSON_PRETTY_PRINT); exit;
}

// Step 8: NOW dispatch (this is where the 500 likely happens)
s('🔄 About to dispatch...');

// Flush log so far in case dispatch causes a fatal
echo "/* PRE-DISPATCH LOG: " . json_encode($log) . " */\n\n";
flush();

try {
    $router->dispatch($request);
    // If we get here, dispatch succeeded
} catch (Throwable $e) {
    // Dispatch threw an exception
    header('Content-Type: application/json');
    echo json_encode([
        'log' => $log,
        'DISPATCH_ERROR' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
