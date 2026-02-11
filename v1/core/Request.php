<?php
/**
 * GarageMinder Mobile API - Request Wrapper
 * 
 * Encapsulates HTTP request data with convenience methods.
 */

namespace GarageMinder\API\Core;

class Request
{
    private string $method;
    private string $uri;
    private string $path;
    private array $headers;
    private array $query;
    private array $body;
    private ?string $rawBody;
    private array $routeParams = [];
    private ?int $authenticatedUserId = null;
    private ?array $authenticatedUser = null;
    private ?string $bearerToken = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = $this->parsePath();
        $this->headers = $this->parseHeaders();
        $this->query = $_GET;
        $this->rawBody = file_get_contents('php://input') ?: null;
        $this->body = $this->parseBody();
        $this->bearerToken = $this->extractBearerToken();
    }

    private function parsePath(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH) ?? '/';
        // Remove API prefix
        $prefix = API_PREFIX;
        if (strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
        }
        // Ensure leading slash, remove trailing
        $path = '/' . trim($path, '/');
        return $path;
    }

    private function parseHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    private function parseBody(): array
    {
        if ($this->rawBody === null) {
            return [];
        }

        $contentType = $this->getHeader('content-type') ?? '';

        if (strpos($contentType, 'application/json') !== false) {
            $decoded = json_decode($this->rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($this->rawBody, $parsed);
            return $parsed;
        }

        return [];
    }

    private function extractBearerToken(): ?string
    {
        $auth = $this->getHeader('authorization') ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // === Getters ===

    public function getMethod(): string { return $this->method; }
    public function getUri(): string { return $this->uri; }
    public function getPath(): string { return $this->path; }
    public function getRawBody(): ?string { return $this->rawBody; }
    public function getBearerToken(): ?string { return $this->bearerToken; }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getQuery(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    public function getBody(string $key = null, $default = null)
    {
        if ($key === null) return $this->body;
        return $this->body[$key] ?? $default;
    }

    public function getRouteParam(string $key, $default = null)
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function getIpAddress(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }
        return '0.0.0.0';
    }

    public function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    // === Authentication Context ===

    public function setAuthenticatedUser(int $userId, array $userData = []): void
    {
        $this->authenticatedUserId = $userId;
        $this->authenticatedUser = $userData;
    }

    public function getAuthenticatedUserId(): ?int
    {
        return $this->authenticatedUserId;
    }

    public function getAuthenticatedUser(): ?array
    {
        return $this->authenticatedUser;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticatedUserId !== null;
    }

    // === Cookie Access (for token-exchange) ===

    public function getCookie(string $name): ?string
    {
        return $_COOKIE[$name] ?? null;
    }

    public function getWordPressCookie(): ?string
    {
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'wordpress_logged_in_') === 0) {
                return $name . '=' . $value;
            }
        }
        return null;
    }

    // === Validation Helpers ===

    public function requireBody(array $requiredFields): array
    {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($this->body[$field]) || $this->body[$field] === '') {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new \InvalidArgumentException(
                'Missing required fields: ' . implode(', ', $missing)
            );
        }

        return array_intersect_key($this->body, array_flip($requiredFields));
    }
}
