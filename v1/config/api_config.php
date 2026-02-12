<?php
/**
 * GarageMinder Mobile API - Configuration
 * 
 * DUAL DATABASE SUPPORT:
 * - GarageMinder DB: vehicles, entries, reminders, api_* tables
 * - WordPress DB: wp_users, wp_usermeta (authentication)
 * 
 * ENVIRONMENT SUPPORT:
 * - Dev:  https://yesca.st/gm/  (all under /gm/)
 * - Prod: trackmywrench.com (WP) + app.trackmywrench.com (Garage)
 * 
 * To override paths, create config/environment.php:
 *   <?php return [
 *       'garage_config' => '/path/to/garage/config.php',
 *       'wp_config'     => '/path/to/wp-config.php',
 *   ];
 */

// Prevent direct access
if (!defined('GM_API')) {
    http_response_code(403);
    exit('Direct access forbidden');
}

// ============================================================================
// Environment Detection & Config Path Resolution
// ============================================================================

/**
 * Resolve paths to both config files.
 * Returns ['garage' => '/path/to/config.php', 'wordpress' => '/path/to/wp-config.php']
 */
function resolve_config_paths(): array {
    $paths = ['garage' => null, 'wordpress' => null];
    
    // 1. Manual override via config/environment.php
    $overrideFile = __DIR__ . '/environment.php';
    if (file_exists($overrideFile)) {
        $override = require $overrideFile;
        if (is_array($override)) {
            if (!empty($override['garage_config']) && file_exists($override['garage_config'])) {
                $paths['garage'] = $override['garage_config'];
            }
            if (!empty($override['wp_config']) && file_exists($override['wp_config'])) {
                $paths['wordpress'] = $override['wp_config'];
            }
        } elseif (is_string($override) && file_exists($override)) {
            // Simple string = garage config only
            $paths['garage'] = $override;
        }
    }
    
    // 2. Auto-discover garage config.php
    if (!$paths['garage']) {
        $apiDir = dirname(__DIR__);  // api/v1/
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname($apiDir, 3);
        
        $garageCandidates = [
            $apiDir . '/../garage/config.php',            // /gm/api/../garage/ → /gm/garage/
            dirname($apiDir) . '/garage/config.php',      // /gm/garage/
            $docRoot . '/gm/garage/config.php',           // doc_root/gm/garage/
            $docRoot . '/garage/config.php',              // doc_root/garage/
            $docRoot . '/app/config.php',                 // doc_root/app/
            $docRoot . '/config.php',                     // doc_root/
            $apiDir . '/../../garage/config.php',         // up two levels
            $apiDir . '/../../../config.php',             // up three levels
        ];
        
        foreach ($garageCandidates as $candidate) {
            $resolved = @realpath($candidate);
            if ($resolved && is_readable($resolved)) {
                $contents = file_get_contents($resolved);
                if (preg_match('/GM_DB_HOST|GM_DB_NAME|\$db_host|\$db_name/i', $contents)) {
                    $paths['garage'] = $resolved;
                    break;
                }
            }
        }
    }
    
    // 3. Auto-discover wp-config.php
    if (!$paths['wordpress']) {
        // First: try to read WP_PATH from garage config if found
        $wpPath = null;
        if ($paths['garage']) {
            $garageContents = file_get_contents($paths['garage']);
            // Match: const WP_PATH = '/home2/yesca/public_html/gm';
            if (preg_match("/WP_PATH\s*=\s*['\"]([^'\"]+)['\"]/", $garageContents, $m)) {
                $wpPath = $m[1];
            }
        }
        
        $apiDir = dirname(__DIR__);
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname($apiDir, 3);
        
        $wpCandidates = [];
        
        // If WP_PATH found in garage config, check there first
        if ($wpPath) {
            $wpCandidates[] = $wpPath . '/wp-config.php';
        }
        
        $wpCandidates = array_merge($wpCandidates, [
            $apiDir . '/../wp-config.php',                // /gm/api/../wp-config.php → /gm/wp-config.php
            dirname($apiDir) . '/wp-config.php',          // /gm/wp-config.php
            $docRoot . '/wp-config.php',                  // doc_root/wp-config.php
            $docRoot . '/gm/wp-config.php',               // doc_root/gm/wp-config.php
            dirname($docRoot) . '/wp-config.php',         // above doc_root
            $apiDir . '/../../wp-config.php',             // up two levels
        ]);
        
        foreach ($wpCandidates as $candidate) {
            $resolved = @realpath($candidate);
            if ($resolved && is_readable($resolved)) {
                $contents = file_get_contents($resolved);
                if (preg_match('/DB_NAME|DB_HOST/', $contents)) {
                    $paths['wordpress'] = $resolved;
                    break;
                }
            }
        }
    }
    
    if (!$paths['garage']) {
        throw new \RuntimeException(
            "GarageMinder config.php not found. Create config/environment.php with:\n" .
            "<?php return ['garage_config' => '/absolute/path/to/garage/config.php', 'wp_config' => '/path/to/wp-config.php'];"
        );
    }
    
    if (!$paths['wordpress']) {
        throw new \RuntimeException(
            "WordPress wp-config.php not found. Add 'wp_config' to config/environment.php:\n" .
            "<?php return ['garage_config' => '" . $paths['garage'] . "', 'wp_config' => '/path/to/wp-config.php'];"
        );
    }
    
    return $paths;
}

