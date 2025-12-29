<?php
/**
 * API Controller
 * 
 * Handles API endpoints for mobile apps or AJAX requests.
 * Returns JSON responses.
 * 
 * Routes:
 *   GET /api/user     - Get current user
 *   GET /api/events   - Get events list
 *   POST /api/login   - Authenticate user
 */

require_once __DIR__ . '/../core/Controller.php';

class ApiController extends Controller
{
    /**
     * Get current authenticated user
     */
    public function getUser(Request $request, array $params): void
    {
        $this->requireAuth();

        $user = $this->user();

        // Remove sensitive fields
        unset($user['password_hash']);

        $this->json([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'dp_code' => $user['dp_code'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
            ]
        ]);
    }

    /**
     * API Login
     */
    public function login(Request $request, array $params): void
    {
        if (!$request->isPost()) {
            $this->response->error('Method not allowed', 405);
        }

        $dpCode = strtoupper(trim($request->post('dp_code', '')));
        $password = $request->post('password', '');

        if (empty($dpCode) || empty($password)) {
            $this->json(['success' => false, 'error' => 'DP code and password required'], 400);
        }

        $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code = ?', [$dpCode]);

        if (!$user || !password_verify($password, $user['password_hash'] ?? '')) {
            $this->json(['success' => false, 'error' => 'Invalid credentials'], 401);
        }

        // Set session
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        log_action('api_login', 'user', $user['id']);

        $this->json([
            'success' => true,
            'redirect' => get_role_redirect('after_login', $user['role']),
            'user' => [
                'id' => $user['id'],
                'dp_code' => $user['dp_code'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
            ]
        ]);
    }

    /**
     * Get events list
     */
    public function getEvents(Request $request, array $params): void
    {
        $this->requireAuth();

        $events = fetch_all('SELECT id, name, location, start_datetime, public_slug FROM events ORDER BY start_datetime DESC LIMIT 50');

        $this->json([
            'success' => true,
            'events' => $events
        ]);
    }

    /**
     * Get single event
     */
    public function getEvent(Request $request, array $params): void
    {
        $this->requireAuth();

        $id = (int) ($params['id'] ?? 0);

        $event = fetch_one('SELECT * FROM events WHERE id = ?', [$id]);

        if (!$event) {
            $this->response->notFound('Event not found');
        }

        $this->json([
            'success' => true,
            'event' => $event
        ]);
    }
}
