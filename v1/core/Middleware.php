<?php
/**
 * GarageMinder Mobile API - Middleware Base
 */

namespace GarageMinder\API\Core;

abstract class Middleware
{
    /**
     * Handle the request. Call $next() to continue pipeline.
     */
    abstract public function handle(Request $request, callable $next): void;
}
