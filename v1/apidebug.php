<?php
/**
 * GarageMinder API - Bootstrap Debugger
 * Replicates EXACTLY what index.php does, step by step.
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
        echo json_encode(['FATAL_ERROR' => $error], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
});

header('Content-Type: application/json; charset=utf-8');
$log = [];
$step = 0;

function s(string $msg, &$log, &$step) { $step++; $log[] = "Step {$step}: {$msg}"; }
function fail(array $log, Throwable $e) {
    echo json_encode(['steps' => $log, 'ERROR' => [
        'message' => $e->getMessage(), 'file' => $e->getFile(),
        'line' => $e->getLine(), 'trace' => explode("\n", $e->getTraceAsString()),
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// === Step 1: Constants ===
if (!defined('GM_API')) define('GM_API', true);
if (!defined('GM_API_START')) define('GM_API_START', microtime(true));
s('✅ Constants defined', $log, $step);

// === Step 2: Config ===
try {
    require_once __DIR__ . '/config/api_config.php';
    s('✅ api_config.php loaded', $log, $step);
} catch (Throwable $e) { s('❌ config failed', $log, $step); fail($log, $e); }

// === Step 3: Register autoloader (EXACTLY as index.php does it) ===
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
s('✅ Autoloader registered', $log, $step);

// === Step 4: Load every class that index.php uses ===
$allClasses = [
    // Core
    'GarageMinder\\API\\Core\\Router',
    'GarageMinder\\API\\Core\\Request',
    'GarageMinder\\API\\Core\\Response',
    'GarageMinder\\API\\Core\\Database',
    'GarageMinder\\API\\Core\\JWTHandler',
    'GarageMinder\\API\\Core\\Validator',
    'GarageMinder\\API\\Core\\Logger',
    'GarageMinder\\API\\Core\\RateLimiter',
    'GarageMinder\\API\\Core\\Middleware',
    // Middleware
    'GarageMinder\\API\\Middleware\\CorsMiddleware',
    'GarageMinder\\API\\Middleware\\RateLimitMiddleware',
    'GarageMinder\\API\\Middleware\\AuthMiddleware',
    'GarageMinder\\API\\Middleware\\AdminMiddleware',
    'GarageMinder\\API\\Middleware\\LoggingMiddleware',
    // Models
    'GarageMinder\\API\\Models\\User',
    'GarageMinder\\API\\Models\\Vehicle',
    'GarageMinder\\API\\Models\\Token',
    'GarageMinder\\API\\Models\\Device',
    'GarageMinder\\API\\Models\\Reminder',
    // Auth endpoints
    'GarageMinder\\API\\Endpoints\\BaseEndpoint',
    'GarageMinder\\API\\Endpoints\\Auth\\LoginEndpoint',
    'GarageMinder\\API\\Endpoints\\Auth\\TokenExchangeEndpoint',
    'GarageMinder\\API\\Endpoints\\Auth\\RefreshEndpoint',
    'GarageMinder\\API\\Endpoints\\Auth\\LogoutEndpoint',
    'GarageMinder\\API\\Endpoints\\Auth\\VerifyEndpoint',
    // User endpoints
    'GarageMinder\\API\\Endpoints\\User\\ProfileEndpoint',
    'GarageMinder\\API\\Endpoints\\User\\PreferencesEndpoint',
    // Vehicle endpoints
    'GarageMinder\\API\\Endpoints\\Vehicles\\ListEndpoint',
    'GarageMinder\\API\\Endpoints\\Vehicles\\DetailEndpoint',
    'GarageMinder\\API\\Endpoints\\Vehicles\\OdometerEndpoint',
    'GarageMinder\\API\\Endpoints\\Vehicles\\RemindersEndpoint',
    'GarageMinder\\API\\Endpoints\\Vehicles\\RemindersDueEndpoint',
    // Reminder endpoints
    'GarageMinder\\API\\Endpoints\\Reminders\\ListEndpoint',
    'GarageMinder\\API\\Endpoints\\Reminders\\DueEndpoint',
    'GarageMinder\\API\\Endpoints\\Reminders\\DetailEndpoint',
    // Sync endpoints
    'GarageMinder\\API\\Endpoints\\Sync\\PushEndpoint',
    'GarageMinder\\API\\Endpoints\\Sync\\StatusEndpoint',
    'GarageMinder\\API\\Endpoints\\Sync\\DeviceEndpoint',
    // Subscription
    'GarageMinder\\API\\Endpoints\\Subscription\\StatusEndpoint',
    // Admin
    'GarageMinder\\API\\Endpoints\\Admin\\TestEndpoint',
    'GarageMinder\\API\\Endpoints\\Admin\\UsersEndpoint',
    'GarageMinder\\API\\Endpoints\\Admin\\LogsEndpoint',
    'GarageMinder\\API\\Endpoints\\Admin\\StatsEndpoint',
];

$missing = [];
foreach ($allClasses as $fqcn) {
    try {
        if (class_exists($fqcn, true)) {
            s("✅ {$fqcn}", $log, $step);
        } else {
            s("❌ NOT FOUND: {$fqcn}", $log, $step);
            
            // Show what file the autoloader would look for
            $rel = str_replace('GarageMinder\\API\\', '', $fqcn);
            $parts = explode('\\', $rel);
            // Lowercase first part (namespace dir)
            $parts[0] = strtolower($parts[0]);
            if (count($parts) > 2) $parts[1] = strtolower($parts[1]);
            $expectedFile = __DIR__ . '/' . implode('/', $parts) . '.php';
            $missing[] = ['class' => $fqcn, 'expected_file' => $expectedFile, 'file_exists' => file_exists($expectedFile)];
        }
    } catch (Throwable $e) {
        s("❌ ERROR loading {$fqcn}: " . $e->getMessage(), $log, $step);
        fail($log, $e);
    }
}

// === Step 5: Try creating Router + Request ===
try {
    $router = new \GarageMinder\API\Core\Router();
    s('✅ Router created', $log, $step);
    
    $request = new \GarageMinder\API\Core\Request();
    s('✅ Request created: ' . $request->getMethod() . ' ' . $request->getPath(), $log, $step);
} catch (Throwable $e) {
    s('❌ Router/Request failed', $log, $step);
    fail($log, $e);
}

// === Summary ===
echo json_encode([
    'status' => empty($missing) ? '✅ ALL CLASSES LOADED - ready for dispatch' : '❌ MISSING CLASSES - see list',
    'total_steps' => $step,
    'missing_classes' => $missing,
    'steps' => $log,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
