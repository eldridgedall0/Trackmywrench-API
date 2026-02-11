<?php
namespace GarageMinder\API\Endpoints\Vehicles;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Validator};
use GarageMinder\API\Models\Vehicle;

class OdometerEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicleId = (int) $request->getRouteParam('id');
        $newOdometer = (int) $request->getBody('odometer');

        $v = new Validator();
        $v->required('odometer', $request->getBody('odometer'))
          ->integer('odometer', $newOdometer, 0, 999999);
        $v->throwIfFailed();

        $vehicleModel = new Vehicle();
        $vehicle = $vehicleModel->getById($vehicleId, $userId);

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
            return;
        }

        if ($newOdometer < $vehicle['odometer']) {
            Response::error(
                "New odometer ({$newOdometer}) cannot be less than current ({$vehicle['odometer']}).",
                422,
                'ODOMETER_DECREASE'
            );
            return;
        }

        $increase = $newOdometer - $vehicle['odometer'];
        if ($increase > SYNC_MAX_ODOMETER_JUMP) {
            Response::error(
                "Odometer increase of {$increase} miles exceeds maximum allowed.",
                422,
                'ODOMETER_JUMP_TOO_LARGE'
            );
            return;
        }

        $success = $vehicleModel->updateOdometer($vehicleId, $userId, $newOdometer);

        if (!$success) {
            Response::error('Failed to update odometer.', 500);
            return;
        }

        Response::success([
            'vehicle_id'        => $vehicleId,
            'previous_odometer' => $vehicle['odometer'],
            'new_odometer'      => $newOdometer,
            'increase'          => $increase,
        ]);
    }
}
