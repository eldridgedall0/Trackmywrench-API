<?php
namespace GarageMinder\API\Endpoints\Sync;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Validator};
use GarageMinder\API\Models\Device;

class DeviceEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();

        $v = new Validator();
        $v->required('device_id', $request->getBody('device_id'))
          ->required('platform', $request->getBody('platform'))
          ->inArray('platform', $request->getBody('platform'), ['android', 'ios']);
        $v->throwIfFailed();

        $deviceModel = new Device();
        $result = $deviceModel->registerDevice($userId, $request->getBody());

        Response::success($result, $result['status'] === 'registered' ? 201 : 200);
    }
}
