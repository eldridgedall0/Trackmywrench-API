<?php
namespace GarageMinder\API\Middleware;

use GarageMinder\API\Core\{Middleware, Request, Response, RateLimiter};

class RateLimitMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): void
    {
        $limiter = new RateLimiter();

        // Check IP-based rate limit
        $ipResult = $limiter->check($request->getIpAddress(), 'ip', $request->getPath());
        $limiter->setHeaders($ipResult);

        if (!$ipResult['allowed']) {
            Response::error(
                'Rate limit exceeded. Please slow down.',
                429,
                'RATE_LIMITED',
                ['retry_after' => max(1, $ipResult['reset'] - time())]
            );
            return;
        }

        // If authenticated, also check user-based limit
        if ($request->isAuthenticated()) {
            $userResult = $limiter->check(
                (string) $request->getAuthenticatedUserId(),
                'user',
                $request->getPath()
            );

            if (!$userResult['allowed']) {
                $limiter->setHeaders($userResult);
                Response::error(
                    'Rate limit exceeded for your account.',
                    429,
                    'RATE_LIMITED',
                    ['retry_after' => max(1, $userResult['reset'] - time())]
                );
                return;
            }
        }

        $next();
    }
}
