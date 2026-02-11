<?php
namespace GarageMinder\API\Middleware;

use GarageMinder\API\Core\{Middleware, Request, Response, JWTHandler, Database};

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): void
    {
        $token = $request->getBearerToken();

        if (!$token) {
            Response::error('Authentication required. Provide a Bearer token.', 401);
            return;
        }

        $jwt = new JWTHandler();
        $payload = $jwt->validateAccessToken($token);

        if (!$payload) {
            Response::error('Invalid or expired access token. Use /auth/refresh to get a new one.', 401, 'TOKEN_EXPIRED');
            return;
        }

        $userId = (int) $payload['sub'];

        // Fetch user data from WordPress users table
        $db = Database::getInstance();
        $user = $db->fetchOne(
            "SELECT ID, user_login, user_email, display_name 
             FROM wp_users WHERE ID = ?",
            [$userId]
        );

        if (!$user) {
            Response::error('User account not found.', 401, 'USER_NOT_FOUND');
            return;
        }

        // Set authenticated context on request
        $request->setAuthenticatedUser($userId, [
            'id'           => (int) $user['ID'],
            'username'     => $user['user_login'],
            'email'        => $user['user_email'],
            'display_name' => $user['display_name'],
        ]);

        $next();
    }
}
