<?php
namespace GarageMinder\API\Middleware;

use GarageMinder\API\Core\{Middleware, Request, Response, Database};

class AdminMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): void
    {
        if (!$request->isAuthenticated()) {
            Response::error('Authentication required.', 401);
            return;
        }

        $userId = $request->getAuthenticatedUserId();
        $wpDb = Database::getWordPress();
        $table = Database::wpTable('usermeta');
        $capKey = WP_TABLE_PREFIX . 'capabilities';

        // Check WordPress usermeta for administrator role
        $meta = $wpDb->fetchOne(
            "SELECT meta_value FROM `{$table}` WHERE user_id = ? AND meta_key = ?",
            [$userId, $capKey]
        );

        if (!$meta) {
            Response::error('Access denied. Admin privileges required.', 403, 'ADMIN_REQUIRED');
            return;
        }

        $capabilities = @unserialize($meta['meta_value']);

        if (!is_array($capabilities) || !isset($capabilities[ADMIN_ROLE])) {
            Response::error('Access denied. Admin privileges required.', 403, 'ADMIN_REQUIRED');
            return;
        }

        $next();
    }
}
