<?php
/**
 * Direct Login Test - bypasses framework to find the 500 error
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
];

// Step 1: Can we define GM_API and load config?
try {
    define('GM_API', true);
    define('GM_API_START', microtime(true));
    $results['step'] = '1_config_loading';
    
    require_once __DIR__ . '/config/api_config.php';
    $results['steps']['1_config'] = '✅ api_config.php loaded';
    $results['api_prefix'] = defined('API_PREFIX') ? API_PREFIX : 'NOT DEFINED';
    $results['api_debug'] = defined('API_DEBUG') ? API_DEBUG : 'NOT DEFINED';
    
} catch (Throwable $e) {
    $results['steps']['1_config'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 1, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// Step 2: Can we get DB config?
try {
    $results['step'] = '2_db_config';
    $dbConfig = get_db_config();
    $results['steps']['2_db_config'] = '✅ DB config extracted';
    $results['db_host'] = $dbConfig['host'];
    $results['db_name'] = $dbConfig['name'];
    $results['db_user'] = $dbConfig['user'];
    $results['db_pass_length'] = strlen($dbConfig['pass'] ?? '');
    
} catch (Throwable $e) {
    $results['steps']['2_db_config'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 2, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    
    // Try to show what GM_CONFIG_PATH points to
    $results['gm_config_path'] = defined('GM_CONFIG_PATH') ? GM_CONFIG_PATH : 'NOT DEFINED';
    $results['gm_config_exists'] = defined('GM_CONFIG_PATH') ? file_exists(GM_CONFIG_PATH) : false;
    $results['gm_config_resolved'] = defined('GM_CONFIG_PATH') ? @realpath(GM_CONFIG_PATH) : null;
    
    // Scan for config.php nearby
    $results['config_search'] = [];
    $searchPaths = [
        __DIR__ . '/../../../config.php',
        __DIR__ . '/../../config.php',
        __DIR__ . '/../config.php',
        __DIR__ . '/../../../../config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/gm/config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/app/config.php',
        $_SERVER['DOCUMENT_ROOT'] . '/gm/app/config.php',
    ];
    foreach ($searchPaths as $sp) {
        $results['config_search'][$sp] = file_exists($sp) ? '✅ EXISTS' : '❌';
    }
    
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Step 3: Can we connect to the database?
try {
    $results['step'] = '3_db_connect';
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $results['steps']['3_db_connect'] = '✅ Database connected';
    
} catch (Throwable $e) {
    $results['steps']['3_db_connect'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 3, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Step 4: Do the API tables exist?
try {
    $results['step'] = '4_api_tables';
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $apiTables = ['api_refresh_tokens', 'api_rate_limits', 'api_devices', 'api_request_log', 'api_user_preferences', 'api_sync_status'];
    $results['steps']['4_api_tables'] = [];
    foreach ($apiTables as $t) {
        $exists = in_array($t, $tables);
        $results['steps']['4_api_tables'][$t] = $exists ? '✅' : '❌ MISSING';
        if (!$exists) {
            $results['errors'][] = ['step' => 4, 'error' => "Table '$t' missing - run schema_api.sql"];
        }
    }
    
} catch (Throwable $e) {
    $results['steps']['4_api_tables'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 4, 'error' => $e->getMessage()];
}

// Step 5: Can we find WordPress user tables?
try {
    $results['step'] = '5_wp_tables';
    $wpUsers = $pdo->query("SELECT COUNT(*) FROM wp_users")->fetchColumn();
    $results['steps']['5_wp_users'] = "✅ wp_users has $wpUsers users";
    
    // Check wp_usermeta
    $wpMeta = $pdo->query("SELECT COUNT(*) FROM wp_usermeta")->fetchColumn();
    $results['steps']['5_wp_usermeta'] = "✅ wp_usermeta has $wpMeta rows";
    
} catch (Throwable $e) {
    $results['steps']['5_wp_tables'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 5, 'error' => $e->getMessage()];
    
    // Maybe different prefix?
    try {
        $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $userTables = array_filter($allTables, fn($t) => str_contains($t, 'user'));
        $results['tables_with_user'] = array_values($userTables);
        $results['all_table_prefixes'] = array_unique(array_map(fn($t) => explode('_', $t)[0], $allTables));
    } catch (Throwable $e2) {}
}

// Step 6: Try loading the Database core class
try {
    $results['step'] = '6_autoload';
    
    // Manual autoload test
    $coreFiles = [
        'Database'  => __DIR__ . '/core/Database.php',
        'Router'    => __DIR__ . '/core/Router.php',
        'Request'   => __DIR__ . '/core/Request.php',
        'Response'  => __DIR__ . '/core/Response.php',
        'JWT'       => __DIR__ . '/core/JWT.php',
        'Validator' => __DIR__ . '/core/Validator.php',
    ];
    
    foreach ($coreFiles as $name => $file) {
        $results['steps']['6_core_files'][$name] = file_exists($file) ? '✅' : '❌ MISSING';
    }
    
    // Try loading Database
    require_once __DIR__ . '/core/Database.php';
    $results['steps']['6_database_class'] = '✅ Database.php loaded';
    
    $db = \GarageMinder\API\Core\Database::getInstance();
    $results['steps']['6_database_instance'] = '✅ Database instance created';
    
} catch (Throwable $e) {
    $results['steps']['6_autoload'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 6, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
}

// Step 7: Try loading the Login endpoint
try {
    $results['step'] = '7_login_endpoint';
    
    $endpointFile = __DIR__ . '/endpoints/auth/LoginEndpoint.php';
    $results['steps']['7_login_file_exists'] = file_exists($endpointFile) ? '✅' : '❌ MISSING';
    
    if (file_exists($endpointFile)) {
        // Load dependencies
        $deps = [
            __DIR__ . '/core/Response.php',
            __DIR__ . '/core/JWT.php',
            __DIR__ . '/core/Validator.php',
            __DIR__ . '/endpoints/BaseEndpoint.php',
            __DIR__ . '/models/User.php',
            __DIR__ . '/models/Token.php',
        ];
        
        foreach ($deps as $dep) {
            if (file_exists($dep)) {
                require_once $dep;
                $results['steps']['7_deps'][basename($dep)] = '✅';
            } else {
                $results['steps']['7_deps'][basename($dep)] = '❌ MISSING';
                $results['errors'][] = ['step' => 7, 'error' => "Missing: $dep"];
            }
        }
        
        require_once $endpointFile;
        $results['steps']['7_login_loaded'] = '✅ LoginEndpoint.php loaded';
    }
    
} catch (Throwable $e) {
    $results['steps']['7_login_endpoint'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 7, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()];
}

// Step 8: Try loading middleware
try {
    $results['step'] = '8_middleware';
    
    $mwFiles = [
        __DIR__ . '/middleware/CorsMiddleware.php',
        __DIR__ . '/middleware/LoggingMiddleware.php',
        __DIR__ . '/middleware/RateLimitMiddleware.php',
        __DIR__ . '/middleware/AuthMiddleware.php',
        __DIR__ . '/middleware/AdminMiddleware.php',
    ];
    
    foreach ($mwFiles as $mw) {
        $name = basename($mw);
        if (file_exists($mw)) {
            require_once $mw;
            $results['steps']['8_middleware'][$name] = '✅';
        } else {
            $results['steps']['8_middleware'][$name] = '❌ MISSING';
        }
    }
    
} catch (Throwable $e) {
    $results['steps']['8_middleware'] = '❌ ' . $e->getMessage();
    $results['errors'][] = ['step' => 8, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()];
}

// Summary
$results['step'] = 'complete';
$hasErrors = !empty($results['errors']);
$results['summary'] = $hasErrors 
    ? '❌ Found ' . count($results['errors']) . ' error(s) - see details above'
    : '✅ All checks passed - the 500 error may be in request processing logic';

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
