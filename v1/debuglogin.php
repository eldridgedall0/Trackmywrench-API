<?php
/**
 * GarageMinder API - Debug Login Page v3
 * Comprehensive diagnostic login test with full error display.
 * Supports ALL WordPress password hash formats including 6.8+ SHA-384 pre-hash.
 * 
 * Upload to: gm/api/v1/debuglogin.php
 * DELETE after debugging!
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo '<div style="background:#ff4444;color:white;padding:20px;margin:20px 0;border-radius:8px;font-family:monospace;">';
        echo '<h2>💀 FATAL PHP ERROR</h2>';
        echo '<p><b>Message:</b> ' . htmlspecialchars($error['message']) . '</p>';
        echo '<p><b>File:</b> ' . htmlspecialchars($error['file']) . ':' . $error['line'] . '</p>';
        echo '</div>';
    }
});

// Load config
define('GM_API', true);
define('GM_API_START', microtime(true));
require_once __DIR__ . '/config/api_config.php';

$results = [];
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

function addResult(string $step, string $status, $detail, ?string $fix = null) {
    global $results;
    $results[] = ['step' => $step, 'status' => $status, 'detail' => $detail, 'fix' => $fix];
}

// ============================================================
// Process login
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $username && $password) {
    
    // --------------------------------------------------------
    // Step 1: WordPress DB Connection
    // --------------------------------------------------------
    $wpPdo = null;
    try {
        $wpConfig = get_wp_db_config();
        $wpDsn = "mysql:host={$wpConfig['host']};dbname={$wpConfig['name']};charset=utf8mb4";
        if (!empty($wpConfig['port'])) {
            $wpDsn = "mysql:host={$wpConfig['host']};port={$wpConfig['port']};dbname={$wpConfig['name']};charset=utf8mb4";
        }
        $wpPdo = new PDO($wpDsn, $wpConfig['user'], $wpConfig['pass']);
        $wpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        addResult('1. WordPress DB', 'ok', "Connected to {$wpConfig['name']} @ {$wpConfig['host']}");
    } catch (Throwable $e) {
        addResult('1. WordPress DB', 'error', $e->getMessage(),
            "Check wp-config.php credentials. DSN: {$wpDsn}");
        goto render;
    }
    
    // --------------------------------------------------------
    // Step 2: Find user in database
    // --------------------------------------------------------
    $user = null;
    $prefix = defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'wp_';
    try {
        $usersTable = $prefix . 'users';
        $stmt = $wpPdo->prepare(
            "SELECT ID, user_login, user_email, display_name, user_pass, user_status
             FROM `{$usersTable}` WHERE user_login = ? OR user_email = ? LIMIT 1"
        );
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            addResult('2. Find User', 'ok', 
                "ID={$user['ID']}, login={$user['user_login']}, email={$user['user_email']}, status={$user['user_status']}");
        } else {
            // Show existing users to help debug
            $allUsers = $wpPdo->query("SELECT ID, user_login, user_email FROM `{$usersTable}` LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            addResult('2. Find User', 'error', "No user found for '{$username}' in {$usersTable}",
                "Check username/email. Available users: " . json_encode($allUsers));
            goto render;
        }
    } catch (Throwable $e) {
        addResult('2. Find User', 'error', $e->getMessage(),
            "Table prefix is '{$prefix}'. Check if {$prefix}users table exists.");
        goto render;
    }
    
    // --------------------------------------------------------
    // Step 3: Hash Analysis (detailed)
    // --------------------------------------------------------
    $hash = $user['user_pass'];
    $hashLen = strlen($hash);
    $hashPrefix = substr($hash, 0, 7);
    $hashType = 'unknown';
    
    if ($hashLen <= 32) {
        $hashType = 'MD5 (legacy)';
    } elseif (strpos($hash, '$wp$') === 0) {
        $hashType = 'WordPress 6.8+ bcrypt (SHA-384 pre-hash)';
    } elseif (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0) {
        $hashType = 'WordPress phpass (pre-6.8)';
    } elseif (strpos($hash, '$2y$') === 0 || strpos($hash, '$2b$') === 0) {
        $hashType = 'Standard bcrypt (plugin or manual)';
    } elseif (strpos($hash, '$argon2') === 0) {
        $hashType = 'Argon2 (advanced)';
    }
    
    addResult('3. Hash Analysis', 'info', [
        'hash_type'   => $hashType,
        'hash_length' => $hashLen,
        'hash_prefix' => $hashPrefix . '...',
        'full_hash'   => substr($hash, 0, 30) . '... (truncated for security)',
    ]);
    
    // --------------------------------------------------------
    // Step 4: Password Verification (all methods)
    // --------------------------------------------------------
    $verified = false;
    $verifyMethod = 'none';
    $verifyDetails = [];
    
    // Method A: MD5
    if ($hashLen <= 32) {
        $verifyMethod = 'MD5';
        $verified = hash_equals($hash, md5($password));
        $verifyDetails['md5_computed'] = md5($password);
        $verifyDetails['md5_stored'] = $hash;
        $verifyDetails['md5_match'] = $verified ? 'YES' : 'NO';
    }
    
    // Method B: WordPress 6.8+ ($wp$ prefix with SHA-384 pre-hash)
    if (!$verified && strpos($hash, '$wp$') === 0) {
        $verifyMethod = 'WP 6.8 bcrypt (SHA-384 pre-hash)';
        
        // WordPress 6.8 method: HMAC-SHA384 the password, then bcrypt-verify
        $preHashed = base64_encode(hash_hmac('sha384', $password, 'wp-sha384', true));
        $bcryptPart = substr($hash, 3); // Strip "$wp" → "$2y$10$..."
        
        $verifyDetails['pre_hashed_password'] = substr($preHashed, 0, 20) . '...';
        $verifyDetails['bcrypt_hash_part'] = substr($bcryptPart, 0, 20) . '...';
        $verifyDetails['bcrypt_hash_valid'] = (strpos($bcryptPart, '$2y$') === 0 || strpos($bcryptPart, '$2b$') === 0) ? 'YES' : 'NO - unexpected format: ' . substr($bcryptPart, 0, 10);
        
        $verified = password_verify($preHashed, $bcryptPart);
        $verifyDetails['password_verify_result'] = $verified ? 'MATCH' : 'NO MATCH';
        
        // If that didn't work, also try the raw password (in case $wp$ is from a plugin)
        if (!$verified) {
            $rawVerify = password_verify($password, $bcryptPart);
            $verifyDetails['raw_password_verify'] = $rawVerify ? 'MATCH (raw)' : 'NO MATCH (raw)';
            if ($rawVerify) {
                $verified = true;
                $verifyMethod .= ' (raw fallback)';
            }
        }
    }
    
    // Method C: phpass ($P$ or $H$)
    if (!$verified && (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0)) {
        $verifyMethod = 'phpass';
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $countLog2 = strpos($itoa64, $hash[3]);
        $count = 1 << $countLog2;
        $salt = substr($hash, 4, 8);
        
        $computed = md5($salt . $password, true);
        do { $computed = md5($computed . $password, true); } while (--$count);
        
        $output = substr($hash, 0, 12);
        $i = 0;
        do {
            $value = ord($computed[$i++]);
            $output .= $itoa64[$value & 0x3f];
            if ($i < 16) $value |= ord($computed[$i]) << 8;
            $output .= $itoa64[($value >> 6) & 0x3f];
            if ($i++ >= 16) break;
            if ($i < 16) $value |= ord($computed[$i]) << 16;
            $output .= $itoa64[($value >> 12) & 0x3f];
            if ($i++ >= 16) break;
            $output .= $itoa64[($value >> 18) & 0x3f];
        } while ($i < 16);
        
        $verified = hash_equals($hash, $output);
        $verifyDetails['phpass_salt'] = $salt;
        $verifyDetails['phpass_iterations'] = 1 << $countLog2;
    }
    
    // Method D: Standard bcrypt/argon2 (no prefix manipulation)
    if (!$verified && strpos($hash, '$P$') !== 0 && strpos($hash, '$H$') !== 0 && strpos($hash, '$wp$') !== 0) {
        $verifyMethod = 'password_verify (standard)';
        $verified = password_verify($password, $hash);
        $verifyDetails['standard_verify'] = $verified ? 'MATCH' : 'NO MATCH';
    }
    
    if ($verified) {
        addResult('4. Password Verification', 'ok', 
            "✅ Password CORRECT (method: {$verifyMethod})", null);
    } else {
        $diagInfo = "Method tried: {$verifyMethod}\n\nDiagnostic details:\n" . json_encode($verifyDetails, JSON_PRETTY_PRINT);
        
        $fixSuggestions = "Possible causes:\n" .
            "1. Wrong password — try resetting password in WordPress admin\n" .
            "2. WordPress version mismatch — check WP version (6.8+ uses SHA-384 pre-hash)\n" .
            "3. Password plugin override — check if a plugin changed wp_hash_password()\n" .
            "4. Hash corruption — compare hash in DB vs what WordPress shows\n\n" .
            "Quick fix: In WordPress admin, go to Users → Edit → set new password.\n" .
            "Or run in MySQL: UPDATE {$prefix}users SET user_pass=MD5('newpassword') WHERE ID={$user['ID']};\n" .
            "(MD5 is temporary — WordPress will re-hash on next login)";
        
        addResult('4. Password Verification', 'error', $diagInfo, $fixSuggestions);
        // Continue anyway to test remaining infrastructure
    }
    
    // --------------------------------------------------------
    // Step 5: WordPress Version Check
    // --------------------------------------------------------
    try {
        $optionsTable = $prefix . 'options';
        $stmt = $wpPdo->prepare("SELECT option_value FROM `{$optionsTable}` WHERE option_name = 'db_version' LIMIT 1");
        $stmt->execute();
        $dbVersion = $stmt->fetchColumn();
        
        $stmt2 = $wpPdo->prepare("SELECT option_value FROM `{$optionsTable}` WHERE option_name = 'initial_db_version' LIMIT 1");
        $stmt2->execute();
        $initialVersion = $stmt2->fetchColumn();
        
        // WP 6.8 has db_version 58975 or higher
        $is68Plus = ($dbVersion >= 58975);
        
        addResult('5. WordPress Version', 'info', [
            'db_version' => $dbVersion,
            'initial_db_version' => $initialVersion,
            'is_wp_6.8+' => $is68Plus ? 'YES (SHA-384 pre-hash hashing active)' : 'NO (phpass or standard bcrypt)',
            'expected_hash_format' => $is68Plus ? '$wp$2y$...' : ($dbVersion > 30000 ? '$P$...' : 'MD5'),
        ]);
    } catch (Throwable $e) {
        addResult('5. WordPress Version', 'error', $e->getMessage(), 
            "Could not read {$prefix}options table.");
    }
    
    // --------------------------------------------------------
    // Step 6: GarageMinder DB Connection
    // --------------------------------------------------------
    $gmPdo = null;
    try {
        $gmConfig = get_db_config();
        $gmDsn = "mysql:host={$gmConfig['host']};dbname={$gmConfig['name']};charset=utf8mb4";
        if (!empty($gmConfig['port'])) {
            $gmDsn = "mysql:host={$gmConfig['host']};port={$gmConfig['port']};dbname={$gmConfig['name']};charset=utf8mb4";
        }
        $gmPdo = new PDO($gmDsn, $gmConfig['user'], $gmConfig['pass']);
        $gmPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        addResult('6. GarageMinder DB', 'ok', "Connected to {$gmConfig['name']}");
    } catch (Throwable $e) {
        addResult('6. GarageMinder DB', 'error', $e->getMessage(),
            "Check config.php credentials.");
        goto render;
    }
    
    // --------------------------------------------------------
    // Step 7: API Tables Check
    // --------------------------------------------------------
    try {
        $apiTables = ['api_refresh_tokens', 'api_rate_limits', 'api_request_log'];
        $missingTables = [];
        $tableInfo = [];
        
        foreach ($apiTables as $table) {
            $check = $gmPdo->query("SHOW TABLES LIKE '{$table}'")->fetch();
            if ($check) {
                $cols = $gmPdo->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
                $tableInfo[$table] = $cols;
            } else {
                $missingTables[] = $table;
            }
        }
        
        if (empty($missingTables)) {
            addResult('7. API Tables', 'ok', $tableInfo);
        } else {
            addResult('7. API Tables', 'error', "Missing tables: " . implode(', ', $missingTables),
                "Run the schema SQL from the API repository to create missing tables.");
        }
    } catch (Throwable $e) {
        addResult('7. API Tables', 'error', $e->getMessage());
    }
    
    // --------------------------------------------------------
    // Step 8: JWT Configuration
    // --------------------------------------------------------
    try {
        $jwtOk = defined('JWT_SECRET') && strlen(JWT_SECRET) >= 32;
        addResult('8. JWT Config', $jwtOk ? 'ok' : 'error', [
            'JWT_SECRET_length' => defined('JWT_SECRET') ? strlen(JWT_SECRET) : 'NOT DEFINED',
            'JWT_SECRET_preview' => defined('JWT_SECRET') ? substr(JWT_SECRET, 0, 8) . '...' : 'N/A',
            'JWT_ACCESS_TOKEN_EXPIRY' => defined('JWT_ACCESS_TOKEN_EXPIRY') ? JWT_ACCESS_TOKEN_EXPIRY . 's' : 'NOT DEFINED',
            'JWT_REFRESH_TOKEN_EXPIRY' => defined('JWT_REFRESH_TOKEN_EXPIRY') ? JWT_REFRESH_TOKEN_EXPIRY . 's' : 'NOT DEFINED',
        ], !$jwtOk ? "JWT_SECRET must be defined and at least 32 chars in api_config.php" : null);
    } catch (Throwable $e) {
        addResult('8. JWT Config', 'error', $e->getMessage());
    }
    
    // --------------------------------------------------------
    // Step 9: Framework User Model Test
    // --------------------------------------------------------
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
        
        if (!$foundUser) {
            addResult('9. Framework User Model', 'error', 'User not found via framework',
                "The User model query may differ from direct SQL. Check User.php findByLogin().");
        } else {
            $frameworkVerify = $userModel->verifyPassword($password, $foundUser['password_hash']);
            addResult('9. Framework User Model', $frameworkVerify ? 'ok' : 'error', [
                'user_found' => true,
                'user_id' => $foundUser['id'],
                'password_hash_from_model' => substr($foundUser['password_hash'], 0, 20) . '...',
                'framework_verify' => $frameworkVerify ? 'PASS ✅' : 'FAIL ❌',
            ], !$frameworkVerify ? "User.php verifyPassword() doesn't handle this hash format. Update models/User.php." : null);
        }
    } catch (Throwable $e) {
        addResult('9. Framework User Model', 'error', 
            $e->getMessage() . "\n\nFile: " . basename($e->getFile()) . ':' . $e->getLine() . "\n\nTrace:\n" . $e->getTraceAsString(),
            "Check if all framework classes can load. Run apidebug.php first.");
    }
    
    // --------------------------------------------------------
    // Step 10: Full Login Simulation (JWT + Refresh Token)
    // --------------------------------------------------------
    if ($verified || (!empty($foundUser) && !empty($frameworkVerify) && $frameworkVerify)) {
        try {
            $jwt = new \GarageMinder\API\Core\JWTHandler();
            $userId = (int)($foundUser['id'] ?? $user['ID']);
            
            $accessToken = $jwt->createAccessToken($userId, [
                'username' => $foundUser['username'] ?? $user['user_login'],
            ]);
            
            $refreshToken = $jwt->createRefreshToken(
                $userId, 'debug-device', 'Debug Login Page', 'web',
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? 'debug'
            );
            
            addResult('10. Token Generation', 'ok', [
                'access_token' => substr($accessToken, 0, 60) . '...',
                'refresh_token' => substr($refreshToken, 0, 20) . '...',
                'status' => '🎉 FULL LOGIN SUCCESSFUL!',
            ]);
            
            // Get subscription level
            $subLevel = $userModel->getSubscriptionLevel($userId);
            addResult('10b. Subscription', 'ok', "Level: {$subLevel}");
            
        } catch (Throwable $e) {
            addResult('10. Token Generation', 'error',
                $e->getMessage() . "\n\nFile: " . basename($e->getFile()) . ':' . $e->getLine(),
                "Check JWTHandler.php and api_refresh_tokens table schema match.");
        }
    } else {
        addResult('10. Token Generation', 'info', 'Skipped — password verification failed in earlier steps.');
    }
    
    // --------------------------------------------------------
    // Step 11: API Endpoint Curl Test
    // --------------------------------------------------------
    if (function_exists('curl_init')) {
        try {
            $apiUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/auth/login';
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['username' => $username, 'password' => $password]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                addResult('11. Live API Test', 'error', "Curl error: {$curlError}",
                    "API URL: {$apiUrl}. Check .htaccess rewrite rules.");
            } else {
                $decoded = json_decode($response, true);
                $status = ($httpCode === 200) ? 'ok' : 'error';
                addResult('11. Live API Test', $status, [
                    'url' => $apiUrl,
                    'http_code' => $httpCode,
                    'response' => $decoded ?: substr($response, 0, 500),
                ], ($httpCode === 500) ? "500 Internal Server Error. Check Apache error log:\ntail -f /var/log/apache2/error.log\n\nOr check: index.php line 19 sets display_errors=0. Temporarily change to 1." : null);
            }
        } catch (Throwable $e) {
            addResult('11. Live API Test', 'error', $e->getMessage());
        }
    } else {
        addResult('11. Live API Test', 'info', 'curl not available — skipped');
    }
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
        .container { max-width: 900px; margin: 0 auto; }
        h1 { color: #00d4aa; margin-bottom: 4px; }
        h2 { color: #00d4aa; margin-bottom: 16px; font-size: 18px; }
        .subtitle { color: #888; margin-bottom: 20px; font-size: 14px; }
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
        .result { padding: 14px 18px; border-radius: 8px; margin-bottom: 10px; font-size: 14px; }
        .result-ok { background: #0a3d2e; border-left: 4px solid #00d4aa; }
        .result-error { background: #3d0a0a; border-left: 4px solid #ff4444; }
        .result-info { background: #1a2a4a; border-left: 4px solid #4488ff; }
        .result .step { font-weight: 700; color: #fff; font-size: 15px; }
        .result .detail { color: #ccc; margin-top: 6px; word-break: break-word; }
        .result .detail pre { background: #0a0a1a; padding: 10px; border-radius: 6px; overflow-x: auto; margin-top: 6px; font-size: 12px; line-height: 1.5; white-space: pre-wrap; color: #ddd; }
        .result .fix { background: #1a2a1a; border: 1px dashed #44aa44; padding: 10px; border-radius: 6px; margin-top: 8px; font-size: 12px; color: #88cc88; white-space: pre-wrap; }
        .result .fix::before { content: '💡 Fix: '; font-weight: bold; }
        .warn { background: #3d2a0a; color: #ffaa44; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; }
        .config-info { font-size: 12px; color: #555; margin-top: 20px; line-height: 1.8; }
        .config-info code { background: #0a0a1a; padding: 2px 6px; border-radius: 4px; color: #888; }
        .success-banner { background: linear-gradient(135deg, #00b894, #00d4aa); color: #1a1a2e; padding: 20px; border-radius: 12px; text-align: center; margin-bottom: 20px; }
        .success-banner h2 { color: #1a1a2e; font-size: 22px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 GarageMinder API - Debug Login</h1>
        <p class="subtitle">Comprehensive diagnostic login — tests every component with detailed error info</p>
        
        <div class="warn">⚠️ DELETE this file after debugging! It exposes database info and credentials.</div>
        
        <div class="card">
            <form method="POST">
                <label for="username">Username or Email</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($username) ?>" placeholder="your@email.com" autocomplete="off">
                
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter password" autocomplete="off">
                
                <button type="submit">🔐 Test Login (12 Steps)</button>
            </form>
        </div>
        
        <?php 
        $hasSuccess = false;
        foreach ($results as $r) {
            if (strpos($r['detail'] ?? '', 'FULL LOGIN SUCCESSFUL') !== false) $hasSuccess = true;
            if (is_array($r['detail'] ?? '') && ($r['detail']['status'] ?? '') === '🎉 FULL LOGIN SUCCESSFUL!') $hasSuccess = true;
        }
        if ($hasSuccess): ?>
        <div class="success-banner">
            <h2>🎉 LOGIN WORKS!</h2>
            <p>All components verified. If the actual API still returns 500, the issue is in index.php dispatch (not login logic).</p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($results)): ?>
        <div class="card">
            <h2>Results (<?= count($results) ?> steps)</h2>
            <?php foreach ($results as $r): ?>
                <?php
                    $cls = 'result-info';
                    $icon = 'ℹ️';
                    if ($r['status'] === 'ok') { $cls = 'result-ok'; $icon = '✅'; }
                    if ($r['status'] === 'error') { $cls = 'result-error'; $icon = '❌'; }
                ?>
                <div class="result <?= $cls ?>">
                    <div class="step"><?= $icon ?> <?= htmlspecialchars($r['step']) ?></div>
                    <div class="detail">
                        <?php if (is_array($r['detail'])): ?>
                            <pre><?= htmlspecialchars(json_encode($r['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                        <?php else: ?>
                            <pre><?= htmlspecialchars($r['detail']) ?></pre>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($r['fix'])): ?>
                        <div class="fix"><?= htmlspecialchars($r['fix']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="config-info">
            <p>Config: <code><?= defined('GM_CONFIG_PATH') ? GM_CONFIG_PATH : 'N/A' ?></code></p>
            <p>WP Config: <code><?= defined('WP_CONFIG_PATH') ? WP_CONFIG_PATH : 'N/A' ?></code></p>
            <p>Table Prefix: <code><?= defined('WP_TABLE_PREFIX') ? WP_TABLE_PREFIX : 'N/A' ?></code></p>
            <p>PHP: <code><?= phpversion() ?></code> | hash_hmac: <code><?= function_exists('hash_hmac') ? 'available' : 'MISSING' ?></code> | sodium: <code><?= extension_loaded('sodium') ? 'loaded' : 'not loaded' ?></code></p>
            <p>Server: <code><?= $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' ?></code></p>
        </div>
    </div>
</body>
</html>
