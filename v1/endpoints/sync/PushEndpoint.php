<?php
namespace GarageMinder\API\Endpoints\Sync;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database, Validator};
use GarageMinder\API\Models\Vehicle;

class PushEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicles = $request->getBody('vehicles');

        if (!is_array($vehicles) || empty($vehicles)) {
            Response::error('vehicles array is required.', 422);
            return;
        }

        if (count($vehicles) > SYNC_MAX_VEHICLES_PER_PUSH) {
            Response::error('Too many vehicles in single push. Max: ' . SYNC_MAX_VEHICLES_PER_PUSH, 422);
            return;
        }

        $vehicleModel = new Vehicle();
        $results = $vehicleModel->batchUpdateOdometers($userId, $vehicles);

        // Update sync status
        $db = Database::getInstance();
        $deviceId = $request->getHeader('x-device-id');
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        $totalMiles = array_sum(array_map(fn($r) => $r['increase'] ?? 0, array_filter($results, fn($r) => $r['success'])));

        $existing = $db->fetchOne(
            "SELECT id FROM api_sync_status WHERE user_id = ? AND device_id <=> ?",
            [$userId, $deviceId]
        );

        if ($existing) {
            $db->execute(
                "UPDATE api_sync_status SET 
                    last_sync_push = NOW(), 
                    vehicles_synced = vehicles_synced + ?,
                    total_miles_synced = total_miles_synced + ?,
                    sync_count = sync_count + 1,
                    last_error = NULL
                 WHERE id = ?",
                [$successCount, $totalMiles, $existing['id']]
            );
        } else {
            $db->insert('api_sync_status', [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'last_sync_push' => date('Y-m-d H:i:s'),
                'vehicles_synced' => $successCount,
                'total_miles_synced' => $totalMiles,
                'sync_count' => 1,
            ]);
        }

        $allSuccess = count(array_filter($results, fn($r) => !$r['success'])) === 0;

        Response::success([
            'synced'  => $successCount,
            'total'   => count($vehicles),
            'miles'   => $totalMiles,
            'results' => $results,
        ], $allSuccess ? 200 : 207);
    }
}
