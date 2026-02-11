<?php
namespace GarageMinder\API\Endpoints\Reminders;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Reminder;

class DetailEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $reminderId = (int) $request->getRouteParam('id');

        $reminderModel = new Reminder();
        $reminder = $reminderModel->getById($reminderId, $userId);

        if (!$reminder) {
            Response::error('Reminder not found.', 404);
            return;
        }

        Response::success($reminder);
    }
}