$_configPaths = resolve_config_paths();
define('GM_CONFIG_PATH', $_configPaths['garage']);
define('WP_CONFIG_PATH', $_configPaths['wordpress']);

// ============================================================================
// JWT Configuration
// ============================================================================
define('JWT_SECRET_FILE', __DIR__ . '/jwt_secret.key');
define('JWT_ACCESS_TOKEN_EXPIRY', 1800);       // 30 minutes
define('JWT_REFRESH_TOKEN_EXPIRY', 2592000);    // 30 days
define('JWT_ISSUER', 'garageminder-api');
define('JWT_ALGORITHM', 'HS256');

// ============================================================================
// Rate Limiting
// ============================================================================
define('RATE_LIMIT_USER_REQUESTS', 100);
define('RATE_LIMIT_USER_WINDOW', 60);
define('RATE_LIMIT_IP_REQUESTS', 200);
define('RATE_LIMIT_IP_WINDOW', 60);
define('RATE_LIMIT_LOGIN_REQUESTS', 10);
define('RATE_LIMIT_LOGIN_WINDOW', 300);

// ============================================================================
// API Settings
// ============================================================================
define('API_VERSION', '1.0.0');

// Auto-detect base path
$_scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php');
define('API_PREFIX', rtrim($_scriptDir, '/'));

define('API_DEBUG', true);                        // TODO: Set false for production!
define('API_LOG_REQUESTS', true);
define('API_LOG_BODY', false);
define('API_MAX_BODY_SIZE', 1048576);

// ============================================================================
// CORS - auto-includes current domain
// ============================================================================
$_currentOrigin = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
define('CORS_ALLOWED_ORIGINS', array_unique(array_filter([
    $_currentOrigin,
    'https://trackmywrench.com',
    'https://www.trackmywrench.com',
    'https://app.trackmywrench.com',
    'https://yesca.st',
])));
define('CORS_ALLOW_CREDENTIALS', true);
define('CORS_MAX_AGE', 86400);

// ============================================================================
// Security
// ============================================================================
define('ADMIN_ROLE', 'administrator');
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('BRUTE_FORCE_LOCKOUT_ATTEMPTS', 10);
define('BRUTE_FORCE_LOCKOUT_DURATION', 900);

// ============================================================================
// Reminders & Sync
// ============================================================================
define('REMINDERS_DUE_WINDOW_DAYS', 30);
define('REMINDERS_OVERDUE_INCLUDE', true);
define('SYNC_MAX_VEHICLES_PER_PUSH', 50);
define('SYNC_MAX_ODOMETER_JUMP', 10000);

// ============================================================================
// Helper: JWT Secret (auto-generates on first run)
// ============================================================================
function get_jwt_secret(): string {
    $secret_file = JWT_SECRET_FILE;
    
    if (!file_exists($secret_file)) {
        $secret = bin2hex(random_bytes(64));
        file_put_contents($secret_file, $secret);
        chmod($secret_file, 0600);
    }
    
    $secret = trim(file_get_contents($secret_file));
    
    if (strlen($secret) < 32) {
        throw new \RuntimeException('JWT secret is too short. Regenerate jwt_secret.key');
    }
    
    return $secret;
}

