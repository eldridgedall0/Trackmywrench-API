<?php
/**
 * GarageMinder Mobile API - Configuration
 * 
 * IMPORTANT: This file contains sensitive configuration.
 * Protected by .htaccess in this directory.
 * 
 * ENVIRONMENT SUPPORT:
 * - Development: https://yesca.st/gm/  (WP + API + Garage all under /gm/)
 * - Production:  https://trackmywrench.com (WP), https://app.trackmywrench.com (Garage)
 * 
 * The API auto-detects config.php location. To override, create a file:
 *   config/environment.php  
 * with a single line:  
 *   <?php return '/absolute/path/to/config.php';
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
 * Find the GarageMinder config.php automatically.
 * 
 * Search order:
 * 1. Manual override via config/environment.php
 * 2. Common relative paths from the API directory
 * 3. Common absolute paths based on document root
 * 
 * Supports layouts:
 * - /gm/garage/config.php       (dev: yesca.st)
 * - /app/config.php             (prod: app.trackmywrench.com)
 * - /config.php                 (root level)
 * - /garage/config.php          (garage as sibling to api)
 */
function resolve_config_path(): string {
    // 1. Manual override - highest priority
    $overrideFile = __DIR__ . '/environment.php';
    if (file_exists($overrideFile)) {
        $path = require $overrideFile;
        if (is_string($path) && file_exists($path)) {
            return $path;
        }
    }
    
    // 2. Auto-discovery - search common locations
    $apiDir = dirname(__DIR__);  // api/v1/
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname($apiDir, 3);
    
    $candidates = [
        // Relative to API directory (api/v1/ -> up to find garage/)
        $apiDir . '/../garage/config.php',           // /gm/api/../garage/config.php → /gm/garage/
        $apiDir . '/../../garage/config.php',         // up two levels
        dirname($apiDir) . '/garage/config.php',      // api/../garage/
        
        // Relative to document root
        $docRoot . '/garage/config.php',              // /public_html/garage/config.php
        $docRoot . '/gm/garage/config.php',           // /public_html/gm/garage/config.php
        $docRoot . '/app/config.php',                 // /public_html/app/config.php
        $docRoot . '/config.php',                     // /public_html/config.php
        
        // Production: app subdomain with separate doc root
        dirname($docRoot) . '/app/config.php',        // sibling to public_html
        dirname($docRoot) . '/garage/config.php',     // sibling to public_html
        
        // Common cPanel/hosting structures
        dirname($docRoot) . '/public_html/garage/config.php',
        dirname($docRoot) . '/public_html/app/config.php',
        
        // Legacy paths
        $apiDir . '/../../../config.php',             // 3 levels up
        $apiDir . '/../../config.php',                // 2 levels up
    ];
    
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved && is_readable($resolved)) {
            // Verify it actually contains DB credentials
            $contents = file_get_contents($resolved);
            if (preg_match('/\$db_host|\$db_name|DB_HOST|DB_NAME/i', $contents)) {
                return $resolved;
            }
        }
    }
    
    // Nothing found - provide helpful error
    throw new \RuntimeException(
        "GarageMinder config.php not found. Create config/environment.php with:\n" .
        "<?php return '/absolute/path/to/your/garage/config.php';\n\n" .
        "Searched:\n" . implode("\n", array_map(fn($c) => "  - " . (realpath($c) ?: $c), $candidates))
    );
}

define('GM_CONFIG_PATH', resolve_config_path());

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
define('RATE_LIMIT_USER_REQUESTS', 100);         // Per user per window
define('RATE_LIMIT_USER_WINDOW', 60);            // Window in seconds
define('RATE_LIMIT_IP_REQUESTS', 200);           // Per IP per window
define('RATE_LIMIT_IP_WINDOW', 60);              // Window in seconds
define('RATE_LIMIT_LOGIN_REQUESTS', 10);         // Login attempts per IP
define('RATE_LIMIT_LOGIN_WINDOW', 300);          // 5 minutes

// ============================================================================
// API Settings
// ============================================================================
define('API_VERSION', '1.0.0');

// Auto-detect base path from SCRIPT_NAME
// Works under /api/v1/, /gm/api/v1/, or any subdirectory
$_scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/api/v1/index.php');
define('API_PREFIX', rtrim($_scriptDir, '/'));

define('API_DEBUG', true);                        // TODO: Set false for production!
define('API_LOG_REQUESTS', true);                 // Log all requests to DB
define('API_LOG_BODY', false);                    // Log request bodies
define('API_MAX_BODY_SIZE', 1048576);             // 1MB max request body

// ============================================================================
// CORS Configuration
// Automatically includes current domain + production domains
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
define('CORS_MAX_AGE', 86400);                    // 24 hours preflight cache

// ============================================================================
// Security
// ============================================================================
define('ADMIN_ROLE', 'administrator');             // WordPress role for admin API access
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('BRUTE_FORCE_LOCKOUT_ATTEMPTS', 10);
define('BRUTE_FORCE_LOCKOUT_DURATION', 900);      // 15 minutes lockout

// ============================================================================
// Reminders
// ============================================================================
define('REMINDERS_DUE_WINDOW_DAYS', 30);
define('REMINDERS_OVERDUE_INCLUDE', true);

// ============================================================================
// Sync
// ============================================================================
define('SYNC_MAX_VEHICLES_PER_PUSH', 50);
define('SYNC_MAX_ODOMETER_JUMP', 10000);           // Max single odometer increase (miles)

// ============================================================================
// Helper: Load JWT Secret (auto-generates on first run)
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
// Helper: Get DB credentials from GarageMinder config.php
// Supports both $db_host style and define('DB_HOST') style
// ============================================================================
function get_db_config(): array {
    $config_path = GM_CONFIG_PATH;
    
    if (!file_exists($config_path)) {
        throw new \RuntimeException('GarageMinder config.php not found at: ' . $config_path);
    }
    
    $config_contents = file_get_contents($config_path);
    $db_config = [];
    
    // Pattern 1: GarageMinder style - $db_host = 'value';
    $gm_patterns = [
        'host' => '/\$db_host\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'name' => '/\$db_name\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'user' => '/\$db_user\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'pass' => '/\$db_pass\s*=\s*[\'"]([^\'"]*)[\'"]/',
    ];
    
    foreach ($gm_patterns as $key => $pattern) {
        if (preg_match($pattern, $config_contents, $matches)) {
            $db_config[$key] = $matches[1];
        }
    }
    
    // Pattern 2: WordPress style - define('DB_HOST', 'value');
    if (empty($db_config['host'])) {
        $wp_patterns = [
            'host' => '/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            'name' => '/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            'user' => '/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            'pass' => '/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/',
        ];
        
        foreach ($wp_patterns as $key => $pattern) {
            if (preg_match($pattern, $config_contents, $matches)) {
                $db_config[$key] = $matches[1];
            }
        }
    }
    
    // Validate required values
    $required = ['host', 'name', 'user'];
    foreach ($required as $key) {
        if (empty($db_config[$key])) {
            throw new \RuntimeException(
                "Could not extract db_{$key} from config.php at: {$config_path}\n" .
                "Expected either \$db_{$key} = 'value'; or define('DB_" . strtoupper($key) . "', 'value');"
            );
        }
    }
    
    if (!isset($db_config['pass'])) {
        $db_config['pass'] = '';
    }
    
    return $db_config;
}