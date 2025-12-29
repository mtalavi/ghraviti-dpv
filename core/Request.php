<?php
/**
 * Request - HTTP Request Wrapper
 * 
 * Provides clean access to GET, POST, and other request data.
 * 
 * Usage:
 *   $request = new Request();
 *   $email = $request->post('email');
 *   $page = $request->get('page', 1);
 */

class Request
{
    private array $query;
    private array $post;
    private array $server;
    private array $files;

    public function __construct()
    {
        $this->query = $_GET;
        $this->post = $_POST;
        $this->server = $_SERVER;
        $this->files = $_FILES;
    }

    /**
     * Get a value from query string ($_GET)
     */
    public function get(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get a value from POST data
     */
    public function post(string $key, $default = null)
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Get a value from either GET or POST
     */
    public function input(string $key, $default = null)
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * Get all POST data
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /**
     * Check if a key exists in POST
     */
    public function has(string $key): bool
    {
        return isset($this->post[$key]) || isset($this->query[$key]);
    }

    /**
     * Get the HTTP method (GET, POST, etc.)
     */
    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    /**
     * Check if request is POST
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Check if request is GET
     */
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    /**
     * Get the request URI path (without query string)
     */
    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $path ?: '/';
    }

    /**
     * Get uploaded file info
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Check if request expects JSON response
     */
    public function wantsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Get a header value
     */
    public function header(string $key, $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$key] ?? $default;
    }

    /**
     * Get client IP address
     */
    public function ip(): string
    {
        if (!empty($this->server['HTTP_CF_CONNECTING_IP'])) {
            return $this->server['HTTP_CF_CONNECTING_IP'];
        }
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
