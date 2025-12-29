<?php
/**
 * Controller - Base Controller Class
 * 
 * All controllers should extend this class.
 * Provides common methods for views, redirects, JSON responses.
 * 
 * Usage:
 *   class AuthController extends Controller {
 *       public function login(Request $request): void {
 *           $this->redirect('/dashboard');
 *       }
 *   }
 */

require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/Response.php';

abstract class Controller
{
    protected Request $request;
    protected Response $response;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    /**
     * Render a view file
     * 
     * @param string $name View name (e.g., 'auth/login')
     * @param array $data Data to pass to view
     */
    protected function view(string $name, array $data = []): void
    {
        // Extract data to make variables available in view
        extract($data);

        $viewPath = __DIR__ . '/../views/' . $name . '.php';

        if (!file_exists($viewPath)) {
            throw new RuntimeException("View not found: $name");
        }

        require $viewPath;
    }

    /**
     * Send JSON response
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        $this->response->json($data, $statusCode);
    }

    /**
     * Redirect to URL
     */
    protected function redirect(string $url): void
    {
        $this->response->redirect($url);
    }

    /**
     * Get current logged-in user
     */
    protected function user(): ?array
    {
        return current_user();
    }

    /**
     * Check if user is logged in
     */
    protected function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Require user to be logged in, redirect to login if not
     */
    protected function requireAuth(): void
    {
        if (!$this->isLoggedIn()) {
            $this->redirect(BASE_URL . '/auth/login.php');
        }
    }

    /**
     * Require specific role
     */
    protected function requireRole(array $roles): void
    {
        $this->requireAuth();
        $user = $this->user();

        if ($user['role'] === 'super_admin') {
            return; // Super admin has all roles
        }

        if (!in_array($user['role'], $roles, true)) {
            $this->response->forbidden();
        }
    }

    /**
     * Require specific permission
     */
    protected function requirePermission(string $permission): void
    {
        $this->requireAuth();

        if (!has_permission($permission)) {
            $this->response->forbidden();
        }
    }

    /**
     * Check CSRF token
     */
    protected function validateCsrf(): bool
    {
        $token = $this->request->post('csrf', '');
        return csrf_check($token);
    }

    /**
     * Get flash message and clear it
     */
    protected function flash(string $key, ?string $message = null)
    {
        return flash($key, $message);
    }
}