// ============================================================================
// Helper: Parse DB credentials from a config file
// Supports 3 patterns:
//   1. const GM_DB_HOST = 'value';       (GarageMinder style)
//   2. $db_host = 'value';               (legacy variable style)
//   3. define('DB_HOST', 'value');        (WordPress style)
// ============================================================================
function parse_db_credentials(string $file_path, string $label = 'config'): array {
    if (!file_exists($file_path)) {
        throw new \RuntimeException("{$label} not found at: {$file_path}");
    }
    
    $contents = file_get_contents($file_path);
    $db = [];
    
    // Pattern 1: const GM_DB_HOST = 'value';
    $const_patterns = [
        'host' => '/const\s+GM_DB_HOST\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'name' => '/const\s+GM_DB_NAME\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'user' => '/const\s+GM_DB_USER\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'pass' => '/const\s+GM_DB_PASS\s*=\s*[\'"]([^\'"]*)[\'"]/',
    ];
    
    foreach ($const_patterns as $key => $pattern) {
        if (preg_match($pattern, $contents, $m)) {
            $db[$key] = $m[1];
        }
    }
    
    // Pattern 2: $db_host = 'value';
    if (empty($db['host'])) {
        $var_patterns = [
            'host' => '/\$db_host\s*=\s*[\'"]([^\'"]+)[\'"]/',
            'name' => '/\$db_name\s*=\s*[\'"]([^\'"]+)[\'"]/',
            'user' => '/\$db_user\s*=\s*[\'"]([^\'"]+)[\'"]/',
            'pass' => '/\$db_pass\s*=\s*[\'"]([^\'"]*)[\'"]/',
        ];
        
        foreach ($var_patterns as $key => $pattern) {
            if (preg_match($pattern, $contents, $m)) {
                $db[$key] = $m[1];
            }
        }
    }
    
    // Pattern 3: define('DB_HOST', 'value');  (WordPress wp-config.php)
    if (empty($db['host'])) {
        $define_patterns = [
            'host' => '/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            'name' => '/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            'user' => '/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            'pass' => '/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/',
        ];
        
        foreach ($define_patterns as $key => $pattern) {
            if (preg_match($pattern, $contents, $m)) {
                $db[$key] = $m[1];
            }
        }
    }
    
    // Validate
    foreach (['host', 'name', 'user'] as $key) {
        if (empty($db[$key])) {
            throw new \RuntimeException(
                "Could not extract '{$key}' from {$label} at: {$file_path}"
            );
        }
    }
    
    if (!isset($db['pass'])) {
        $db['pass'] = '';
    }
    
    // Handle host:port format (e.g. "localhost:3306")
    // PDO needs host and port separate in DSN
    $db['port'] = null;
    if (isset($db['host']) && strpos($db['host'], ':') !== false) {
        $parts = explode(':', $db['host'], 2);
        $db['host'] = $parts[0];
        $db['port'] = (int) $parts[1];
    }
    
    return $db;
}

/**
 * Get GarageMinder database credentials
 */
function get_db_config(): array {
    return parse_db_credentials(GM_CONFIG_PATH, 'GarageMinder config.php');
}

/**
 * Get WordPress database credentials
 */
function get_wp_db_config(): array {
    return parse_db_credentials(WP_CONFIG_PATH, 'WordPress wp-config.php');
}

/**
 * Get WordPress table prefix from wp-config.php
 * Extracts: $table_prefix = '89bPD7p_';
 * Falls back to 'wp_' if not found
 */
function get_wp_table_prefix(): string {
    static $prefix = null;
    if ($prefix !== null) return $prefix;
    
    if (!defined('WP_CONFIG_PATH') || !file_exists(WP_CONFIG_PATH)) {
        $prefix = 'wp_';
        return $prefix;
    }
    
    $contents = file_get_contents(WP_CONFIG_PATH);
    
    // Match: $table_prefix = '89bPD7p_';  or  $table_prefix  = "wp_";
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $contents, $m)) {
        $prefix = $m[1];
    } else {
        $prefix = 'wp_';
    }
    
    return $prefix;
}

// Make prefix available globally as a constant
define('WP_TABLE_PREFIX', get_wp_table_prefix());
