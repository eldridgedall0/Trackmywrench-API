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
        // To verify: pre-hash the input password the same way, then password_verify
        // against the hash with "$wp" stripped (3 chars, keeping the leading "$")
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
     * Get user's subscription level from Simple Membership plugin
     */
    public function getSubscriptionLevel(int $userId): string
    {
        $tMembers = $this->t('swpm_members_tbl');
        $tLevels = $this->t('swpm_membership_tbl');

        $member = $this->wpDb->fetchOne(
            "SELECT membership_level, account_state FROM `{$tMembers}` WHERE member_id = ?",
            [$userId]
        );

        if (!$member || $member['account_state'] !== 'active') {
            return 'free';
        }

        $level = $this->wpDb->fetchOne(
            "SELECT id, alias FROM `{$tLevels}` WHERE id = ?",
            [$member['membership_level']]
        );

        if (!$level) return 'free';

        $paidAliases = ['paid', 'premium', 'pro', 'subscriber'];
        $alias = strtolower($level['alias'] ?? '');

        foreach ($paidAliases as $paidAlias) {
            if (strpos($alias, $paidAlias) !== false) {
                return 'paid';
            }
        }

        if ((int) $level['id'] > 1) {
            return 'paid';
        }

        return 'free';
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

        $user['subscription_level'] = $this->getSubscriptionLevel($userId);
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
