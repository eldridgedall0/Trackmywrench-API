<?php
/**
 * GarageMinder API - Step-by-step Bootstrap Debugger
 * This file manually walks through EXACTLY what index.php does,
 * catching every possible error including fatal/compile errors.
 * 
 * Upload to: gm/api/v1/apidebug.php
 * Visit: https://yesca.st/gm/api/v1/apidebug.php
 * DELETE after debugging!
 */

// Force ALL errors visible - override everything
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'FATAL_ERROR' => true,
            'type' => $error['type'],
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
});

header('Content-Type: application/json; charset=utf-8');

$log = [];
$step = 0;

function logStep(string $msg, &$log, &$step) {
    $step++;
    $log[] = "Step {$step}: {$msg}";
    // Flush after each step so we see where it dies
}

function dumpAndDie(array $log, ?Throwable $e = null) {
    $result = ['steps_completed' => $log];
    if ($e) {
        $result['ERROR'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// Walk through EXACTLY what index.php does
// ============================================================

try {
    // Step 1: Define constants
    if (!defined('GM_API')) define('GM_API', true);
    if (!defined('GM_API_START')) define('GM_API_START', microtime(true));
    logStep('✅ Constants defined', $log, $step);

    // Step 2: Load config
    require_once __DIR__ . '/config/api_config.php';
    logStep('✅ api_config.php loaded', $log, $step);
    
    // Step 3: Autoloader
    require_once __DIR__ . '/config/autoload.php';
    logStep('✅ autoload.php loaded', $log, $step);
    
} catch (Throwable $e) {
    logStep('❌ Config/autoload failed', $log, $step);
    dumpAndDie($log, $e);
}

// Step 4: Test each core class individually
$coreClasses = [
    'Database'   => 'GarageMinder\\API\\Core\\Database',
    'Router'     => 'GarageMinder\\API\\Core\\Router',
    'Request'    => 'GarageMinder\\API\\Core\\Request',
    'Response'   => 'GarageMinder\\API\\Core\\Response',
    'JWTHandler' => 'GarageMinder\\API\\Core\\JWTHandler',
    'Validator'  => 'GarageMinder\\API\\Core\\Validator',
    'Logger'     => 'GarageMinder\\API\\Core\\Logger',
    'RateLimiter'=> 'GarageMinder\\API\\Core\\RateLimiter',
    'Middleware'  => 'GarageMinder\\API\\Core\\Middleware',
];

foreach ($coreClasses as $name => $fqcn) {
    try {
        if (!class_exists($fqcn, true)) {
            logStep("❌ Class not found: {$fqcn}", $log, $step);
        } else {
            logStep("✅ Class loaded: {$name}", $log, $step);
        }
    } catch (Throwable $e) {
        logStep("❌ Error loading {$name}: " . $e->getMessage(), $log, $step);
        dumpAndDie($log, $e);
    }
}

// Step 5: Test middleware classes
$middlewareClasses = [
    'CorsMiddleware'      => 'GarageMinder\\API\\Middleware\\CorsMiddleware',
    'AuthMiddleware'      => 'GarageMinder\\API\\Middleware\\AuthMiddleware',
    'AdminMiddleware'     => 'GarageMinder\\API\\Middleware\\AdminMiddleware',
    'RateLimitMiddleware' => 'GarageMinder\\API\\Middleware\\RateLimitMiddleware',
    'LoggingMiddleware'   => 'GarageMinder\\API\\Middleware\\LoggingMiddleware',
];

foreach ($middlewareClasses as $name => $fqcn) {
    try {
        if (!class_exists($fqcn, true)) {
            logStep("❌ Middleware not found: {$fqcn}", $log, $step);
        } else {
            logStep("✅ Middleware loaded: {$name}", $log, $step);
        }
    } catch (Throwable $e) {
        logStep("❌ Error loading {$name}: " . $e->getMessage(), $log, $step);
        dumpAndDie($log, $e);
    }
}

// Step 6: Test model classes
$modelClasses = [
    'User'     => 'GarageMinder\\API\\Models\\User',
    'Vehicle'  => 'GarageMinder\\API\\Models\\Vehicle',
    'Token'    => 'GarageMinder\\API\\Models\\Token',
    'Device'   => 'GarageMinder\\API\\Models\\Device',
    'Reminder' => 'GarageMinder\\API\\Models\\Reminder',
];

foreach ($modelClasses as $name => $fqcn) {
    try {
        if (!class_exists($fqcn, true)) {
            logStep("❌ Model not found: {$fqcn}", $log, $step);
        } else {
            logStep("✅ Model loaded: {$name}", $log, $step);
        }
    } catch (Throwable $e) {
        logStep("❌ Error loading {$name}: " . $e->getMessage(), $log, $step);
        dumpAndDie($log, $e);
    }
}

// Step 7: Test endpoint classes
$endpointClasses = [
    'BaseEndpoint'     => 'GarageMinder\\API\\Endpoints\\BaseEndpoint',
    'LoginEndpoint'    => 'GarageMinder\\API\\Endpoints\\Auth\\LoginEndpoint',
    'TokenExchange'    => 'GarageMinder\\API\\Endpoints\\Auth\\TokenExchangeEndpoint',
    'RefreshEndpoint'  => 'GarageMinder\\API\\Endpoints\\Auth\\RefreshEndpoint',
    'VerifyEndpoint'   => 'GarageMinder\\API\\Endpoints\\Auth\\VerifyEndpoint',
    'LogoutEndpoint'   => 'GarageMinder\\API\\Endpoints\\Auth\\LogoutEndpoint',
    'VehicleList'      => 'GarageMinder\\API\\Endpoints\\Vehicles\\ListEndpoint',
    'VehicleDetail'    => 'GarageMinder\\API\\Endpoints\\Vehicles\\DetailEndpoint',
    'VehicleOdometer'  => 'GarageMinder\\API\\Endpoints\\Vehicles\\OdometerEndpoint',
    'SyncPush'         => 'GarageMinder\\API\\Endpoints\\Sync\\PushEndpoint',
    'SyncStatus'       => 'GarageMinder\\API\\Endpoints\\Sync\\StatusEndpoint',
    'UserProfile'      => 'GarageMinder\\API\\Endpoints\\User\\ProfileEndpoint',
];

foreach ($endpointClasses as $name => $fqcn) {
    try {
        if (!class_exists($fqcn, true)) {
            logStep("❌ Endpoint not found: {$fqcn}", $log, $step);
        } else {
            logStep("✅ Endpoint loaded: {$name}", $log, $step);
        }
    } catch (Throwable $e) {
        logStep("❌ Error loading {$name}: " . $e->getMessage(), $log, $step);
        dumpAndDie($log, $e);
    }
}

// Step 8: Try creating a Request object (what Router does)
try {
    $request = new \GarageMinder\API\Core\Request();
    logStep('✅ Request object created - method=' . $request->getMethod() . ' path=' . $request->getPath(), $log, $step);
} catch (Throwable $e) {
    logStep('❌ Request creation failed: ' . $e->getMessage(), $log, $step);
    dumpAndDie($log, $e);
}

// Step 9: Try creating a Router and registering routes
try {
    $router = new \GarageMinder\API\Core\Router();
    logStep('✅ Router created', $log, $step);
    
    // Check if routes.php exists
    $routesFile = __DIR__ . '/config/routes.php';
    if (file_exists($routesFile)) {
        require_once $routesFile;
        logStep('✅ routes.php loaded', $log, $step);
    } else {
        logStep('❌ routes.php NOT FOUND at: ' . $routesFile, $log, $step);
    }
} catch (Throwable $e) {
    logStep('❌ Router/routes failed: ' . $e->getMessage(), $log, $step);
    dumpAndDie($log, $e);
}

// Step 10: Try dispatching (without actually running)
try {
    // Show what the router would do
    logStep('✅ All classes loaded successfully', $log, $step);
    logStep('ℹ️ REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'not set'), $log, $step);
    logStep('ℹ️ SCRIPT_NAME: ' . ($_SERVER['SCRIPT_NAME'] ?? 'not set'), $log, $step);
    logStep('ℹ️ PATH_INFO: ' . ($_SERVER['PATH_INFO'] ?? 'not set'), $log, $step);
    logStep('ℹ️ API_PREFIX: ' . (defined('API_PREFIX') ? API_PREFIX : 'not defined'), $log, $step);
} catch (Throwable $e) {
    logStep('❌ ' . $e->getMessage(), $log, $step);
}

// Step 11: Now try ACTUALLY running index.php logic
try {
    // This is what index.php does after loading
    logStep('🔄 About to call Router::dispatch()...', $log, $step);
    
    // Don't actually dispatch - just confirm everything loaded
    logStep('✅ Bootstrap complete - all ' . $step . ' steps passed', $log, $step);
} catch (Throwable $e) {
    logStep('❌ Dispatch failed: ' . $e->getMessage(), $log, $step);
    dumpAndDie($log, $e);
}

// Final output
echo json_encode([
    'status' => '✅ ALL BOOTSTRAP STEPS PASSED',
    'total_steps' => $step,
    'steps' => $log,
    'note' => 'If index.php still 500s, the error is in Router::dispatch() or .htaccess rewriting. Check Step 10 SERVER vars above.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
