<?php
/**
 * Authentication & Authorization Helper Functions
 * 
 * Contains: User session management, role checks, permissions,
 * and brute-force protection (throttling).
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// USER SESSION
// =====================================================

/**
 * Get the current logged-in user with decrypted data.
 * Results are cached for the request lifetime.
 * 
 * @return array|null User data or null if not logged in
 */
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $cache = null;
    if ($cache === null) {
        // Use decryption for sensitive fields
        $cache = function_exists('fetch_user_decrypted')
            ? fetch_user_decrypted('SELECT * FROM users WHERE id=?', [$_SESSION['uid']])
            : fetch_one('SELECT * FROM users WHERE id=?', [$_SESSION['uid']]);
    }
    return $cache ?: null;
}

/**
 * Require user to be logged in, redirect to login if not.
 * 
 * @param string $redirectTo Optional custom redirect after login
 */
function require_login(string $redirectTo = ''): void
{
    if (empty($_SESSION['uid'])) {
        $dest = BASE_URL . '/auth/login.php';
        if ($redirectTo) {
            $dest .= '?redirect=' . urlencode($redirectTo);
        }
        redirect($dest);
    }
}

// =====================================================
// ROLE CHECKS
// =====================================================

/**
 * Check if current user has a specific role.
 * Super admins pass all role checks.
 * 
 * @param string $role Role to check
 * @return bool True if user has role
 */
function has_role(string $role): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    if ($u['role'] === 'super_admin') {
        return true;
    }
    return $u['role'] === $role;
}

/**
 * Require user to have specified role(s), exit with 403 if not.
 * Super admins bypass all role checks.
 * 
 * @param string|array $roles Required role(s)
 */
function require_role($roles): void
{
    $roles = (array) $roles;
    $u = current_user();
    if (!$u) {
        redirect(BASE_URL . '/auth/login.php');
    }
    if ($u['role'] === 'super_admin') {
        return;
    }
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

/**
 * Check if current user is a super admin.
 * @return bool True if super admin
 */
function is_super_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'super_admin';
}

/**
 * Get the dashboard/home URL for the current user.
 * - Super admins -> /admin/dashboard.php
 * - Everyone else (admin, user) -> /user/dashboard.php
 * 
 * @return string Full dashboard URL
 */
function dashboard_url(): string
{
    $u = current_user();
    if (!$u) {
        return BASE_URL . '/user/dashboard.php';
    }

    // Super admin goes to admin dashboard
    if ($u['role'] === 'super_admin') {
        return BASE_URL . '/admin/dashboard.php';
    }

    // Admin and user both go to user dashboard
    return BASE_URL . '/user/dashboard.php';
}

// =====================================================
// GRANULAR PERMISSIONS (for admins)
// =====================================================

/**
 * Get all permissions for a user.
 * Results are cached per request.
 * 
 * @param int $userId User ID
 * @return array Associative array of permission_key => allowed
 */
function permissions_for_user(int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $rows = fetch_all("SELECT permission_key, allowed FROM admin_permissions WHERE user_id=?", [$userId]);
    $map = [];
    foreach ($rows as $r) {
        $map[$r['permission_key']] = (bool) $r['allowed'];
    }
    // Default perms for admins: manage_events only, if nothing is set.
    if (!$rows) {
        foreach (default_admin_permissions() as $k) {
            $map[$k] = true;
        }
    }
    $cache[$userId] = $map;
    return $map;
}

/**
 * Require a specific permission, exit with 403 if not allowed.
 * Super admins bypass all permission checks.
 * 
 * @param string $perm Permission key
 */
function require_permission(string $perm): void
{
    $u = current_user();
    if (!$u) {
        redirect(BASE_URL . '/auth/login.php');
    }
    if ($u['role'] === 'super_admin') {
        return;
    }
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    $perms = permissions_for_user((int) $u['id']);
    if (!array_key_exists($perm, $perms)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if ($perms[$perm]) {
        return;
    }
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Check if current user has a specific permission.
 * Super admins always return true.
 * 
 * @param string $perm Permission key
 * @return bool True if allowed
 */
function has_permission(string $perm): bool
{
    $u = current_user();
    if (!$u)
        return false;
    if ($u['role'] === 'super_admin')
        return true;
    if ($u['role'] !== 'admin')
        return false;
    $perms = permissions_for_user((int) $u['id']);
    if (!array_key_exists($perm, $perms)) {
        return false;
    }
    return (bool) $perms[$perm];
}

/**
 * Get default permissions for new admins.
 * @return array List of default permission keys
 */
function default_admin_permissions(): array
{
    return ['manage_events'];
}

/**
 * Save admin permissions (replace all).
 * 
 * @param int $userId User ID
 * @param array $allKeys All possible permission keys
 * @param array $allowedKeys Keys that should be allowed
 */
function save_admin_permissions(int $userId, array $allKeys, array $allowedKeys): void
{
    $pdo = db();
    $pdo->beginTransaction();
    execute_query('DELETE FROM admin_permissions WHERE user_id=?', [$userId]);
    $ins = $pdo->prepare('INSERT INTO admin_permissions (user_id, permission_key, allowed) VALUES (?,?,?)');
    foreach ($allKeys as $key) {
        $ins->execute([$userId, $key, in_array($key, $allowedKeys, true) ? 1 : 0]);
    }
    $pdo->commit();
}

// =====================================================
// BRUTE-FORCE PROTECTION (File-based Throttling)
// =====================================================

/**
 * Check and increment throttle attempt count.
 * SECURITY: File-based to work even if session is reset.
 * 
 * @param string $key Unique key (e.g., 'login_<ip>')
 * @param int $max Maximum attempts allowed
 * @param int $seconds Time window in seconds
 * @return bool True if throttled (exceeded max), false if allowed
 */
function throttle_attempt(string $key, int $max, int $seconds): bool
{
    $throttleFile = sys_get_temp_dir() . '/dpv_throttle_' . sha1($key) . '.json';
    $now = time();

    // Read existing throttle data
    $data = ['window' => $now, 'count' => 0];
    if (file_exists($throttleFile)) {
        $raw = @file_get_contents($throttleFile);
        if ($raw) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }
    }

    // Reset if window expired
    if ($now > ($data['window'] ?? 0) + $seconds) {
        $data = ['window' => $now, 'count' => 0];
    }

    // Increment count
    $data['count'] = ($data['count'] ?? 0) + 1;

    // Write back
    @file_put_contents($throttleFile, json_encode($data));

    // Clean up old throttle files periodically (1% chance per request)
    if (mt_rand(1, 100) === 1) {
        throttle_cleanup($seconds);
    }

    return $data['count'] > $max;
}

/**
 * Clean up old throttle files.
 * @param int $maxAge Maximum age in seconds
 */
function throttle_cleanup(int $maxAge): void
{
    $pattern = sys_get_temp_dir() . '/dpv_throttle_*.json';
    $now = time();
    foreach (glob($pattern) as $file) {
        if ($now - filemtime($file) > $maxAge * 2) {
            @unlink($file);
        }
    }
}
