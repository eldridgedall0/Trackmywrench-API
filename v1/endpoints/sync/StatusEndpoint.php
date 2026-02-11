<?php
namespace GarageMinder\API\Endpoints\Sync;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database};

class StatusEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $deviceId = $request->getHeader('x-device-id');
        $db = Database::getInstance();

        $status = $db->fetchOne(
            "SELECT last_sync_push, last_sync_pull, vehicles_synced, 
                    total_miles_synced, sync_count, last_error
             FROM api_sync_status WHERE user_id = ? AND device_id <=> ?",
            [$userId, $deviceId]
        );

        if (!$status) {
            $status = [
                'last_sync_push' => null,
                'last_sync_pull' => null,
                'vehicles_synced' => 0,
                'total_miles_synced' => 0,
                'sync_count' => 0,
                'last_error' => null,
            ];
        }

        $status['total_miles_synced'] = (float) $status['total_miles_synced'];
        $status['vehicles_synced'] = (int) $status['vehicles_synced'];
        $status['sync_count'] = (int) $status['sync_count'];

        Response::success($status);
    }
}
