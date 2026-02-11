<?php
namespace GarageMinder\API\Endpoints\Auth;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\{User, Token};

class VerifyEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $userData = $request->getAuthenticatedUser();

        $userModel = new User();
        $subscriptionLevel = $userModel->getSubscriptionLevel($userId);

        $tokenModel = new Token();
        $activeSessions = $tokenModel->countActiveSessions($userId);

        Response::success([
            'valid' => true,
            'user' => [
                'id'                 => $userId,
                'username'           => $userData['username'] ?? null,
                'email'              => $userData['email'] ?? null,
                'display_name'       => $userData['display_name'] ?? null,
                'subscription_level' => $subscriptionLevel,
            ],
            'active_sessions' => $activeSessions,
        ]);
    }
}
