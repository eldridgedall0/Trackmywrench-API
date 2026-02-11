<?php
/**
 * GarageMinder Mobile API - JWT Handler
 * 
 * Pure PHP JWT implementation (no external dependencies).
 * Handles access tokens, refresh tokens, and validation.
 */

namespace GarageMinder\API\Core;

class JWTHandler
{
    private string $secret;
    private Database $db;

    public function __construct()
    {
        $this->secret = get_jwt_secret();
        $this->db = Database::getInstance();
    }

    // ========================================================================
    // Access Token Operations
    // ========================================================================

    /**
     * Create an access token for a user
     */
    public function createAccessToken(int $userId, array $claims = []): string
    {
        $now = time();
        $payload = array_merge([
            'iss'  => JWT_ISSUER,
            'sub'  => $userId,
            'iat'  => $now,
            'exp'  => $now + JWT_ACCESS_TOKEN_EXPIRY,
            'type' => 'access',
        ], $claims);

        return $this->encode($payload);
    }

    /**
     * Validate an access token and return payload
     */
    public function validateAccessToken(string $token): ?array
    {
        $payload = $this->decode($token);

        if ($payload === null) return null;
        if (($payload['type'] ?? '') !== 'access') return null;
        if (($payload['exp'] ?? 0) < time()) return null;
        if (($payload['iss'] ?? '') !== JWT_ISSUER) return null;

        return $payload;
    }

    // ========================================================================
    // Refresh Token Operations
    // ========================================================================

    /**
     * Create a refresh token and store in database
     */
    public function createRefreshToken(
        int $userId,
        ?string $deviceId = null,
        ?string $deviceName = null,
        ?string $platform = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): string {
        // Generate secure random token
        $token = bin2hex(random_bytes(64));
        $hash = hash('sha256', $token);

        $expiresAt = date('Y-m-d H:i:s', time() + JWT_REFRESH_TOKEN_EXPIRY);

        $this->db->insert('api_refresh_tokens', [
            'user_id'     => $userId,
            'token_hash'  => $hash,
            'device_id'   => $deviceId,
            'device_name' => $deviceName,
            'platform'    => $platform,
            'ip_address'  => $ipAddress,
            'user_agent'  => $userAgent ? substr($userAgent, 0, 500) : null,
            'expires_at'  => $expiresAt,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Validate a refresh token and return user_id
     */
    public function validateRefreshToken(string $token): ?int
    {
        $hash = hash('sha256', $token);

        $record = $this->db->fetchOne(
            "SELECT user_id, expires_at, revoked FROM api_refresh_tokens 
             WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW()",
            [$hash]
        );

        if (!$record) return null;

        return (int) $record['user_id'];
    }

    /**
     * Revoke a specific refresh token
     */
    public function revokeRefreshToken(string $token): bool
    {
        $hash = hash('sha256', $token);

        $affected = $this->db->execute(
            "UPDATE api_refresh_tokens SET revoked = 1, revoked_at = NOW() WHERE token_hash = ?",
            [$hash]
        );

        return $affected > 0;
    }

    /**
     * Revoke ALL refresh tokens for a user (e.g., password change, logout all)
     */
    public function revokeAllUserTokens(int $userId): int
    {
        return $this->db->execute(
            "UPDATE api_refresh_tokens SET revoked = 1, revoked_at = NOW() WHERE user_id = ? AND revoked = 0",
            [$userId]
        );
    }

    /**
     * Rotate a refresh token (revoke old, create new)
     */
    public function rotateRefreshToken(
        string $oldToken,
        ?string $deviceId = null,
        ?string $deviceName = null,
        ?string $platform = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?array {
        $userId = $this->validateRefreshToken($oldToken);
        if ($userId === null) return null;

        // Revoke the old token
        $this->revokeRefreshToken($oldToken);

        // Get device info from old token if not provided
        if ($deviceId === null) {
            $hash = hash('sha256', $oldToken);
            $old = $this->db->fetchOne(
                "SELECT device_id, device_name, platform FROM api_refresh_tokens WHERE token_hash = ?",
                [$hash]
            );
            if ($old) {
                $deviceId = $old['device_id'];
                $deviceName = $deviceName ?? $old['device_name'];
                $platform = $platform ?? $old['platform'];
            }
        }

        // Create new tokens
        $newRefreshToken = $this->createRefreshToken(
            $userId, $deviceId, $deviceName, $platform, $ipAddress, $userAgent
        );
        $newAccessToken = $this->createAccessToken($userId);

        return [
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in'    => JWT_ACCESS_TOKEN_EXPIRY,
            'user_id'       => $userId,
        ];
    }

    // ========================================================================
    // JWT Encoding/Decoding (Pure PHP - no external libraries)
    // ========================================================================

    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'alg' => JWT_ALGORITHM,
            'typ' => 'JWT',
        ]));

        $payload = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true)
        );

        return "{$header}.{$payload}.{$signature}";
    }

    private function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        // Verify signature
        $expectedSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true)
        );

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $decoded = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($decoded)) return null;

        return $decoded;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    // ========================================================================
    // Cleanup
    // ========================================================================

    /**
     * Remove expired and old revoked tokens (call via cron)
     */
    public function cleanup(): int
    {
        return $this->db->execute(
            "DELETE FROM api_refresh_tokens 
             WHERE expires_at < NOW() 
             OR (revoked = 1 AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY))"
        );
    }
}
