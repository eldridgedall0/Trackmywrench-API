<?php
/**
 * GarageMinder API - Diagnostic Script
 * 
 * Upload this to your api/v1/ directory and visit it in your browser:
 * https://trackmywrench.com/api/v1/debug.php
 * 
 * DELETE THIS FILE after debugging!
 */

header('Content-Type: application/json; charset=utf-8');

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    
    // 1. Check file locations
    'file_checks' => [
        'index.php_exists' => file_exists(__DIR__ . '/index.php'),
        'api_config_exists' => file_exists(__DIR__ . '/config/api_config.php'),
        'htaccess_exists' => file_exists(__DIR__ . '/.htaccess'),
        'current_dir' => __DIR__,
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    ],
    
    // 2. Check config.php path resolution
    'config_path_checks' => [],
    
    // 3. Check .htaccess / mod_rewrite
    'rewrite_checks' => [
        'mod_rewrite_loaded' => in_array('mod_rewrite', apache_get_modules() ?? []),
        'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'not set',
        'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? 'not set',
        'REDIRECT_URL' => $_SERVER['REDIRECT_URL'] ?? 'not set (rewrite may not be active)',
    ],
    
    // 4. Check database connectivity
    'database_check' => null,
    
    // 5. Check WordPress tables
    'wordpress_check' => null,
    
    // 6. Directory layout
    'directory_layout' => [],
];

// Config.php path probing - try common locations
$config_candidates = [
    __DIR__ . '/../../../config.php',                    // api/v1/ -> root/config.php (3 levels up)
    __DIR__ . '/../../config.php',                       // api/v1/ -> api/../config.php (2 levels up)  
    $_SERVER['DOCUMENT_ROOT'] . '/config.php',           // document root
    $_SERVER['DOCUMENT_ROOT'] . '/app/config.php',       // inside /app/
    dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php',   // above document root
    __DIR__ . '/../../../app/config.php',                // api -> app sibling
];

foreach ($config_candidates as $path) {
    $resolved = @realpath($path) ?: $path;
    $diagnostics['config_path_checks'][$path] = [
        'exists' => file_exists($path),
        'resolved' => $resolved,
        'readable' => is_readable($path),
    ];
    
    // If found, try to read DB vars
    if (file_exists($path) && is_readable($path)) {
        $contents = file_get_contents($path);
        $hasDbHost = (bool) preg_match('/\$db_host\s*=/', $contents);
        $hasDbName = (bool) preg_match('/\$db_name\s*=/', $contents);
        
        // Also check for WordPress-style defines
        $hasWpDefine = (bool) preg_match('/define\s*\(\s*[\'"]DB_NAME/', $contents);
        
        $diagnostics['config_path_checks'][$path]['has_db_host_var'] = $hasDbHost;
        $diagnostics['config_path_checks'][$path]['has_db_name_var'] = $hasDbName;
        $diagnostics['config_path_checks'][$path]['has_wp_defines'] = $hasWpDefine;
        $diagnostics['config_path_checks'][$path]['FOUND'] = '✅ THIS IS LIKELY YOUR CONFIG';
    }
}

// Directory layout - show what's around us
$layoutDirs = [
    'api/v1 (us)' => __DIR__,
    'api/' => dirname(__DIR__),
    'parent of api/' => dirname(__DIR__, 2),
    'document_root' => $_SERVER['DOCUMENT_ROOT'],
];

foreach ($layoutDirs as $label => $dir) {
    if (is_dir($dir)) {
        $entries = @scandir($dir);
        if ($entries) {
            $diagnostics['directory_layout'][$label] = array_values(
                array_filter($entries, fn($e) => $e !== '.' && $e !== '..')
            );
        }
    }
}

// Try to connect to database using the first config.php found
foreach ($config_candidates as $path) {
    if (file_exists($path) && is_readable($path)) {
        $contents = file_get_contents($path);
        $db = [];
        
        // Try GarageMinder-style vars
        if (preg_match('/\$db_host\s*=\s*[\'"]([^\'"]+)/', $contents, $m)) $db['host'] = $m[1];
        if (preg_match('/\$db_name\s*=\s*[\'"]([^\'"]+)/', $contents, $m)) $db['name'] = $m[1];
        if (preg_match('/\$db_user\s*=\s*[\'"]([^\'"]+)/', $contents, $m)) $db['user'] = $m[1];
        if (preg_match('/\$db_pass\s*=\s*[\'"]([^\'"]*)[\'"]/', $contents, $m)) $db['pass'] = $m[1];
        
        if (!empty($db['host']) && !empty($db['name'])) {
            try {
                $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
                $pdo = new PDO($dsn, $db['user'] ?? '', $db['pass'] ?? '');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $diagnostics['database_check'] = [
                    'status' => '✅ Connected',
                    'config_used' => $path,
                    'host' => $db['host'],
                    'database' => $db['name'],
                    'user' => $db['user'] ?? 'unknown',
                ];
                
                // Check for WordPress tables
                $tables = $pdo->query("SHOW TABLES LIKE 'wp_%'")->fetchAll(PDO::FETCH_COLUMN);
                $diagnostics['wordpress_check'] = [
                    'wp_users' => in_array('wp_users', $tables),
                    'wp_usermeta' => in_array('wp_usermeta', $tables),
                    'wp_table_count' => count($tables),
                ];
                
                // Check for GarageMinder tables
                $gmTables = $pdo->query("SHOW TABLES LIKE 'gm_%'")->fetchAll(PDO::FETCH_COLUMN);
                $diagnostics['garageminder_tables'] = $gmTables;
                
                // Check for API tables
                $apiTables = $pdo->query("SHOW TABLES LIKE 'api_%'")->fetchAll(PDO::FETCH_COLUMN);
                $diagnostics['api_tables'] = [
                    'found' => $apiTables,
                    'schema_imported' => count($apiTables) > 0,
                    'expected' => ['api_refresh_tokens', 'api_rate_limits', 'api_devices', 'api_request_log', 'api_user_preferences', 'api_sync_status'],
                ];
                
            } catch (PDOException $e) {
                $diagnostics['database_check'] = [
                    'status' => '❌ Connection failed',
                    'error' => $e->getMessage(),
                    'config_used' => $path,
                ];
            }
            break; // Stop after first successful config
        }
    }
}

if ($diagnostics['database_check'] === null) {
    $diagnostics['database_check'] = [
        'status' => '❌ No config.php found with database credentials',
        'hint' => 'Update GM_CONFIG_PATH in config/api_config.php to point to your existing config.php',
    ];
}

// Check mod_rewrite functionality  
if (!function_exists('apache_get_modules')) {
    $diagnostics['rewrite_checks']['mod_rewrite_loaded'] = 'unknown (apache_get_modules not available)';
}

// Summary & recommended fixes
$diagnostics['summary'] = [];

if (!$diagnostics['file_checks']['htaccess_exists']) {
    $diagnostics['summary'][] = '❌ .htaccess missing in api/v1/ - URL rewriting won\'t work';
}

$configFound = false;
foreach ($diagnostics['config_path_checks'] as $path => $info) {
    if ($info['exists']) { $configFound = true; break; }
}
if (!$configFound) {
    $diagnostics['summary'][] = '❌ No config.php found at any expected location - database connection will fail';
}

if (is_array($diagnostics['api_tables'] ?? null) && !($diagnostics['api_tables']['schema_imported'] ?? false)) {
    $diagnostics['summary'][] = '⚠️ API tables not found - run schema_api.sql against your database';
}

if (empty($diagnostics['summary'])) {
    $diagnostics['summary'][] = '✅ Basic checks passed';
}

echo json_encode($diagnostics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);