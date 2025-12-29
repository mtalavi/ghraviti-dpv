<?php
/**
 * Response - HTTP Response Helper
 * 
 * Provides methods for sending responses (HTML, JSON, redirects).
 * 
 * Usage:
 *   $response = new Response();
 *   $response->json(['status' => 'ok']);
 *   $response->redirect('/dashboard');
 */

class Response
{
    private int $statusCode = 200;
    private array $headers = [];

    /**
     * Set HTTP status code
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Add a header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Send JSON response
     */
    public function json(array $data, ?int $statusCode = null): void
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }

        http_response_code($this->statusCode);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send redirect response
     */
    public function redirect(string $url, int $statusCode = 302): void
    {
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit;
    }

    /**
     * Send HTML response
     */
    public function html(string $content, ?int $statusCode = null): void
    {
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }

        http_response_code($this->statusCode);
        header('Content-Type: text/html; charset=utf-8');

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        echo $content;
        exit;
    }

    /**
     * Send error response
     */
    public function error(string $message, int $statusCode = 500): void
    {
        $this->json(['error' => $message], $statusCode);
    }

    /**
     * Send 404 Not Found
     */
    public function notFound(string $message = 'Not Found'): void
    {
        $this->error($message, 404);
    }

    /**
     * Send 403 Forbidden
     */
    public function forbidden(string $message = 'Forbidden'): void
    {
        $this->error($message, 403);
    }

    /**
     * Send 401 Unauthorized
     */
    public function unauthorized(string $message = 'Unauthorized'): void
    {
        $this->error($message, 401);
    }
}
