<?php
namespace GarageMinder\API\Endpoints\Subscription;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\User;

class StatusEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $userModel = new User();
        $level = $userModel->getSubscriptionLevel($userId);

        $features = [
            'manual_sync'     => true,
            'background_sync' => true,
            'auto_sync'       => ($level === 'paid'),
            'trip_tracking'   => true,
            'vehicle_management' => true,
            'odometer_adjustment' => true,
            'reminders'       => true,
        ];

        Response::success([
            'subscription_level' => $level,
            'features' => $features,
        ]);
    }
}
