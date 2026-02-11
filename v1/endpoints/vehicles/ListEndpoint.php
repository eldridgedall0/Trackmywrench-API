<?php
namespace GarageMinder\API\Endpoints\Vehicles;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Vehicle;

class ListEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $vehicleModel = new Vehicle();
        $vehicles = $vehicleModel->getByUser($userId);
        Response::success($vehicles);
    }
}
