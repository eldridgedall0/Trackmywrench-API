<?php
namespace GarageMinder\API\Endpoints\Vehicles;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database};
use GarageMinder\API\Models\Reminder;

class RemindersEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicleId = $request->getRouteParam('id'); // String ID

        $reminderModel = new Reminder();
        $reminders = $reminderModel->getByVehicle($vehicleId, $userId);

        if ($reminders === [] && !$this->vehicleExists($vehicleId, $userId)) {
            Response::error('Vehicle not found.', 404);
            return;
        }

        Response::success($reminders);
    }

    private function vehicleExists(string $vehicleId, int $userId): bool
    {
        $db = Database::getInstance();
        $result = $db->fetchOne(
            "SELECT id FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );
        return $result !== null;
    }
}
