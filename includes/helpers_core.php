<?php
/**
 * Core Helper Functions
 * 
 * Contains: CSRF protection, redirect handling, sanitization, flash messages,
 * password generation, and other core utilities.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// CSRF PROTECTION
// =====================================================

/**
 * Generate or retrieve CSRF token for current session.
 * @return string The CSRF token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/**
 * Validate CSRF token using constant-time comparison.
 * @param string $value Token from form submission
 * @return bool True if valid
 */
function csrf_check(string $value): bool
{
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $value);
}

// =====================================================
// REQUEST UTILITIES
// =====================================================

/**
 * Get real client IP address (handles Cloudflare proxy).
 * @return string Client IP address
 */
function getRealIp(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get current application base URL (with protocol).
 * @return string Absolute base URL
 */
function app_base_absolute(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host ? $scheme . '://' . $host . BASE_URL : BASE_URL;
}

// =====================================================
// OUTPUT ESCAPING & SANITIZATION
// =====================================================

/**
 * HTML escape output (XSS protection).
 * @param mixed $value Value to escape
 * @return string Escaped HTML-safe string
 */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// =====================================================
// REDIRECT HANDLING
// =====================================================

/**
 * Redirect to a URL and exit.
 * @param string $path URL or path to redirect to
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Sanitize redirect path to prevent open redirect attacks.
 * Only allows same-site relative redirects.
 * 
 * SECURITY FIX: Now blocks protocol-relative URLs (//example.com) 
 * which could bypass earlier validation.
 * 
 * @param string|null $value User-provided redirect path
 * @return string Safe redirect path or default fallback
 */
function sanitize_redirect_path(?string $value): string
{
    if (empty($value)) {
        return '';
    }

    $value = urldecode(trim($value));

    // CRITICAL: Block protocol-relative URLs (//example.com) and any non-local paths
    if (str_starts_with($value, '//') || !str_starts_with($value, '/')) {
        return '/user/dashboard.php';
    }

    // Block any URL with scheme (http://, javascript:, data:, etc.)
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        return '/user/dashboard.php';
    }

    // Parse URL to extract only the path component (removes any host/scheme that slipped through)
    $path = parse_url($value, PHP_URL_PATH);
    if ($path === null || $path === false) {
        return '/user/dashboard.php';
    }

    // If BASE_URL is set, ensure path starts with it
    if (BASE_URL && strpos($path, BASE_URL . '/') !== 0 && $path !== BASE_URL) {
        return '/user/dashboard.php';
    }

    return $path;
}

// =====================================================
// FILESYSTEM UTILITIES
// =====================================================

/**
 * Ensure a directory exists, create if not.
 * @param string $dir Directory path
 */
function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

// =====================================================
// SESSION & FLASH MESSAGES
// =====================================================

/**
 * Get or set a flash message (one-time session message).
 * 
 * @param string $key Message key
 * @param string|null $message Message to set (null to get)
 * @return string|null The message when getting, null when setting
 */
function flash(string $key, ?string $message = null): ?string
{
    if ($message === null) {
        if (isset($_SESSION['flash'][$key])) {
            $msg = $_SESSION['flash'][$key];
            unset($_SESSION['flash'][$key]);
            return $msg;
        }
        return null;
    }
    $_SESSION['flash'][$key] = $message;
    return null;
}

// =====================================================
// PASSWORD & CODE GENERATION
// =====================================================

/**
 * Generate a random password with mixed characters.
 * @param int $length Password length (default: 12)
 * @return string Generated password
 */
function generate_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
    $chars = str_split($alphabet);
    $pwd = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, count($chars) - 1)];
    }
    return $pwd;
}

/**
 * Generate a numeric code (for OTPs, verification codes).
 * @param int $length Code length (default: 6)
 * @return string Generated numeric code
 */
function generate_numeric_code(int $length = 6): string
{
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= (string) random_int(0, 9);
    }
    return $code;
}
