<?php
namespace GarageMinder\API\Endpoints\Auth;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, JWTHandler};

class LogoutEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $jwt = new JWTHandler();
        $refreshToken = $request->getBody('refresh_token');

        if ($refreshToken) {
            $jwt->revokeRefreshToken($refreshToken);
        }

        // Optionally revoke ALL tokens for this user
        if ($request->getBody('all_devices') === true) {
            $userId = $request->getAuthenticatedUserId();
            if ($userId) {
                $jwt->revokeAllUserTokens($userId);
            }
        }

        Response::success(['message' => 'Logged out successfully.']);
    }
}
