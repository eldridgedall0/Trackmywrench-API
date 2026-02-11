<?php
/**
 * GarageMinder Mobile API v1 - Entry Point
 * 
 * All requests are routed here via .htaccess
 * This file bootstraps the API: autoloading, config, routing, dispatch.
 */

// ============================================================================
// 1. Define API constant (prevents direct access to config)
// ============================================================================
define('GM_API', true);
define('GM_API_START', microtime(true));

// ============================================================================
// 2. Error handling
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    
    $response = [
        'success' => false,
        'data'    => null,
        'error'   => [
            'code'    => 'INTERNAL_ERROR',
            'message' => 'An internal error occurred.',
        ],
        'meta' => ['api_version' => '1.0.0', 'timestamp' => time()],
    ];

    // Include details in debug mode
    if (defined('API_DEBUG') && API_DEBUG) {
        $response['error']['details'] = [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ];
    }

    echo json_encode($response);
    exit;
});

// ============================================================================
// 3. Load configuration
// ============================================================================
require_once __DIR__ . '/config/api_config.php';

// ============================================================================
// 4. PSR-4 style autoloader
// ============================================================================
spl_autoload_register(function (string $class) {
    $prefix = 'GarageMinder\\API\\';
    $baseDir = __DIR__ . '/';

    if (strpos($class, $prefix) !== 0) return;

    $relativeClass = substr($class, strlen($prefix));
    
    // Map namespace to directory
    $map = [
        'Core\\'         => 'core/',
        'Middleware\\'    => 'middleware/',
        'Models\\'       => 'models/',
        'Endpoints\\'    => 'endpoints/',
    ];

    foreach ($map as $nsPrefix => $dir) {
        if (strpos($relativeClass, $nsPrefix) === 0) {
            $classPath = substr($relativeClass, strlen($nsPrefix));
            // Convert namespace separators to directory separators
            $file = $baseDir . $dir . str_replace('\\', '/', $classPath) . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                return;
            }
            
            // Try lowercase directory for endpoint subdirectories
            $parts = explode('\\', $classPath);
            if (count($parts) > 1) {
                $parts[0] = strtolower($parts[0]); // lowercase first subdirectory
                $file = $baseDir . $dir . implode('/', $parts) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    return;
                }
            }
        }
    }

    // Fallback: try BaseEndpoint
    $file = $baseDir . 'endpoints/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ============================================================================
// 5. Use statements
// ============================================================================
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

// ============================================================================
// 6. Build Router & Register Routes
// ============================================================================
$router = new Router();
$request = new Request();

// Global middleware (runs on every request)
$router->use(CorsMiddleware::class);
$router->use(LoggingMiddleware::class);

// --- Public Routes (no auth required) ---
$router->group('', [RateLimitMiddleware::class], function (Router $r) {
    $r->post('/auth/login',          LoginEndpoint::class);
    $r->post('/auth/token-exchange', TokenExchangeEndpoint::class);
    $r->post('/auth/refresh',        RefreshEndpoint::class);
});

// --- Authenticated Routes ---
$router->group('', [RateLimitMiddleware::class, AuthMiddleware::class], function (Router $r) {
    // Auth
    $r->post('/auth/logout',  LogoutEndpoint::class);
    $r->get('/auth/verify',   VerifyEndpoint::class);

    // User
    $r->get('/user/profile',       ProfileEndpoint::class);
    $r->get('/user/preferences',   PreferencesEndpoint::class);
    $r->put('/user/preferences',   PreferencesEndpoint::class);

    // Vehicles
    $r->get('/vehicles',                       VehicleListEndpoint::class);
    $r->get('/vehicles/{id}',                  VehicleDetailEndpoint::class);
    $r->put('/vehicles/{id}/odometer',         OdometerEndpoint::class);
    $r->get('/vehicles/{id}/reminders',        VehicleRemindersEndpoint::class);
    $r->get('/vehicles/{id}/reminders/due',    VehicleRemindersDueEndpoint::class);

    // Reminders (all vehicles)
    $r->get('/reminders',      ReminderListEndpoint::class);
    $r->get('/reminders/due',  ReminderDueEndpoint::class);
    $r->get('/reminders/{id}', ReminderDetailEndpoint::class);

    // Sync
    $r->post('/sync/push',            PushEndpoint::class);
    $r->get('/sync/status',           SyncStatusEndpoint::class);
    $r->post('/sync/register-device', DeviceEndpoint::class);

    // Subscription
    $r->get('/subscription/status', SubscriptionStatusEndpoint::class);
});

// --- Admin Routes ---
$router->group('/admin', [RateLimitMiddleware::class, AuthMiddleware::class, AdminMiddleware::class], function (Router $r) {
    $r->get('/test',   TestEndpoint::class);
    $r->post('/test',  TestEndpoint::class);
    $r->get('/users',  UsersEndpoint::class);
    $r->get('/logs',   LogsEndpoint::class);
    $r->get('/stats',  StatsEndpoint::class);
});

// ============================================================================
// 7. Dispatch!
// ============================================================================
$router->dispatch($request);
