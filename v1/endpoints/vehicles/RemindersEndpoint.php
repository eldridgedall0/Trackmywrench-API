<?php
namespace GarageMinder\API\Endpoints\Vehicles;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Reminder;

class RemindersEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicleId = (int) $request->getRouteParam('id');

        $reminderModel = new Reminder();
        $reminders = $reminderModel->getByVehicle($vehicleId, $userId);

        if ($reminders === [] && !$this->vehicleExists($vehicleId, $userId)) {
            Response::error('Vehicle not found.', 404);
            return;
        }

        Response::success($reminders);
    }

    private function vehicleExists(int $vehicleId, int $userId): bool
    {
        $db = \GarageMinder\API\Core\Database::getInstance();
        return (bool) $db->fetchOne(
            "SELECT id FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );
    }
}
