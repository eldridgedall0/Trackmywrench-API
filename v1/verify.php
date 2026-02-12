<?php
/**
 * GarageMinder API - File Verification Script
 * 
 * Checks that model files are correctly deployed (no gm_ prefix, correct subscription logic).
 * DELETE THIS FILE after verifying!
 */
header('Content-Type: text/plain; charset=utf-8');

echo "=== GarageMinder API - File Verification ===\n\n";

$apiDir = __DIR__;

// Files to check for gm_ table prefix issue
$filesToCheck = [
    'models/Vehicle.php',
    'models/Reminder.php',
    'models/User.php',
    'endpoints/vehicles/RemindersEndpoint.php',
    'endpoints/auth/LoginEndpoint.php',
];

$allGood = true;

foreach ($filesToCheck as $file) {
    $path = $apiDir . '/' . $file;
    echo "--- {$file} ---\n";
    
    if (!file_exists($path)) {
        echo "  ❌ FILE NOT FOUND!\n\n";
        $allGood = false;
        continue;
    }
    
    $contents = file_get_contents($path);
    $size = strlen($contents);
    $modified = date('Y-m-d H:i:s', filemtime($path));
    echo "  Size: {$size} bytes | Modified: {$modified}\n";
    
    // Check for bad gm_ table references (FROM gm_, JOIN gm_, etc.)
    // Exclude legitimate uses like GM_CONFIG, gm_get_current_user_info, etc.
    preg_match_all('/(?:FROM|JOIN|INTO|UPDATE|TABLE)\s+(?:`?)gm_\w+/', $contents, $matches);
    if (!empty($matches[0])) {
        echo "  ❌ STILL HAS gm_ TABLE PREFIX:\n";
        foreach ($matches[0] as $match) {
            echo "     → {$match}\n";
        }
        $allGood = false;
    } else {
        echo "  ✅ No gm_ table prefixes\n";
    }
    
    // Check for correct table names
    preg_match_all('/(?:FROM|JOIN|INTO|UPDATE)\s+(?:`?)(\w+)(?:`?)/', $contents, $tableMatches);
    if (!empty($tableMatches[1])) {
        $tables = array_unique($tableMatches[1]);
        echo "  Tables referenced: " . implode(', ', $tables) . "\n";
    }
    
    // Special checks per file
    if ($file === 'models/User.php') {
        if (strpos($contents, 'getSubscriptionFromGarageConfig') !== false) {
            echo "  ✅ Uses garage config subscription check\n";
        } elseif (strpos($contents, 'gm_get_current_user_info') !== false) {
            echo "  ✅ Uses gm_get_current_user_info\n";
        } else {
            echo "  ⚠️  No garage config subscription check found (old version?)\n";
        }
        
        if (strpos($contents, 'has_subscription') !== false) {
            echo "  ✅ Returns has_subscription field\n";
        } else {
            echo "  ⚠️  Missing has_subscription field (old version?)\n";
        }
        
        if (strpos($contents, '$wp$') !== false) {
            echo "  ✅ Supports WP 6.8 bcrypt password verification\n";
        } else {
            echo "  ❌ Missing WP 6.8 password support!\n";
            $allGood = false;
        }
    }
    
    if ($file === 'endpoints/auth/LoginEndpoint.php') {
        if (strpos($contents, 'has_subscription') !== false) {
            echo "  ✅ Returns has_subscription in login response\n";
        } else {
            echo "  ⚠️  Login response missing has_subscription (old version?)\n";
        }
        if (strpos($contents, 'subscription_tier') !== false) {
            echo "  ✅ Returns subscription_tier in login response\n";
        } else {
            echo "  ⚠️  Login response missing subscription_tier (old version?)\n";
        }
    }
    
    echo "\n";
}

// Quick DB table check
echo "--- Database Table Verification ---\n";
try {
    // Load API config
    define('GM_API', true);
    require_once $apiDir . '/config/api_config.php';
    
    $gmConfig = get_db_config();
    $dsn = "mysql:host={$gmConfig['host']};dbname={$gmConfig['name']};charset=utf8mb4";
    if (!empty($gmConfig['port'])) {
        $dsn = "mysql:host={$gmConfig['host']};port={$gmConfig['port']};dbname={$gmConfig['name']};charset=utf8mb4";
    }
    $pdo = new PDO($dsn, $gmConfig['user'], $gmConfig['pass']);
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "  GarageMinder DB tables: " . implode(', ', $tables) . "\n";
    
    // Check if gm_ prefixed tables exist
    $gmPrefixTables = array_filter($tables, fn($t) => strpos($t, 'gm_') === 0);
    $noPrefixTables = array_filter($tables, fn($t) => strpos($t, 'gm_') !== 0 && strpos($t, 'api_') !== 0);
    
    if (!empty($gmPrefixTables)) {
        echo "  ℹ️  Tables WITH gm_ prefix: " . implode(', ', $gmPrefixTables) . "\n";
    }
    if (!empty($noPrefixTables)) {
        echo "  ℹ️  Tables WITHOUT prefix: " . implode(', ', $noPrefixTables) . "\n";
    }
    
    // The real check: do we have 'vehicles' or 'gm_vehicles'?
    if (in_array('vehicles', $tables)) {
        echo "  ✅ 'vehicles' table exists (no prefix)\n";
    }
    if (in_array('gm_vehicles', $tables)) {
        echo "  ⚠️  'gm_vehicles' also exists (prefixed version)\n";
    }
    if (in_array('reminders', $tables)) {
        echo "  ✅ 'reminders' table exists (no prefix)\n";
    }
    if (in_array('gm_reminders', $tables)) {
        echo "  ⚠️  'gm_reminders' also exists (prefixed version)\n";
    }
    
} catch (Exception $e) {
    echo "  ❌ DB Error: " . $e->getMessage() . "\n";
}

// Check if gm_get_current_user_info is available
echo "\n--- Subscription Function Check ---\n";
if (defined('GM_CONFIG_PATH') && file_exists(GM_CONFIG_PATH)) {
    echo "  GM_CONFIG_PATH: " . GM_CONFIG_PATH . "\n";
    $configContents = file_get_contents(GM_CONFIG_PATH);
    if (strpos($configContents, 'gm_get_current_user_info') !== false) {
        echo "  ✅ gm_get_current_user_info() defined in garage config\n";
    } else {
        echo "  ⚠️  gm_get_current_user_info() NOT in garage config\n";
        echo "     → API will fall back to direct DB/SWPM queries\n";
    }
} else {
    echo "  ⚠️  GM_CONFIG_PATH not set or file not found\n";
}

echo "\n";
if ($allGood) {
    echo "🎉 ALL CHECKS PASSED!\n";
    echo "You can safely delete this verify.php file.\n";
} else {
    echo "❌ SOME CHECKS FAILED - see details above.\n";
    echo "Make sure you uploaded the latest files from the output.\n";
}
