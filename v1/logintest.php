<?php
/**
 * Dual-Database Login Test
 * Tests both GarageMinder DB and WordPress DB connections
 * Upload to: gm/api/v1/logintest.php
 * Visit: https://yesca.st/gm/api/v1/logintest.php
 * DELETE after debugging!
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$results = [
    'step' => 'starting',
    'errors' => [],
    'steps' => [],
];

// Step 1: Load api_config.php
try {
    define('GM_API', true);
    define('GM_API_START', microtime(true));
    
    require_once __DIR__ . '/config/api_config.php';
    $results['steps']['1_config'] = '✅ api_config.php loaded';
    $results['api_prefix'] = defined('API_PREFIX') ? API_PREFIX : 'NOT DEFINED';
    
} catch (Throwable $e) {
    $results['steps']['1_config'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 1, 'error' => $e->getMessage()];
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Step 2: Config paths resolved
try {
    $results['steps']['2_paths'] = [
        'GM_CONFIG_PATH' => defined('GM_CONFIG_PATH') ? GM_CONFIG_PATH : 'NOT DEFINED',
        'WP_CONFIG_PATH' => defined('WP_CONFIG_PATH') ? WP_CONFIG_PATH : 'NOT DEFINED',
        'gm_exists' => defined('GM_CONFIG_PATH') ? (file_exists(GM_CONFIG_PATH) ? '✅' : '❌') : '❌',
        'wp_exists' => defined('WP_CONFIG_PATH') ? (file_exists(WP_CONFIG_PATH) ? '✅' : '❌') : '❌',
    ];
} catch (Throwable $e) {
    $results['steps']['2_paths'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 2, 'error' => $e->getMessage()];
}

// Step 3: GarageMinder DB credentials
try {
    $gmConfig = get_db_config();
    $results['steps']['3_gm_db'] = [
        'status' => '✅ Credentials extracted',
        'host' => $gmConfig['host'],
        'name' => $gmConfig['name'],
        'user' => $gmConfig['user'],
        'pass_len' => strlen($gmConfig['pass'] ?? ''),
    ];
} catch (Throwable $e) {
    $results['steps']['3_gm_db'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 3, 'error' => $e->getMessage()];
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Step 4: WordPress DB credentials
try {
    $wpConfig = get_wp_db_config();
    $results['steps']['4_wp_db'] = [
        'status' => '✅ Credentials extracted',
        'host' => $wpConfig['host'],
        'name' => $wpConfig['name'],
        'user' => $wpConfig['user'],
        'pass_len' => strlen($wpConfig['pass'] ?? ''),
    ];
} catch (Throwable $e) {
    $results['steps']['4_wp_db'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 4, 'error' => $e->getMessage()];
}

// Step 5: Connect to GarageMinder DB
try {
    $gmDsn = "mysql:host={$gmConfig['host']};dbname={$gmConfig['name']};charset=utf8mb4";
    $gmPdo = new PDO($gmDsn, $gmConfig['user'], $gmConfig['pass']);
    $gmPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $gmTables = $gmPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $apiTables = ['api_refresh_tokens', 'api_rate_limits', 'api_devices', 'api_request_log', 'api_user_preferences', 'api_sync_status'];
    $gmTableStatus = [];
    foreach ($apiTables as $t) {
        $gmTableStatus[$t] = in_array($t, $gmTables) ? '✅' : '❌ MISSING';
    }
    
    $results['steps']['5_gm_connect'] = [
        'status' => '✅ Connected to GarageMinder DB',
        'total_tables' => count($gmTables),
        'api_tables' => $gmTableStatus,
        'has_vehicles' => in_array('vehicles', $gmTables) ? '✅' : '❌',
        'has_entries' => in_array('entries', $gmTables) ? '✅' : '❌',
    ];
    
} catch (Throwable $e) {
    $results['steps']['5_gm_connect'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 5, 'error' => $e->getMessage()];
}

// Step 6: Connect to WordPress DB
try {
    if (isset($wpConfig)) {
        $wpDsn = "mysql:host={$wpConfig['host']};dbname={$wpConfig['name']};charset=utf8mb4";
        $wpPdo = new PDO($wpDsn, $wpConfig['user'], $wpConfig['pass']);
        $wpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $wpTables = $wpPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        
        $hasWpUsers = in_array('wp_users', $wpTables);
        $hasWpUsermeta = in_array('wp_usermeta', $wpTables);
        $hasSWPM = in_array('wp_swpm_members_tbl', $wpTables);
        
        $userCount = $hasWpUsers ? $wpPdo->query("SELECT COUNT(*) FROM wp_users")->fetchColumn() : 0;
        
        $results['steps']['6_wp_connect'] = [
            'status' => '✅ Connected to WordPress DB',
            'total_tables' => count($wpTables),
            'wp_users' => $hasWpUsers ? "✅ ({$userCount} users)" : '❌ MISSING',
            'wp_usermeta' => $hasWpUsermeta ? '✅' : '❌ MISSING',
            'wp_swpm_members_tbl' => $hasSWPM ? '✅' : '❌ (Simple Membership not found)',
        ];
    } else {
        $results['steps']['6_wp_connect'] = '⏭️ Skipped - no WP credentials';
    }
    
} catch (Throwable $e) {
    $results['steps']['6_wp_connect'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 6, 'error' => $e->getMessage()];
}

// Step 7: Test Database class dual-connection
try {
    require_once __DIR__ . '/core/Database.php';
    
    $gmDb = \GarageMinder\API\Core\Database::getInstance();
    $results['steps']['7_db_class']['gm_instance'] = '✅ GarageMinder DB singleton created';
    
    $wpDb = \GarageMinder\API\Core\Database::getWordPress();
    $results['steps']['7_db_class']['wp_instance'] = '✅ WordPress DB singleton created';
    
    // Test a query on each
    $gmTest = $gmDb->fetchColumn("SELECT DATABASE()");
    $wpTest = $wpDb->fetchColumn("SELECT DATABASE()");
    
    $results['steps']['7_db_class']['gm_database'] = $gmTest;
    $results['steps']['7_db_class']['wp_database'] = $wpTest;
    $results['steps']['7_db_class']['different_dbs'] = ($gmTest !== $wpTest) ? '✅ Separate databases confirmed' : '⚠️ Same database';
    
} catch (Throwable $e) {
    $results['steps']['7_db_class'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 7, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
}

// Step 8: Core files check
$coreFiles = [
    'Database.php'    => __DIR__ . '/core/Database.php',
    'Router.php'      => __DIR__ . '/core/Router.php',
    'Request.php'     => __DIR__ . '/core/Request.php',
    'Response.php'    => __DIR__ . '/core/Response.php',
    'JWTHandler.php'  => __DIR__ . '/core/JWTHandler.php',
    'Validator.php'   => __DIR__ . '/core/Validator.php',
    'Middleware.php'  => __DIR__ . '/core/Middleware.php',
    'Logger.php'      => __DIR__ . '/core/Logger.php',
    'RateLimiter.php' => __DIR__ . '/core/RateLimiter.php',
];

foreach ($coreFiles as $name => $file) {
    $results['steps']['8_core_files'][$name] = file_exists($file) ? '✅' : '❌ MISSING';
}

// Step 9: Middleware files check
$mwFiles = [
    'CorsMiddleware.php'      => __DIR__ . '/middleware/CorsMiddleware.php',
    'AuthMiddleware.php'      => __DIR__ . '/middleware/AuthMiddleware.php',
    'AdminMiddleware.php'     => __DIR__ . '/middleware/AdminMiddleware.php',
    'RateLimitMiddleware.php' => __DIR__ . '/middleware/RateLimitMiddleware.php',
    'LoggingMiddleware.php'   => __DIR__ . '/middleware/LoggingMiddleware.php',
];

foreach ($mwFiles as $name => $file) {
    $results['steps']['9_middleware'][$name] = file_exists($file) ? '✅' : '❌ MISSING';
}

// Step 10: Endpoint files check
$endpoints = [
    'auth/LoginEndpoint.php'           => __DIR__ . '/endpoints/auth/LoginEndpoint.php',
    'auth/TokenExchangeEndpoint.php'   => __DIR__ . '/endpoints/auth/TokenExchangeEndpoint.php',
    'auth/RefreshEndpoint.php'         => __DIR__ . '/endpoints/auth/RefreshEndpoint.php',
    'auth/VerifyEndpoint.php'          => __DIR__ . '/endpoints/auth/VerifyEndpoint.php',
    'auth/LogoutEndpoint.php'          => __DIR__ . '/endpoints/auth/LogoutEndpoint.php',
    'vehicles/ListEndpoint.php'        => __DIR__ . '/endpoints/vehicles/ListEndpoint.php',
    'vehicles/DetailEndpoint.php'      => __DIR__ . '/endpoints/vehicles/DetailEndpoint.php',
    'vehicles/OdometerEndpoint.php'    => __DIR__ . '/endpoints/vehicles/OdometerEndpoint.php',
    'sync/PushEndpoint.php'            => __DIR__ . '/endpoints/sync/PushEndpoint.php',
    'sync/StatusEndpoint.php'          => __DIR__ . '/endpoints/sync/StatusEndpoint.php',
    'user/ProfileEndpoint.php'         => __DIR__ . '/endpoints/user/ProfileEndpoint.php',
    'BaseEndpoint.php'                 => __DIR__ . '/endpoints/BaseEndpoint.php',
];

foreach ($endpoints as $name => $file) {
    $results['steps']['10_endpoints'][$name] = file_exists($file) ? '✅' : '❌ MISSING';
}

// Step 11: Model files check
$models = [
    'User.php'     => __DIR__ . '/models/User.php',
    'Vehicle.php'  => __DIR__ . '/models/Vehicle.php',
    'Token.php'    => __DIR__ . '/models/Token.php',
    'Device.php'   => __DIR__ . '/models/Device.php',
    'Reminder.php' => __DIR__ . '/models/Reminder.php',
];

foreach ($models as $name => $file) {
    $results['steps']['11_models'][$name] = file_exists($file) ? '✅' : '❌ MISSING';
}

// Step 12: Try finding an admin user via WordPress DB
try {
    if (isset($wpDb)) {
        $admin = $wpDb->fetchOne(
            "SELECT u.ID, u.user_login, u.user_email 
             FROM wp_users u 
             JOIN wp_usermeta m ON u.ID = m.user_id 
             WHERE m.meta_key = 'wp_capabilities' AND m.meta_value LIKE '%administrator%' 
             LIMIT 1"
        );
        
        if ($admin) {
            $results['steps']['12_admin_user'] = "✅ Admin: {$admin['user_login']} (ID: {$admin['ID']})";
        } else {
            $results['steps']['12_admin_user'] = '⚠️ No admin user found';
        }
    }
} catch (Throwable $e) {
    $results['steps']['12_admin_user'] = '❌ ' . $e->getMessage();
}

// Summary
$results['step'] = 'complete';
$errorCount = count($results['errors']);
$results['summary'] = $errorCount === 0
    ? '✅ All checks passed! API should be functional.'
    : "❌ Found {$errorCount} error(s) - see details above";

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
