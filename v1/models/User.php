<?php
namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class User
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT ID as id, user_login as username, user_email as email, 
                    display_name, user_registered as registered_at
             FROM wp_users WHERE ID = ?",
            [$id]
        );
    }

    /**
     * Find user by username or email
     */
    public function findByLogin(string $login): ?array
    {
        return $this->db->fetchOne(
            "SELECT ID as id, user_login as username, user_email as email,
                    display_name, user_pass as password_hash, user_registered as registered_at
             FROM wp_users WHERE user_login = ? OR user_email = ?",
            [$login, $login]
        );
    }

    /**
     * Validate WordPress password hash
     * WordPress uses phpass portable hashing.
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        // WordPress uses $P$ prefix for phpass
        if (strpos($hash, '$P$') === 0 || strpos($hash, '$H$') === 0) {
            return $this->checkPhpass($password, $hash);
        }
        // Fallback: bcrypt or native PHP password_verify
        return password_verify($password, $hash);
    }

    /**
     * Get user's subscription level from Simple Membership plugin
     */
    public function getSubscriptionLevel(int $userId): string
    {
        // Check Simple Membership plugin tables
        $member = $this->db->fetchOne(
            "SELECT membership_level, account_state FROM wp_swpm_members_tbl WHERE member_id = ?",
            [$userId]
        );

        if (!$member || $member['account_state'] !== 'active') {
            return 'free';
        }

        // Check if their membership level is a paid tier
        $level = $this->db->fetchOne(
            "SELECT id, alias FROM wp_swpm_membership_tbl WHERE id = ?",
            [$member['membership_level']]
        );

        if (!$level) return 'free';

        // Determine if paid based on alias or level id
        // Adjust this logic based on your actual membership level setup
        $paidAliases = ['paid', 'premium', 'pro', 'subscriber'];
        $alias = strtolower($level['alias'] ?? '');

        foreach ($paidAliases as $paidAlias) {
            if (strpos($alias, $paidAlias) !== false) {
                return 'paid';
            }
        }

        // If membership level > 1 (free is typically level 1), consider it paid
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
        $meta = $this->db->fetchOne(
            "SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities'",
            [$userId]
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
        return $this->db->fetchAll(
            "SELECT ID as id, user_login as username, user_email as email,
                    display_name, user_registered as registered_at
             FROM wp_users ORDER BY ID ASC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * Count total users
     */
    public function countUsers(): int
    {
        return (int) $this->db->fetchColumn("SELECT COUNT(*) FROM wp_users");
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
