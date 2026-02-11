<?php
namespace GarageMinder\API\Endpoints\Admin;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database};

class StatsEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $db = Database::getInstance();

        $stats = [
            'requests' => [
                'total_24h' => (int) $db->fetchColumn(
                    "SELECT COUNT(*) FROM api_request_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                ),
                'total_7d' => (int) $db->fetchColumn(
                    "SELECT COUNT(*) FROM api_request_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
                ),
                'errors_24h' => (int) $db->fetchColumn(
                    "SELECT COUNT(*) FROM api_request_log WHERE status_code >= 400 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                ),
                'avg_response_ms' => (float) $db->fetchColumn(
                    "SELECT AVG(response_time_ms) FROM api_request_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                ),
            ],
            'auth' => [
                'active_tokens' => (int) $db->fetchColumn(
                    "SELECT COUNT(*) FROM api_refresh_tokens WHERE revoked = 0 AND expires_at > NOW()"
                ),
                'unique_users_24h' => (int) $db->fetchColumn(
                    "SELECT COUNT(DISTINCT user_id) FROM api_request_log WHERE user_id IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
                ),
            ],
            'devices' => [
                'total_registered' => (int) $db->fetchColumn(
                    "SELECT COUNT(*) FROM api_devices WHERE is_active = 1"
                ),
                'by_platform' => $db->fetchAll(
                    "SELECT platform, COUNT(*) as count FROM api_devices WHERE is_active = 1 GROUP BY platform"
                ),
            ],
            'sync' => [
                'total_syncs' => (int) $db->fetchColumn(
                    "SELECT SUM(sync_count) FROM api_sync_status"
                ),
                'total_miles' => (float) $db->fetchColumn(
                    "SELECT SUM(total_miles_synced) FROM api_sync_status"
                ),
            ],
            'top_endpoints_24h' => $db->fetchAll(
                "SELECT endpoint, method, COUNT(*) as count, AVG(response_time_ms) as avg_ms
                 FROM api_request_log 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 GROUP BY endpoint, method ORDER BY count DESC LIMIT 10"
            ),
        ];

        Response::success($stats);
    }
}
