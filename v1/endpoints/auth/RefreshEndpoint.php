<?php
namespace GarageMinder\API\Endpoints\Auth;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, JWTHandler, Validator};

class RefreshEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $v = new Validator();
        $v->required('refresh_token', $request->getBody('refresh_token'));
        $v->throwIfFailed();

        $jwt = new JWTHandler();
        $result = $jwt->rotateRefreshToken(
            $request->getBody('refresh_token'),
            $request->getBody('device_id'),
            $request->getBody('device_name'),
            $request->getBody('platform'),
            $request->getIpAddress(),
            $request->getUserAgent()
        );

        if (!$result) {
            Response::error('Invalid or expired refresh token. Please log in again.', 401, 'REFRESH_FAILED');
            return;
        }

        Response::success([
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => JWT_ACCESS_TOKEN_EXPIRY,
        ]);
    }
}
