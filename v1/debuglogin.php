<?php
/**
 * GarageMinder API - Debug Login Page
 * Standalone login test that bypasses the framework entirely.
 * Tests each component of the login flow individually with full error display.
 * 
 * Upload to: gm/api/v1/debuglogin.php
 * Visit: https://yesca.st/gm/api/v1/debuglogin.php
 * DELETE after debugging!
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Catch fatals
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<div style="background:#ff4444;color:white;padding:20px;margin:20px 0;border-radius:8px;">';
        echo '<h2>💀 FATAL ERROR</h2>';
        echo '<pre>' . htmlspecialchars(print_r($error, true)) . '</pre>';
        echo '</div>';
    }
});

// Load config for DB access
define('GM_API', true);
define('GM_API_START', microtime(true));
require_once __DIR__ . '/config/api_config.php';

$results = [];
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// Process login if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $username && $password) {
    
    // Step 1: WordPress DB Connection
    try {
        $wpConfig = get_wp_db_config();
        $wpDsn = "mysql:host={$wpConfig['host']};dbname={$wpConfig['name']};charset=utf8mb4";
        if (!empty($wpConfig['port'])) {
            $wpDsn = "mysql:host={$wpConfig['host']};port={$wpConfig['port']};dbname={$wpConfig['name']};charset=utf8mb4";
        }
        $wpPdo = new PDO($wpDsn, $wpConfig['user'], $wpConfig['pass']);
        $wpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $results[] = ['step' => '1. WordPress DB', 'status' => 'ok', 'detail' => "Connected to {$wpConfig['name']}"];
    } catch (Throwable $e) {
        $results[] = ['step' => '1. WordPress DB', 'status' => 'error', 'detail' => $e->getMessage()];
        goto render;
    }
    
    // Step 2: Find user
    try {
        $prefix = WP_TABLE_PREFIX;
        $usersTable = $prefix . 'users';
        
        $stmt = $wpPdo->prepare(
            "SELECT ID, user_login, user_email, display_name, user_pass 
             FROM `{$usersTable}` WHERE user_login = ? OR user_email = ? LIMIT 1"
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $results[] = ['step' => '2. Find User', 'status' => 'ok', 'detail' => 
                "Found: ID={$user['ID']}, login={$user['user_login']}, email={$user['user_email']}, " .
                "hash_prefix=" . substr($user['user_pass'], 0, 12) . "..."
            ];
        } else {
            $results[] = ['step' => '2. Find User', 'status' => 'error', 'detail' => 
                "No user found with login or email: {$username}. " .
                "Table: {$usersTable}"
            ];
            
            // Show available users for debugging
            $allUsers = $wpPdo->query("SELECT ID, user_login, user_email FROM `{$usersTable}` LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $results[] = ['step' => '2b. Available Users', 'status' => 'info', 'detail' => $allUsers];
            goto render;
        }
    } catch (Throwable $e) {
        $results[] = ['step' => '2. Find User', 'status' => 'error', 'detail' => $e->getMessage()];
        goto render;
    }
    
    // Step 3: Verify password
    try {
        $hash = $user['user_pass'];
        $verified = false;
        $method = 'unknown';
        
        // WordPress phpass ($P$ or $H$ prefix)
        if (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0) {
            $method = 'phpass';
            $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
            $countLog2 = strpos($itoa64, $hash[3]);
            $count = 1 << $countLog2;
            $salt = substr($hash, 4, 8);
            
            $computed = md5($salt . $password, true);
            do {
                $computed = md5($computed . $password, true);
            } while (--$count);
            
            // Encode
            $output = substr($hash, 0, 12);
            $i = 0;
            $hashLen = 16;
            do {
                $value = ord($computed[$i++]);
                $output .= $itoa64[$value & 0x3f];
                if ($i < $hashLen) $value |= ord($computed[$i]) << 8;
                $output .= $itoa64[($value >> 6) & 0x3f];
                if ($i++ >= $hashLen) break;
                if ($i < $hashLen) $value |= ord($computed[$i]) << 16;
                $output .= $itoa64[($value >> 12) & 0x3f];
                if ($i++ >= $hashLen) break;
                $output .= $itoa64[($value >> 18) & 0x3f];
            } while ($i < $hashLen);
            
            $verified = hash_equals($hash, $output);
        }
        // WordPress 6.x+ bcrypt with $wp$ prefix: "$wp$2y$10$..." → strip to "$2y$10$..."
        elseif (strpos($hash, '$wp$') === 0) {
            $method = 'wp_bcrypt (stripped $wp$ prefix)';
            $strippedHash = substr($hash, 3); // "$wp$2y$10$..." → "$2y$10$..."
            $verified = password_verify($password, $strippedHash);
        }
        // Modern bcrypt/argon
        else {
            $method = 'password_verify';
            $verified = password_verify($password, $hash);
        }
        
        if ($verified) {
            $results[] = ['step' => '3. Password', 'status' => 'ok', 'detail' => "Password verified using {$method}"];
        } else {
            $results[] = ['step' => '3. Password', 'status' => 'error', 'detail' => 
                "Password INCORRECT (method: {$method}). Hash type: " . substr($hash, 0, 4)
            ];
            goto render;
        }
    } catch (Throwable $e) {
        $results[] = ['step' => '3. Password', 'status' => 'error', 'detail' => $e->getMessage()];
        goto render;
    }
    
    // Step 4: GarageMinder DB Connection
    try {
        $gmConfig = get_db_config();
        $gmDsn = "mysql:host={$gmConfig['host']};dbname={$gmConfig['name']};charset=utf8mb4";
        if (!empty($gmConfig['port'])) {
            $gmDsn = "mysql:host={$gmConfig['host']};port={$gmConfig['port']};dbname={$gmConfig['name']};charset=utf8mb4";
        }
        $gmPdo = new PDO($gmDsn, $gmConfig['user'], $gmConfig['pass']);
        $gmPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $results[] = ['step' => '4. GarageMinder DB', 'status' => 'ok', 'detail' => "Connected to {$gmConfig['name']}"];
    } catch (Throwable $e) {
        $results[] = ['step' => '4. GarageMinder DB', 'status' => 'error', 'detail' => $e->getMessage()];
        goto render;
    }
    
    // Step 5: Check api_refresh_tokens table schema
    try {
        $cols = $gmPdo->query("DESCRIBE api_refresh_tokens")->fetchAll(PDO::FETCH_COLUMN);
        $results[] = ['step' => '5. Token Table Schema', 'status' => 'ok', 'detail' => 'Columns: ' . implode(', ', $cols)];
    } catch (Throwable $e) {
        $results[] = ['step' => '5. Token Table', 'status' => 'error', 'detail' => $e->getMessage()];
        goto render;
    }
    
    // Step 6: JWT token generation
    try {
        $jwtSecret = defined('JWT_SECRET') ? JWT_SECRET : null;
        if (!$jwtSecret) {
            $results[] = ['step' => '6. JWT Config', 'status' => 'error', 'detail' => 'JWT_SECRET not defined'];
            goto render;
        }
        
        $results[] = ['step' => '6. JWT Config', 'status' => 'ok', 'detail' => 
            'JWT_SECRET length: ' . strlen($jwtSecret) . ', ' .
            'JWT_ACCESS_TOKEN_EXPIRY: ' . (defined('JWT_ACCESS_TOKEN_EXPIRY') ? JWT_ACCESS_TOKEN_EXPIRY : 'NOT SET') . ', ' .
            'JWT_REFRESH_TOKEN_EXPIRY: ' . (defined('JWT_REFRESH_TOKEN_EXPIRY') ? JWT_REFRESH_TOKEN_EXPIRY : 'NOT SET')
        ];
        
        // Try generating a token manually (HMAC-SHA256)
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'sub' => (int)$user['ID'],
            'iat' => time(),
            'exp' => time() + 3600,
            'username' => $user['user_login'],
        ]));
        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", $jwtSecret, true));
        $testToken = "$header.$payload.$signature";
        
        $results[] = ['step' => '6b. Test Token', 'status' => 'ok', 'detail' => 
            'Generated test token: ' . substr($testToken, 0, 50) . '...'
        ];
    } catch (Throwable $e) {
        $results[] = ['step' => '6. JWT', 'status' => 'error', 'detail' => $e->getMessage()];
        goto render;
    }
    
    // Step 7: Try generating refresh token (DB insert)
    try {
        $refreshTokenStr = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $refreshTokenStr);
        
        $stmt = $gmPdo->prepare(
            "INSERT INTO api_refresh_tokens (user_id, token_hash, device_id, device_name, platform, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            (int)$user['ID'],
            $hashedToken,
            'debug-test',
            'Debug Login Page',
            'web',
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? 'debug',
            date('Y-m-d H:i:s', time() + 86400 * 30),
        ]);
        
        $tokenId = $gmPdo->lastInsertId();
        $results[] = ['step' => '7. Refresh Token', 'status' => 'ok', 'detail' => 
            "Refresh token saved (ID: {$tokenId}). Token: " . substr($refreshTokenStr, 0, 16) . '...'
        ];
        
        // Clean up test token
        $gmPdo->exec("DELETE FROM api_refresh_tokens WHERE id = {$tokenId}");
        $results[] = ['step' => '7b. Cleanup', 'status' => 'ok', 'detail' => 'Test token removed'];
    } catch (Throwable $e) {
        $results[] = ['step' => '7. Refresh Token', 'status' => 'error', 'detail' => $e->getMessage()];
        
        // Show actual table columns vs what we tried to insert
        try {
            $describe = $gmPdo->query("DESCRIBE api_refresh_tokens")->fetchAll(PDO::FETCH_ASSOC);
            $results[] = ['step' => '7b. Table Structure', 'status' => 'info', 'detail' => $describe];
        } catch (Throwable $e2) {}
        goto render;
    }
    
    // Step 8: Subscription level check
    try {
        $swpmTable = $prefix . 'swpm_members_tbl';
        
        // Check if SWPM table exists
        $tableCheck = $wpPdo->query("SHOW TABLES LIKE '{$swpmTable}'")->fetch();
        
        if ($tableCheck) {
            $stmt = $wpPdo->prepare("SELECT membership_level, account_state FROM `{$swpmTable}` WHERE member_id = ?");
            $stmt->execute([(int)$user['ID']]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($member) {
                $results[] = ['step' => '8. Subscription', 'status' => 'ok', 'detail' => 
                    "SWPM member: level={$member['membership_level']}, state={$member['account_state']}"
                ];
            } else {
                $results[] = ['step' => '8. Subscription', 'status' => 'ok', 'detail' => 
                    "User not in SWPM members table → defaults to 'free'"
                ];
            }
        } else {
            $results[] = ['step' => '8. Subscription', 'status' => 'ok', 'detail' => 
                "SWPM table ({$swpmTable}) not found → defaults to 'free'"
            ];
        }
    } catch (Throwable $e) {
        $results[] = ['step' => '8. Subscription', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    // Step 9: Rate limit table check
    try {
        $stmt = $gmPdo->query("DESCRIBE api_rate_limits");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'Field');
        $results[] = ['step' => '9. Rate Limit Table', 'status' => 'ok', 'detail' => 
            'Columns: ' . implode(', ', $colNames)
        ];
        
        // Check for required columns
        $required = ['identifier', 'identifier_type', 'endpoint', 'request_count', 'window_start', 'window_seconds'];
        $missing = array_diff($required, $colNames);
        if ($missing) {
            $results[] = ['step' => '9b. Missing Columns', 'status' => 'error', 'detail' => 
                'Missing: ' . implode(', ', $missing)
            ];
        }
    } catch (Throwable $e) {
        $results[] = ['step' => '9. Rate Limit Table', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    // Step 10: Request log table check
    try {
        $stmt = $gmPdo->query("DESCRIBE api_request_log");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'Field');
        $results[] = ['step' => '10. Request Log Table', 'status' => 'ok', 'detail' => 
            'Columns: ' . implode(', ', $colNames)
        ];
    } catch (Throwable $e) {
        $results[] = ['step' => '10. Request Log Table', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    // Step 11: Full framework login test
    try {
        // Register autoloader
        spl_autoload_register(function (string $class) {
            $prefix = 'GarageMinder\\API\\';
            $baseDir = __DIR__ . '/';
            if (strpos($class, $prefix) !== 0) return;
            $relativeClass = substr($class, strlen($prefix));
            $map = ['Core\\' => 'core/', 'Middleware\\' => 'middleware/', 'Models\\' => 'models/', 'Endpoints\\' => 'endpoints/'];
            foreach ($map as $nsPrefix => $dir) {
                if (strpos($relativeClass, $nsPrefix) === 0) {
                    $classPath = substr($relativeClass, strlen($nsPrefix));
                    $file = $baseDir . $dir . str_replace('\\', '/', $classPath) . '.php';
                    if (file_exists($file)) { require_once $file; return; }
                    $parts = explode('\\', $classPath);
                    if (count($parts) > 1) { $parts[0] = strtolower($parts[0]); $file = $baseDir . $dir . implode('/', $parts) . '.php'; if (file_exists($file)) { require_once $file; return; } }
                }
            }
        });
        
        $userModel = new \GarageMinder\API\Models\User();
        $foundUser = $userModel->findByLogin($username);
        
        if ($foundUser && $userModel->verifyPassword($password, $foundUser['password_hash'])) {
            $jwt = new \GarageMinder\API\Core\JWTHandler();
            $accessToken = $jwt->createAccessToken((int)$foundUser['id'], ['username' => $foundUser['username']]);
            
            $results[] = ['step' => '11. Framework Login', 'status' => 'ok', 'detail' => 
                "✅ LOGIN SUCCESS via framework! Access token: " . substr($accessToken, 0, 50) . '...'
            ];
            
            $subLevel = $userModel->getSubscriptionLevel((int)$foundUser['id']);
            $results[] = ['step' => '11b. Subscription', 'status' => 'ok', 'detail' => "Level: {$subLevel}"];
        } else {
            $results[] = ['step' => '11. Framework Login', 'status' => 'error', 'detail' => 
                'Framework login failed: ' . ($foundUser ? 'password mismatch' : 'user not found')
            ];
        }
    } catch (Throwable $e) {
        $results[] = ['step' => '11. Framework Login', 'status' => 'error', 'detail' => 
            $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
        ];
    }
    
    // FINAL: Test actual API endpoint via internal HTTP
    $results[] = ['step' => '12. Summary', 'status' => 'ok', 'detail' => 
        'All individual components tested. If API still 500s, the error is in Router dispatch or middleware chain execution order.'
    ];
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GarageMinder API - Debug Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: #eee; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #00d4aa; margin-bottom: 8px; }
        .subtitle { color: #888; margin-bottom: 30px; font-size: 14px; }
        .card { background: #16213e; border-radius: 12px; padding: 24px; margin-bottom: 20px; border: 1px solid #2a2a4a; }
        label { display: block; color: #aaa; margin-bottom: 6px; font-size: 14px; }
        input[type="text"], input[type="password"] { 
            width: 100%; padding: 12px; background: #0f3460; border: 1px solid #2a2a4a; 
            border-radius: 8px; color: #fff; font-size: 16px; margin-bottom: 16px; 
        }
        input:focus { outline: none; border-color: #00d4aa; }
        button { 
            background: #00d4aa; color: #1a1a2e; padding: 14px 28px; border: none; 
            border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%;
        }
        button:hover { background: #00b894; }
        .result { padding: 12px 16px; border-radius: 8px; margin-bottom: 8px; font-size: 14px; }
        .result-ok { background: #0a3d2e; border-left: 4px solid #00d4aa; }
        .result-error { background: #3d0a0a; border-left: 4px solid #ff4444; }
        .result-info { background: #1a2a4a; border-left: 4px solid #4488ff; }
        .result strong { color: #fff; }
        .result .detail { color: #bbb; margin-top: 4px; word-break: break-all; }
        .result .detail pre { background: #0a0a1a; padding: 8px; border-radius: 4px; overflow-x: auto; margin-top: 4px; font-size: 12px; }
        .warn { background: #3d2a0a; color: #ffaa44; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .config-info { font-size: 12px; color: #666; margin-top: 20px; }
        .config-info code { background: #0a0a1a; padding: 2px 6px; border-radius: 4px; color: #888; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 GarageMinder API - Debug Login</h1>
        <p class="subtitle">Standalone login test — bypasses framework, shows every step</p>
        
        <div class="warn">⚠️ DELETE this file after debugging! It exposes sensitive information.</div>
        
        <div class="card">
            <form method="POST">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" placeholder="your@email.com" autocomplete="off">
                
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" autocomplete="off">
                
                <button type="submit">🔐 Test Login</button>
            </form>
        </div>
        
        <?php if (!empty($results)): ?>
        <div class="card">
            <h2 style="margin-bottom:16px; color:#00d4aa;">Results</h2>
            <?php foreach ($results as $r): ?>
                <?php
                    $cls = 'result-info';
                    $icon = 'ℹ️';
                    if ($r['status'] === 'ok') { $cls = 'result-ok'; $icon = '✅'; }
                    if ($r['status'] === 'error') { $cls = 'result-error'; $icon = '❌'; }
                ?>
                <div class="result <?= $cls ?>">
                    <strong><?= $icon ?> <?= htmlspecialchars($r['step']) ?></strong>
                    <div class="detail">
                        <?php if (is_array($r['detail'])): ?>
                            <pre><?= htmlspecialchars(json_encode($r['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                        <?php else: ?>
                            <?= htmlspecialchars($r['detail']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="config-info">
            <p>Config: <code><?= defined('GM_CONFIG_PATH') ? GM_CONFIG_PATH : 'N/A' ?></code></p>
            <p>WP Config: <code><?= defined('WP_CONFIG_PATH') ? WP_CONFIG_PATH : 'N/A' ?></code></p>
            <p>Table Prefix: <code><?= defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'N/A' ?></code></p>
            <p>API Debug: <code><?= defined('API_DEBUG') && API_DEBUG ? 'true' : 'false' ?></code></p>
        </div>
    </div>
</body>
</html>
