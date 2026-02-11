<?php
namespace GarageMinder\API\Endpoints\Admin;

use GarageMinder\API\Endpoints\BaseEndpoint;
use GarageMinder\API\Core\{Request, Response, Database};

class LogsEndpoint extends BaseEndpoint
{
    public function handle(Request $request): void
    {
        $db = Database::getInstance();
        $page = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = min(200, max(1, (int) ($request->getQuery('per_page') ?? 50)));
        $offset = ($page - 1) * $perPage;

        // Filters
        $where = ['1=1'];
        $params = [];

        if ($userId = $request->getQuery('user_id')) {
            $where[] = 'user_id = ?';
            $params[] = (int) $userId;
        }
        if ($endpoint = $request->getQuery('endpoint')) {
            $where[] = 'endpoint LIKE ?';
            $params[] = '%' . $endpoint . '%';
        }
        if ($statusCode = $request->getQuery('status_code')) {
            $where[] = 'status_code = ?';
            $params[] = (int) $statusCode;
        }
        if ($method = $request->getQuery('method')) {
            $where[] = 'method = ?';
            $params[] = strtoupper($method);
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM api_request_log WHERE {$whereClause}",
            $params
        );

        $logs = $db->fetchAll(
            "SELECT * FROM api_request_log WHERE {$whereClause} 
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        Response::paginated($logs, $total, $page, $perPage);
    }
}
