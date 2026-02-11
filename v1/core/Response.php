<?php
/**
 * GarageMinder Mobile API - Response Builder
 * 
 * Standardized JSON response format for all API endpoints.
 * Every response follows: { success, data, error, meta }
 */

namespace GarageMinder\API\Core;

class Response
{
    /**
     * Send a success response
     */
    public static function success($data = null, int $statusCode = 200, array $extraMeta = []): void
    {
        self::send($statusCode, [
            'success' => true,
            'data'    => $data,
            'error'   => null,
            'meta'    => self::buildMeta($extraMeta),
        ]);
    }

    /**
     * Send an error response
     */
    public static function error(
        string $message,
        int $statusCode = 400,
        ?string $errorCode = null,
        $details = null
    ): void {
        self::send($statusCode, [
            'success' => false,
            'data'    => null,
            'error'   => [
                'code'    => $errorCode ?? self::statusToCode($statusCode),
                'message' => $message,
                'details' => $details,
            ],
            'meta' => self::buildMeta(),
        ]);
    }

    /**
     * Send a paginated response
     */
    public static function paginated(
        array $data,
        int $total,
        int $page,
        int $perPage,
        int $statusCode = 200
    ): void {
        self::send($statusCode, [
            'success' => true,
            'data'    => $data,
            'error'   => null,
            'meta'    => self::buildMeta([
                'pagination' => [
                    'total'        => $total,
                    'page'         => $page,
                    'per_page'     => $perPage,
                    'total_pages'  => ceil($total / max($perPage, 1)),
                    'has_more'     => ($page * $perPage) < $total,
                ],
            ]),
        ]);
    }

    /**
     * Send raw JSON and exit
     */
    private static function send(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Build meta block
     */
    private static function buildMeta(array $extra = []): array
    {
        return array_merge([
            'api_version' => API_VERSION,
            'timestamp'   => time(),
        ], $extra);
    }

    /**
     * Map HTTP status to error code string
     */
    private static function statusToCode(int $status): string
    {
        $map = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
        ];
        return $map[$status] ?? 'ERROR';
    }
}
