<?php
namespace GarageMinder\API\Endpoints\Admin;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database};

class TestEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        // Return all registered routes for the admin tester
        if ($request->getMethod() === 'GET') {
            Response::success([
                'message' => 'Admin API Tester active.',
                'admin_user' => $request->getAuthenticatedUser(),
            ]);
            return;
        }

        // POST: execute a test request (returns data without restrictions)
        // This allows admins to test any endpoint behavior
        Response::success([
            'message' => 'Test endpoint. Use the admin tester dashboard for full testing.',
        ]);
    }
}
