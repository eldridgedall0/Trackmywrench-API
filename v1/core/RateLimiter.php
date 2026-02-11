<?php
/**
 * GarageMinder Mobile API - Rate Limiter
 */

namespace GarageMinder\API\Core;

class RateLimiter
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Check if request is allowed. Returns remaining requests or false if blocked.
     */
    public function check(string $identifier, string $type = 'ip', ?string $endpoint = null): array
    {
        $maxRequests = ($type === 'user') ? RATE_LIMIT_USER_REQUESTS : RATE_LIMIT_IP_REQUESTS;
        $windowSeconds = ($type === 'user') ? RATE_LIMIT_USER_WINDOW : RATE_LIMIT_IP_WINDOW;

        // Special limits for login endpoint
        if ($endpoint === '/auth/login') {
            $maxRequests = RATE_LIMIT_LOGIN_REQUESTS;
            $windowSeconds = RATE_LIMIT_LOGIN_WINDOW;
        }

        $windowStart = date('Y-m-d H:i:s', (int)(time() / $windowSeconds) * $windowSeconds);

        // Try to increment existing counter
        $existing = $this->db->fetchOne(
            "SELECT id, request_count FROM api_rate_limits 
             WHERE identifier = ? AND identifier_type = ? AND endpoint <=> ? AND window_start = ?",
            [$identifier, $type, $endpoint, $windowStart]
        );

        if ($existing) {
            $count = (int) $existing['request_count'];

            if ($count >= $maxRequests) {
                $resetTime = ((int)(time() / $windowSeconds) + 1) * $windowSeconds;
                return [
                    'allowed'   => false,
                    'limit'     => $maxRequests,
                    'remaining' => 0,
                    'reset'     => $resetTime,
                ];
            }

            $this->db->execute(
                "UPDATE api_rate_limits SET request_count = request_count + 1 WHERE id = ?",
                [$existing['id']]
            );
            $count++;
        } else {
            // Clean old windows first (prevent table bloat)
            $this->db->execute(
                "DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );

            // Insert new counter
            try {
                $this->db->insert('api_rate_limits', [
                    'identifier'      => $identifier,
                    'identifier_type' => $type,
                    'endpoint'        => $endpoint,
                    'request_count'   => 1,
                    'window_start'    => $windowStart,
                    'window_seconds'  => $windowSeconds,
                ]);
            } catch (\Exception $e) {
                // Race condition: another request inserted first, increment instead
                $this->db->execute(
                    "UPDATE api_rate_limits SET request_count = request_count + 1 
                     WHERE identifier = ? AND identifier_type = ? AND endpoint <=> ? AND window_start = ?",
                    [$identifier, $type, $endpoint, $windowStart]
                );
            }
            $count = 1;
        }

        $resetTime = ((int)(time() / $windowSeconds) + 1) * $windowSeconds;

        return [
            'allowed'   => true,
            'limit'     => $maxRequests,
            'remaining' => max(0, $maxRequests - $count),
            'reset'     => $resetTime,
        ];
    }

    /**
     * Set rate limit headers on response
     */
    public function setHeaders(array $result): void
    {
        header("X-RateLimit-Limit: {$result['limit']}");
        header("X-RateLimit-Remaining: {$result['remaining']}");
        header("X-RateLimit-Reset: {$result['reset']}");

        if (!$result['allowed']) {
            header("Retry-After: " . max(1, $result['reset'] - time()));
        }
    }
}
