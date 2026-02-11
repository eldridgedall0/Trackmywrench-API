<?php
namespace GarageMinder\API\Endpoints\Auth;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, JWTHandler, Validator};
use GarageMinder\API\Models\User;

class LoginEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->getMethod() !== 'POST') {
            Response::error('Method not allowed', 405);
            return;
        }

        // Validate input
        $v = new Validator();
        $v->required('username', $request->getBody('username'))
          ->required('password', $request->getBody('password'));
        $v->throwIfFailed();

        $username = Validator::sanitize($request->getBody('username'));
        $password = $request->getBody('password'); // Don't sanitize password

        $userModel = new User();
        $user = $userModel->findByLogin($username);

        if (!$user) {
            Response::error('Invalid username or password.', 401, 'INVALID_CREDENTIALS');
            return;
        }

        if (!$userModel->verifyPassword($password, $user['password_hash'])) {
            Response::error('Invalid username or password.', 401, 'INVALID_CREDENTIALS');
            return;
        }

        $userId = (int) $user['id'];

        // Create tokens
        $jwt = new JWTHandler();
        $accessToken = $jwt->createAccessToken($userId, [
            'username' => $user['username'],
        ]);

        $refreshToken = $jwt->createRefreshToken(
            $userId,
            $request->getBody('device_id'),
            $request->getBody('device_name'),
            $request->getBody('platform'),
            $request->getIpAddress(),
            $request->getUserAgent()
        );

        // Get subscription level
        $subscriptionLevel = $userModel->getSubscriptionLevel($userId);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => JWT_ACCESS_TOKEN_EXPIRY,
            'user' => [
                'id'                 => $userId,
                'username'           => $user['username'],
                'email'              => $user['email'],
                'display_name'       => $user['display_name'],
                'subscription_level' => $subscriptionLevel,
            ],
        ]);
    }
}
