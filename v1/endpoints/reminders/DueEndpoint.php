<?php
namespace GarageMinder\API\Endpoints\Reminders;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\Reminder;

class DueEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $windowDays = (int) ($request->getQuery('days') ?? REMINDERS_DUE_WINDOW_DAYS);

        $reminderModel = new Reminder();
        $reminders = $reminderModel->getDueByUser($userId, $windowDays);

        $overdue = array_filter($reminders, fn($r) => ($r['is_overdue'] ?? false));

        Response::success([
            'window_days'   => $windowDays,
            'total'         => count($reminders),
            'overdue_count' => count($overdue),
            'reminders'     => $reminders,
        ]);
    }
}
