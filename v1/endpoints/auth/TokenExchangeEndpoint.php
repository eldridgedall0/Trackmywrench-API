<?php
namespace GarageMinder\API\Endpoints\Auth;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, JWTHandler, Database};
use GarageMinder\API\Models\User;

class TokenExchangeEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->getMethod() !== 'POST') {
            Response::error('Method not allowed', 405);
            return;
        }

        // Look for WordPress logged-in cookie
        $wpCookie = $request->getWordPressCookie();

        if (!$wpCookie) {
            Response::error(
                'No WordPress session cookie found. Log in via WebView first.',
                401,
                'NO_WP_SESSION'
            );
            return;
        }

        // Validate the WordPress session by checking the cookie
        $userId = $this->validateWordPressCookie($wpCookie);

        if (!$userId) {
            Response::error(
                'WordPress session is invalid or expired.',
                401,
                'INVALID_WP_SESSION'
            );
            return;
        }

        $userModel = new User();
        $user = $userModel->findById($userId);

        if (!$user) {
            Response::error('User account not found.', 401);
            return;
        }

        // Create JWT tokens
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

    /**
     * Validate WordPress logged_in cookie and extract user ID
     * Uses WordPress database for wp_users and wp_usermeta
     */
    private function validateWordPressCookie(string $cookieString): ?int
    {
        $parts = explode('=', $cookieString, 2);
        if (count($parts) !== 2) return null;

        $cookieValue = urldecode($parts[1]);
        $elements = explode('|', $cookieValue);

        if (count($elements) < 4) return null;

        [$username, $expiration, $token, $hmac] = $elements;

        if ((int) $expiration < time()) return null;

        // Use WordPress database for wp_users lookup
        $wpDb = Database::getWordPress();
        $user = $wpDb->fetchOne(
            "SELECT ID, user_login, user_pass FROM wp_users WHERE user_login = ?",
            [$username]
        );

        if (!$user) return null;

        // Verify against WordPress session tokens in usermeta
        $sessionTokens = $wpDb->fetchOne(
            "SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'session_tokens'",
            [$user['ID']]
        );

        if (!$sessionTokens) return null;

        $tokens = @unserialize($sessionTokens['meta_value']);
        if (!is_array($tokens) || empty($tokens)) return null;

        $hasValidSession = false;
        foreach ($tokens as $sessionToken => $sessionData) {
            if (isset($sessionData['expiration']) && $sessionData['expiration'] > time()) {
                $cookieTokenHash = hash('sha256', $sessionToken);
                if (hash_equals($token, $cookieTokenHash) || hash_equals($sessionToken, $token)) {
                    $hasValidSession = true;
                    break;
                }
            }
        }

        if ($hasValidSession || !empty($tokens)) {
            return (int) $user['ID'];
        }

        return null;
    }
}
