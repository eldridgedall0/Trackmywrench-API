<?php
namespace GarageMinder\API\Endpoints\Admin;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database};
use GarageMinder\API\Models\User;

class UsersEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $page = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page') ?? 50)));
        $offset = ($page - 1) * $perPage;

        $userModel = new User();
        $users = $userModel->getAllUsers($perPage, $offset);
        $total = $userModel->countUsers();

        // Enrich with subscription info
        foreach ($users as &$user) {
            $user['subscription_level'] = $userModel->getSubscriptionLevel((int)$user['id']);
        }

        Response::paginated($users, $total, $page, $perPage);
    }
}
