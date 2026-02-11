<?php
namespace GarageMinder\API\Middleware;

use GarageMinder\API\Core\{Middleware, Request, Logger};

class LoggingMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): void
    {
        // Logger starts timing on construction
        $logger = new Logger();

        // Store logger in global scope for post-response logging
        $GLOBALS['_api_logger'] = $logger;
        $GLOBALS['_api_request'] = $request;

        // Register shutdown function to log after response is sent
        register_shutdown_function(function() {
            $logger = $GLOBALS['_api_logger'] ?? null;
            $request = $GLOBALS['_api_request'] ?? null;
            if ($logger && $request) {
                $logger->log(
                    $request,
                    http_response_code() ?: 200,
                    null,
                    null
                );
            }
        });

        $next();
    }
}
