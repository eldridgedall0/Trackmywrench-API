<?php
namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class Token
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get active refresh tokens for a user
     */
    public function getActiveTokens(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, device_name, platform, ip_address, 
                    created_at, expires_at
             FROM api_refresh_tokens 
             WHERE user_id = ? AND revoked = 0 AND expires_at > NOW()
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    /**
     * Count active sessions for a user
     */
    public function countActiveSessions(int $userId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM api_refresh_tokens 
             WHERE user_id = ? AND revoked = 0 AND expires_at > NOW()",
            [$userId]
        );
    }
}
