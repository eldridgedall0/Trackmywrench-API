<?php
namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class User
{
    private Database $wpDb;   // WordPress DB
    private Database $gmDb;   // GarageMinder DB

    public function __construct()
    {
        $this->wpDb = Database::getWordPress();
        $this->gmDb = Database::getInstance();
    }

    /** Shorthand for prefixed WP table name */
    private function t(string $table): string
    {
        return Database::wpTable($table);
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array
    {
        $t = $this->t('users');
        return $this->wpDb->fetchOne(
            "SELECT ID as id, user_login as username, user_email as email, 
                    display_name, user_registered as registered_at
             FROM `{$t}` WHERE ID = ?",
            [$id]
        );
    }

    /**
     * Find user by username or email
     */
    public function findByLogin(string $login): ?array
    {
        $t = $this->t('users');
        return $this->wpDb->fetchOne(
            "SELECT ID as id, user_login as username, user_email as email,
                    display_name, user_pass as password_hash, user_registered as registered_at
             FROM `{$t}` WHERE user_login = ? OR user_email = ?",
            [$login, $login]
        );
    }

    /**
     * Validate WordPress password hash
     * 
     * Supports all WordPress password hash formats:
     * - MD5 (32 chars)           → Legacy, hash_equals(md5(password), hash)
     * - $P$ / $H$               → WordPress phpass (MD5-based, pre-6.8)
     * - $wp$2y$ / $wp$2b$       → WordPress 6.8+ bcrypt with SHA-384 pre-hash
     * - $2y$ / $2b$ / $argon2*  → Standard bcrypt/argon (plugins, manual resets)
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        // Legacy MD5 (32-char hex hash, very old WordPress or manual DB entry)
        if (strlen($hash) <= 32) {
            return hash_equals($hash, md5($password));
        }
        
        // Passwords longer than 4096 chars not supported (WordPress 6.8 check)
        if (strlen($password) > 4096) {
            return false;
        }
        
        // WordPress 6.8+ bcrypt: "$wp$2y$10$..." 
        // Password is pre-hashed: base64(HMAC-SHA384(password, "wp-sha384"))
        // Then bcrypt of that pre-hash is stored with "$wp$" prefix
        if (strpos($hash, '$wp$') === 0) {
            $passwordToVerify = base64_encode(
                hash_hmac('sha384', $password, 'wp-sha384', true)
            );
            return password_verify($passwordToVerify, substr($hash, 3));
        }
        
        // WordPress phpass ($P$ or $H$ prefix, pre-6.8 installs)
        if (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0) {
            return $this->checkPhpass($password, $hash);
        }
        
        // Standard bcrypt ($2y$), argon2 ($argon2id$), or other password_hash formats
        return password_verify($password, $hash);
    }

    /**
     * Get user's subscription level.
     * 
     * Uses the GarageMinder app's gm_get_current_user_info() approach:
     * Includes the garage config.php which defines that function,
     * then calls it to get has_subscription, subscription_tier, etc.
     * 
     * This way the API always matches whatever subscription plugin 
     * the garage app is configured to use — no hardcoded plugin tables.
     */
    public function getSubscriptionLevel(int $userId): array
    {
        // Try the GarageMinder config approach first
        $result = $this->getSubscriptionFromGarageConfig($userId);
        if ($result !== null) {
            return $result;
        }

        // Fallback: return free with unknown tier
        return [
            'has_subscription' => false,
            'subscription_tier' => 'free',
            'subscription_level_name' => 'Free',
        ];
    }

    /**
     * Load GarageMinder's config.php and use gm_get_current_user_info()
     * to determine subscription status.
     */
    private function getSubscriptionFromGarageConfig(int $userId): ?array
    {
        // GM_CONFIG_PATH is set in api_config.php — points to the garage app's config.php
        if (!defined('GM_CONFIG_PATH') || !file_exists(GM_CONFIG_PATH)) {
            return null;
        }

        try {
            // Include the garage config if gm_get_current_user_info is not yet available
            if (!function_exists('gm_get_current_user_info')) {
                // The garage config.php may need WordPress functions or globals.
                // We'll try including it, but if it fails we fall back gracefully.
                @include_once GM_CONFIG_PATH;
            }

            if (function_exists('gm_get_current_user_info')) {
                $info = gm_get_current_user_info($userId);
                if (is_array($info)) {
                    return [
                        'has_subscription' => !empty($info['has_subscription']),
                        'subscription_tier' => $info['subscription_tier'] ?? 'free',
                        'subscription_level_name' => $info['subscription_level_name'] ?? 'Free',
                    ];
                }
            }

            // If function doesn't exist after include, try direct DB query
            // against whatever subscription data the garage DB holds
            return $this->getSubscriptionFromGarageDB($userId);

        } catch (\Throwable $e) {
            // Config include failed — try direct DB approach
            return $this->getSubscriptionFromGarageDB($userId);
        }
    }

    /**
     * Fallback: Query the GarageMinder database directly for subscription info.
     * Checks common subscription/membership patterns without assuming a specific plugin.
     */
    private function getSubscriptionFromGarageDB(int $userId): ?array
    {
        // Try reading garage config.php to find subscription logic
        // Check if there's a user_subscriptions or similar table in the GM database
        try {
            // Check for a subscriptions table in the GarageMinder DB
            $tables = ['user_subscriptions', 'subscriptions', 'memberships', 'user_memberships'];
            foreach ($tables as $table) {
                if ($this->gmDb->tableExists($table)) {
                    $sub = $this->gmDb->fetchOne(
                        "SELECT * FROM `{$table}` WHERE user_id = ? ORDER BY id DESC LIMIT 1",
                        [$userId]
                    );
                    if ($sub) {
                        // Found subscription data — interpret it
                        $isActive = !empty($sub['is_active']) || 
                                    (!empty($sub['status']) && strtolower($sub['status']) === 'active') ||
                                    (!empty($sub['account_state']) && strtolower($sub['account_state']) === 'active');
                        $tier = $sub['tier'] ?? $sub['level'] ?? $sub['plan'] ?? 'free';
                        $name = $sub['level_name'] ?? $sub['plan_name'] ?? ucfirst($tier);
                        
                        return [
                            'has_subscription' => $isActive && strtolower($tier) !== 'free',
                            'subscription_tier' => strtolower($tier),
                            'subscription_level_name' => $name,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore DB errors in fallback
        }

        // Last resort: try WordPress SWPM tables (Simple WP Membership)
        return $this->getSubscriptionFromSWPM($userId);
    }

    /**
     * Last-resort fallback: Check Simple WP Membership plugin tables.
     * Only used if gm_get_current_user_info() is unavailable AND
     * no subscription table found in GarageMinder DB.
     */
    private function getSubscriptionFromSWPM(int $userId): ?array
    {
        try {
            $tMembers = $this->t('swpm_members_tbl');
            $tLevels = $this->t('swpm_membership_tbl');

            // Check if tables exist
            if (!$this->wpDb->tableExists($tMembers)) {
                return null;
            }

            $member = $this->wpDb->fetchOne(
                "SELECT membership_level, account_state FROM `{$tMembers}` WHERE member_id = ?",
                [$userId]
            );

            if (!$member || $member['account_state'] !== 'active') {
                return [
                    'has_subscription' => false,
                    'subscription_tier' => 'free',
                    'subscription_level_name' => 'Free',
                ];
            }

            $level = $this->wpDb->fetchOne(
                "SELECT id, alias FROM `{$tLevels}` WHERE id = ?",
                [$member['membership_level']]
            );

            if (!$level) {
                return [
                    'has_subscription' => false,
                    'subscription_tier' => 'free',
                    'subscription_level_name' => 'Free',
                ];
            }

            $alias = strtolower($level['alias'] ?? '');
            $paidKeywords = ['paid', 'premium', 'pro', 'subscriber', 'plus', 'business', 'enterprise'];
            $isPaid = false;
            
            foreach ($paidKeywords as $keyword) {
                if (strpos($alias, $keyword) !== false) {
                    $isPaid = true;
                    break;
                }
            }

            // Also consider level ID > 1 as paid (common SWPM pattern)
            if (!$isPaid && (int) $level['id'] > 1) {
                $isPaid = true;
            }

            return [
                'has_subscription' => $isPaid,
                'subscription_tier' => $isPaid ? 'paid' : 'free',
                'subscription_level_name' => $level['alias'] ?? ($isPaid ? 'Paid' : 'Free'),
            ];

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if user is WordPress administrator
     */
    public function isAdmin(int $userId): bool
    {
        $t = $this->t('usermeta');
        $capKey = WP_TABLE_PREFIX . 'capabilities';

        $meta = $this->wpDb->fetchOne(
            "SELECT meta_value FROM `{$t}` WHERE user_id = ? AND meta_key = ?",
            [$userId, $capKey]
        );

        if (!$meta) return false;

        $caps = @unserialize($meta['meta_value']);
        return is_array($caps) && isset($caps['administrator']);
    }

    /**
     * Get user profile with subscription info
     */
    public function getProfile(int $userId): ?array
    {
        $user = $this->findById($userId);
        if (!$user) return null;

        $subscription = $this->getSubscriptionLevel($userId);
        $user['has_subscription'] = $subscription['has_subscription'];
        $user['subscription_tier'] = $subscription['subscription_tier'];
        $user['subscription_level_name'] = $subscription['subscription_level_name'];
        // Keep backward compat: subscription_level maps to tier
        $user['subscription_level'] = $subscription['subscription_tier'];
        $user['is_admin'] = $this->isAdmin($userId);

        return $user;
    }

    /**
     * Get all users (admin only)
     */
    public function getAllUsers(int $limit = 50, int $offset = 0): array
    {
        $t = $this->t('users');
        return $this->wpDb->fetchAll(
            "SELECT ID as id, user_login as username, user_email as email,
                    display_name, user_registered as registered_at
             FROM `{$t}` ORDER BY ID ASC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Count total users
     */
    public function countUsers(): int
    {
        $t = $this->t('users');
        return (int) $this->wpDb->fetchColumn("SELECT COUNT(*) FROM `{$t}`");
    }

    // ========================================================================
    // WordPress phpass password verification
    // ========================================================================

    private function checkPhpass(string $password, string $storedHash): bool
    {
        $itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $countLog2 = strpos($itoa64, $storedHash[3]);
        $count = 1 << $countLog2;
        $salt = substr($storedHash, 4, 8);

        $hash = md5($salt . $password, true);
        do {
            $hash = md5($hash . $password, true);
        } while (--$count);

        $output = substr($storedHash, 0, 12);
        $output .= $this->encode64($hash, 16, $itoa64);

        return hash_equals($storedHash, $output);
    }

    private function encode64(string $input, int $count, string $itoa64): string
    {
        $output = '';
        $i = 0;
        do {
            $value = ord($input[$i++]);
            $output .= $itoa64[$value & 0x3f];
            if ($i < $count) $value |= ord($input[$i]) << 8;
            $output .= $itoa64[($value >> 6) & 0x3f];
            if ($i++ >= $count) break;
            if ($i < $count) $value |= ord($input[$i]) << 16;
            $output .= $itoa64[($value >> 12) & 0x3f];
            if ($i++ >= $count) break;
            $output .= $itoa64[($value >> 18) & 0x3f];
        } while ($i < $count);
        return $output;
    }
}
