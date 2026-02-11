<?php
namespace GarageMinder\API\Endpoints\Vehicles;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Vehicle;

class DetailEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicleId = (int) $request->getRouteParam('id');

        $vehicleModel = new Vehicle();
        $vehicle = $vehicleModel->getById($vehicleId, $userId);

        if (!$vehicle) {
            Response::error('Vehicle not found.', 404);
            return;
        }

        Response::success($vehicle);
    }
}
