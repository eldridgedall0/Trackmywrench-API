<?php
/**
 * GarageMinder Mobile API - Configuration
 * 
 * IMPORTANT: This file contains sensitive configuration.
 * Protected by .htaccess in this directory.
 */

// Prevent direct access
if (!defined('GM_API')) {
    http_response_code(403);
    exit('Direct access forbidden');
}

// ============================================================================
// Path to existing GarageMinder config.php
// Adjust this path based on your server layout
// __DIR__           = /gm/api/v1
// ../../            = /gm
// ../../garage      = /gm/garage

// ============================================================================
define('GM_CONFIG_PATH', __DIR__ . '/../../garage/config.php');

// ============================================================================
// JWT Configuration
// ============================================================================
define('JWT_SECRET_FILE', __DIR__ . '/jwt_secret.key');
define('JWT_ACCESS_TOKEN_EXPIRY', 1800);       // 30 minutes in seconds
define('JWT_REFRESH_TOKEN_EXPIRY', 2592000);    // 30 days in seconds
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
define('API_PREFIX', '/api/v1');
define('API_DEBUG', false);                       // Set true for development
define('API_LOG_REQUESTS', true);                 // Log all requests to DB
define('API_LOG_BODY', false);                    // Log request bodies (careful with sensitive data)
define('API_MAX_BODY_SIZE', 1048576);             // 1MB max request body

// ============================================================================
// CORS Configuration
// ============================================================================
define('CORS_ALLOWED_ORIGINS', [
    'https://trackmywrench.com',
    'https://www.trackmywrench.com',
]);
define('CORS_ALLOW_CREDENTIALS', true);
define('CORS_MAX_AGE', 86400);                    // 24 hours preflight cache

// ============================================================================
// Security
// ============================================================================
define('ADMIN_ROLE', 'administrator');             // WordPress role for admin API access
define('PASSWORD_HASH_ALGO', PASSWORD_BCRYPT);
define('BRUTE_FORCE_LOCKOUT_ATTEMPTS', 10);       // Lock after N failed logins
define('BRUTE_FORCE_LOCKOUT_DURATION', 900);      // 15 minutes lockout

// ============================================================================
// Reminders
// ============================================================================
define('REMINDERS_DUE_WINDOW_DAYS', 30);          // Default "due soon" window
define('REMINDERS_OVERDUE_INCLUDE', true);         // Include overdue in due endpoint

// ============================================================================
// Sync
// ============================================================================
define('SYNC_MAX_VEHICLES_PER_PUSH', 50);          // Max vehicles per sync push
define('SYNC_MAX_ODOMETER_JUMP', 10000);           // Max single odometer increase (miles)

// ============================================================================
// Helper: Load JWT Secret
// ============================================================================
function get_jwt_secret(): string {
    $secret_file = JWT_SECRET_FILE;
    
    if (!file_exists($secret_file)) {
        // Auto-generate secret on first run
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
// Helper: Get WordPress DB credentials from existing config.php
// ============================================================================
function get_db_config(): array {
    $config_path = GM_CONFIG_PATH;
    
    if (!file_exists($config_path)) {
        throw new \RuntimeException('GarageMinder config.php not found at: ' . $config_path);
    }
    
    // Parse config.php to extract DB credentials
    // The existing config.php defines variables like $db_host, $db_name, etc.
    // We capture them without polluting our namespace
    $config_contents = file_get_contents($config_path);
    
    // Extract database credentials using regex (safe approach)
    $db_config = [];
    
    // Match patterns like: $db_host = 'value'; or $variable = "value";
    $patterns = [
        'host' => '/\$db_host\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'name' => '/\$db_name\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'user' => '/\$db_user\s*=\s*[\'"]([^\'"]+)[\'"]/',
        'pass' => '/\$db_pass\s*=\s*[\'"]([^\'"]*)[\'"]/',
    ];
    
    foreach ($patterns as $key => $pattern) {
        if (preg_match($pattern, $config_contents, $matches)) {
            $db_config[$key] = $matches[1];
        }
    }
    
    // Validate we got all required values
    $required = ['host', 'name', 'user'];
    foreach ($required as $key) {
        if (empty($db_config[$key])) {
            throw new \RuntimeException("Could not extract db_{$key} from config.php");
        }
    }
    
    // Default password to empty string if not found
    if (!isset($db_config['pass'])) {
        $db_config['pass'] = '';
    }
    
    return $db_config;
}
