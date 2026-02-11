<?php
namespace GarageMinder\API\Middleware;

use GarageMinder\API\Core\{Middleware, Request, Response};

class CorsMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): void
    {
        $origin = $request->getHeader('origin');

        // Mobile apps don't send Origin header - allow them through
        if ($origin === null) {
            $this->setSecurityHeaders();
            $next();
            return;
        }

        // Check allowed origins
        if (in_array($origin, CORS_ALLOWED_ORIGINS)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Id, X-App-Version');
        header('Access-Control-Max-Age: ' . CORS_MAX_AGE);

        if (CORS_ALLOW_CREDENTIALS) {
            header('Access-Control-Allow-Credentials: true');
        }

        $this->setSecurityHeaders();

        // Handle preflight
        if ($request->getMethod() === 'OPTIONS') {
            Response::success(null, 204);
            return;
        }

        $next();
    }

    private function setSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
