<?php
/**
 * GarageMinder Mobile API - Request Logger
 */

namespace GarageMinder\API\Core;

class Logger
{
    private Database $db;
    private float $startTime;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->startTime = microtime(true);
    }

    /**
     * Log a completed request
     */
    public function log(
        Request $request,
        int $statusCode,
        ?string $responseSummary = null,
        ?string $errorMessage = null
    ): void {
        if (!API_LOG_REQUESTS) return;

        $elapsed = (int) ((microtime(true) - $this->startTime) * 1000);

        $requestBody = null;
        if (API_LOG_BODY && $request->getRawBody()) {
            // Sanitize: remove passwords from logged body
            $body = json_decode($request->getRawBody(), true);
            if (is_array($body)) {
                foreach (['password', 'pwd', 'refresh_token', 'token'] as $sensitive) {
                    if (isset($body[$sensitive])) {
                        $body[$sensitive] = '***REDACTED***';
                    }
                }
                $requestBody = json_encode($body);
            }
        }

        try {
            $this->db->insert('api_request_log', [
                'user_id'          => $request->getAuthenticatedUserId(),
                'method'           => $request->getMethod(),
                'endpoint'         => $request->getPath(),
                'status_code'      => $statusCode,
                'response_time_ms' => $elapsed,
                'request_body'     => $requestBody ? substr($requestBody, 0, 2000) : null,
                'response_summary' => $responseSummary ? substr($responseSummary, 0, 500) : null,
                'ip_address'       => $request->getIpAddress(),
                'user_agent'       => substr($request->getUserAgent(), 0, 500) ?: null,
                'device_id'        => $request->getHeader('x-device-id'),
                'error_message'    => $errorMessage ? substr($errorMessage, 0, 1000) : null,
            ]);
        } catch (\Exception $e) {
            // Logging should never break the API
            if (API_DEBUG) {
                error_log("API Logger error: " . $e->getMessage());
            }
        }
    }
}
