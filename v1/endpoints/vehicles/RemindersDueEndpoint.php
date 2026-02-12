<?php
namespace GarageMinder\API\Endpoints\Vehicles;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Reminder;

class RemindersDueEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicleId = $request->getRouteParam('id'); // String ID
        $windowDays = (int) ($request->getQuery('days') ?? REMINDERS_DUE_WINDOW_DAYS);

        $reminderModel = new Reminder();
        $reminders = $reminderModel->getDueByVehicle($vehicleId, $userId, $windowDays);

        Response::success([
            'vehicle_id' => $vehicleId,
            'window_days' => $windowDays,
            'count' => count($reminders),
            'reminders' => $reminders,
        ]);
    }
}
