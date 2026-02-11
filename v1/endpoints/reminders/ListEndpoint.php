<?php
namespace GarageMinder\API\Endpoints\Reminders;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Reminder;

class ListEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $reminderModel = new Reminder();
        Response::success($reminderModel->getByUser($userId));
    }
}
