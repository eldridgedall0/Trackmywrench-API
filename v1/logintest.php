<?php
/**
 * GarageMinder API - Dual Database Diagnostic
 * Tests: config discovery, credential extraction, DB connections, table prefix, file checks
 * Upload to: gm/api/v1/logintest.php
 * Visit: https://yesca.st/gm/api/v1/logintest.php
 * DELETE after debugging!
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$results = ['step' => 'starting', 'errors' => [], 'steps' => []];

// Helper: build DSN handling host:port
function build_dsn(array $config): string {
    $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset=utf8mb4";
    if (!empty($config['port'])) {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4";
    }
    return $dsn;
}

// ============================================================
// Step 1: Load api_config.php
// ============================================================
try {
    if (!defined('GM_API')) define('GM_API', true);
    if (!defined('GM_API_START')) define('GM_API_START', microtime(true));
    
    require_once __DIR__ . '/config/api_config.php';
    $results['steps']['1_config'] = '✅ api_config.php loaded';
} catch (Throwable $e) {
    $results['steps']['1_config'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 1, 'error' => $e->getMessage()];
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit;
}

// ============================================================
// Step 2: Verify config paths resolved
// ============================================================
$results['steps']['2_paths'] = [
    'GM_CONFIG_PATH' => defined('GM_CONFIG_PATH') ? GM_CONFIG_PATH : 'NOT DEFINED',
    'WP_CONFIG_PATH' => defined('WP_CONFIG_PATH') ? WP_CONFIG_PATH : 'NOT DEFINED',
    'WP_TABLE_PREFIX' => defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'NOT DEFINED',
    'gm_exists' => defined('GM_CONFIG_PATH') && file_exists(GM_CONFIG_PATH) ? '✅' : '❌',
    'wp_exists' => defined('WP_CONFIG_PATH') && file_exists(WP_CONFIG_PATH) ? '✅' : '❌',
];

// ============================================================
// Step 3: Extract GarageMinder DB credentials
// ============================================================
try {
    $gmConfig = get_db_config();
    $results['steps']['3_gm_db'] = [
        'status' => '✅ Credentials extracted',
        'host' => $gmConfig['host'],
        'port' => $gmConfig['port'] ?? 'none',
        'name' => $gmConfig['name'],
        'user' => $gmConfig['user'],
        'pass_len' => strlen($gmConfig['pass'] ?? ''),
    ];
} catch (Throwable $e) {
    $results['steps']['3_gm_db'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 3, 'error' => $e->getMessage()];
}

// ============================================================
// Step 4: Extract WordPress DB credentials
// ============================================================
try {
    $wpConfig = get_wp_db_config();
    $results['steps']['4_wp_db'] = [
        'status' => '✅ Credentials extracted',
        'host' => $wpConfig['host'],
        'port' => $wpConfig['port'] ?? 'none',
        'name' => $wpConfig['name'],
        'user' => $wpConfig['user'],
        'pass_len' => strlen($wpConfig['pass'] ?? ''),
        'table_prefix' => WP_TABLE_PREFIX,
    ];
} catch (Throwable $e) {
    $results['steps']['4_wp_db'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 4, 'error' => $e->getMessage()];
}

// ============================================================
// Step 5: Connect to GarageMinder DB
// ============================================================
try {
    $gmDsn = build_dsn($gmConfig);
    $gmPdo = new PDO($gmDsn, $gmConfig['user'], $gmConfig['pass']);
    $gmPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $tables = $gmPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $apiTables = ['api_refresh_tokens', 'api_rate_limits', 'api_devices', 'api_request_log', 'api_user_preferences', 'api_sync_status'];
    
    $apiCheck = [];
    foreach ($apiTables as $t) {
        $apiCheck[$t] = in_array($t, $tables) ? '✅' : '❌ MISSING';
    }
    
    $results['steps']['5_gm_connect'] = [
        'status' => '✅ Connected to GarageMinder DB',
        'dsn' => $gmDsn,
        'total_tables' => count($tables),
        'api_tables' => $apiCheck,
        'has_vehicles' => in_array('vehicles', $tables) ? '✅' : '❌',
        'has_entries' => in_array('entries', $tables) ? '✅' : '❌',
    ];
} catch (Throwable $e) {
    $results['steps']['5_gm_connect'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 5, 'error' => $e->getMessage()];
}

// ============================================================
// Step 6: Connect to WordPress DB (with dynamic prefix)
// ============================================================
try {
    $wpDsn = build_dsn($wpConfig);
    $wpPdo = new PDO($wpDsn, $wpConfig['user'], $wpConfig['pass']);
    $wpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $wpTables = $wpPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $prefix = WP_TABLE_PREFIX;
    
    // Check key WordPress tables with actual prefix
    $wpTableChecks = [];
    $keyTables = ['users', 'usermeta', 'options', 'posts'];
    foreach ($keyTables as $t) {
        $fullName = $prefix . $t;
        $wpTableChecks[$fullName] = in_array($fullName, $wpTables) ? '✅' : '❌ MISSING';
    }
    
    // Check SWPM tables
    $swpmTables = ['swpm_members_tbl', 'swpm_membership_tbl'];
    foreach ($swpmTables as $t) {
        $fullName = $prefix . $t;
        $wpTableChecks[$fullName] = in_array($fullName, $wpTables) ? '✅' : '❌ not found (ok if no SWPM)';
    }
    
    // Count users
    $userTable = $prefix . 'users';
    $userCount = $wpPdo->query("SELECT COUNT(*) FROM `{$userTable}`")->fetchColumn();
    
    $results['steps']['6_wp_connect'] = [
        'status' => '✅ Connected to WordPress DB',
        'dsn' => $wpDsn,
        'total_tables' => count($wpTables),
        'prefix' => $prefix,
        'key_tables' => $wpTableChecks,
        'user_count' => (int) $userCount,
    ];
} catch (Throwable $e) {
    $results['steps']['6_wp_connect'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 6, 'error' => $e->getMessage()];
}

// ============================================================
// Step 7: Test Database class (both instances)
// ============================================================
try {
    require_once __DIR__ . '/core/Database.php';
    
    $gmDb = \GarageMinder\API\Core\Database::getInstance();
    $wpDb = \GarageMinder\API\Core\Database::getWordPress();
    
    // Verify they're separate databases
    $gmName = $gmDb->fetchColumn("SELECT DATABASE()");
    $wpName = $wpDb->fetchColumn("SELECT DATABASE()");
    
    // Test wpTable helper
    $testTable = \GarageMinder\API\Core\Database::wpTable('users');
    
    $results['steps']['7_db_class'] = [
        'status' => '✅ Both Database instances working',
        'gm_database' => $gmName,
        'wp_database' => $wpName,
        'separate_dbs' => ($gmName !== $wpName) ? '✅ Yes (correct)' : '⚠️ Same DB',
        'wpTable_test' => $testTable,
    ];
} catch (Throwable $e) {
    $results['steps']['7_db_class'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 7, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
}

// ============================================================
// Step 8-11: File existence checks
// ============================================================
$fileChecks = [
    '8_core_files' => [
        'Database.php' => __DIR__ . '/core/Database.php',
        'Router.php' => __DIR__ . '/core/Router.php',
        'Request.php' => __DIR__ . '/core/Request.php',
        'Response.php' => __DIR__ . '/core/Response.php',
        'JWTHandler.php' => __DIR__ . '/core/JWTHandler.php',
        'Validator.php' => __DIR__ . '/core/Validator.php',
        'Middleware.php' => __DIR__ . '/core/Middleware.php',
        'Logger.php' => __DIR__ . '/core/Logger.php',
        'RateLimiter.php' => __DIR__ . '/core/RateLimiter.php',
    ],
    '9_middleware' => [
        'CorsMiddleware.php' => __DIR__ . '/middleware/CorsMiddleware.php',
        'AuthMiddleware.php' => __DIR__ . '/middleware/AuthMiddleware.php',
        'AdminMiddleware.php' => __DIR__ . '/middleware/AdminMiddleware.php',
        'RateLimitMiddleware.php' => __DIR__ . '/middleware/RateLimitMiddleware.php',
        'LoggingMiddleware.php' => __DIR__ . '/middleware/LoggingMiddleware.php',
    ],
    '10_endpoints' => [
        'auth/LoginEndpoint.php' => __DIR__ . '/endpoints/auth/LoginEndpoint.php',
        'auth/TokenExchangeEndpoint.php' => __DIR__ . '/endpoints/auth/TokenExchangeEndpoint.php',
        'auth/RefreshEndpoint.php' => __DIR__ . '/endpoints/auth/RefreshEndpoint.php',
        'auth/VerifyEndpoint.php' => __DIR__ . '/endpoints/auth/VerifyEndpoint.php',
        'auth/LogoutEndpoint.php' => __DIR__ . '/endpoints/auth/LogoutEndpoint.php',
        'vehicles/ListEndpoint.php' => __DIR__ . '/endpoints/vehicles/ListEndpoint.php',
        'vehicles/DetailEndpoint.php' => __DIR__ . '/endpoints/vehicles/DetailEndpoint.php',
        'vehicles/OdometerEndpoint.php' => __DIR__ . '/endpoints/vehicles/OdometerEndpoint.php',
        'sync/PushEndpoint.php' => __DIR__ . '/endpoints/sync/PushEndpoint.php',
        'sync/StatusEndpoint.php' => __DIR__ . '/endpoints/sync/StatusEndpoint.php',
        'user/ProfileEndpoint.php' => __DIR__ . '/endpoints/user/ProfileEndpoint.php',
        'BaseEndpoint.php' => __DIR__ . '/endpoints/BaseEndpoint.php',
    ],
    '11_models' => [
        'User.php' => __DIR__ . '/models/User.php',
        'Vehicle.php' => __DIR__ . '/models/Vehicle.php',
        'Token.php' => __DIR__ . '/models/Token.php',
        'Device.php' => __DIR__ . '/models/Device.php',
        'Reminder.php' => __DIR__ . '/models/Reminder.php',
    ],
];

foreach ($fileChecks as $step => $files) {
    foreach ($files as $label => $path) {
        $results['steps'][$step][$label] = file_exists($path) ? '✅' : '❌ MISSING';
    }
}

// ============================================================
// Step 12: Find admin user in WordPress DB
// ============================================================
try {
    if (isset($wpDb)) {
        $usersTable = \GarageMinder\API\Core\Database::wpTable('users');
        $metaTable = \GarageMinder\API\Core\Database::wpTable('usermeta');
        $capKey = WP_TABLE_PREFIX . 'capabilities';
        
        $admin = $wpDb->fetchOne(
            "SELECT u.ID, u.user_login, u.user_email, u.display_name, m.meta_value as capabilities
             FROM `{$usersTable}` u
             LEFT JOIN `{$metaTable}` m ON m.user_id = u.ID AND m.meta_key = ?
             WHERE u.ID = 1 OR m.meta_value LIKE '%administrator%'
             ORDER BY u.ID ASC LIMIT 1",
            [$capKey]
        );
        
        if ($admin) {
            $results['steps']['12_admin_user'] = [
                'status' => '✅ Admin user found',
                'id' => $admin['ID'],
                'username' => $admin['user_login'],
                'email' => $admin['user_email'],
                'has_caps' => !empty($admin['capabilities']) ? '✅' : '⚠️ no capabilities meta',
            ];
        } else {
            $results['steps']['12_admin_user'] = '⚠️ No admin user found (may be normal)';
        }
    } else {
        $results['steps']['12_admin_user'] = '⏭️ Skipped (WordPress DB not connected)';
    }
} catch (Throwable $e) {
    $results['steps']['12_admin_user'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 12, 'error' => $e->getMessage()];
}

// ============================================================
// Summary
// ============================================================
$results['step'] = 'complete';
$hasErrors = !empty($results['errors']);
$results['summary'] = $hasErrors
    ? '❌ Found ' . count($results['errors']) . ' error(s) - see details above'
    : '✅ All checks passed! Both databases connected with prefix: ' . (defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'wp_');

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
