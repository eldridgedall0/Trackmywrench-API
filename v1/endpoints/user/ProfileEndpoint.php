<?php
namespace GarageMinder\API\Endpoints\User;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response};
use GarageMinder\API\Models\{User, Token};

class ProfileEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $userId = $request->getAuthenticatedUserId();
        $userModel = new User();
        $profile = $userModel->getProfile($userId);

        if (!$profile) {
            Response::error('User not found.', 404);
            return;
        }

        $tokenModel = new Token();
        $profile['active_sessions'] = $tokenModel->countActiveSessions($userId);

        Response::success($profile);
    }
}
