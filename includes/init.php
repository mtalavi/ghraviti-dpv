<?php
// Central bootstrap to load configuration, database, helpers, and shared layout.
require_once __DIR__ . '/../config.php';
@date_default_timezone_set('Asia/Dubai');

// --- PRODUCTION ERROR HIDING (prevent info leakage) ---
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);
// DEBUG MODE ENABLED
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

// --- FORCE HTTPS (Cloudflare compatible) ---
// Check X-Forwarded-Proto (Cloudflare) or direct HTTPS
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false)
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

if (!$isHttps && php_sapi_name() !== 'cli') {
    $redirectUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirectUrl, true, 301);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/encryption.php';

// --- SECURITY HEADERS ---
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: frame-ancestors 'none'");

// HSTS: Force HTTPS for 1 year (required for production security)
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// --- SESSION TIMEOUT (1 hour) ---
$sessionMaxLifetime = 3600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $sessionMaxLifetime)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// --- CONSENT GATING ---
// Redirect users who haven't signed the latest consent version
// Exclude: login/logout pages, consent page itself, static assets, API endpoints
$_consentExcludedPaths = [
    '/auth/login.php',
    '/auth/logout.php',
    '/auth/password.php',
    '/consent.php',
    '/image.php',
    '/card/',
    '/event/', // Public event registration pages
    '/assets/',
    '/index.php', // Login page
    '/register.php', // Public registration
];

$_currentScriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$_isConsentExcluded = false;

// Also exclude root path (index.php might be served as /)
if ($_currentScriptPath === '/' || $_currentScriptPath === '' || basename($_currentScriptPath) === 'index.php') {
    $_isConsentExcluded = true;
}

foreach ($_consentExcludedPaths as $_excludePath) {
    if (strpos($_currentScriptPath, $_excludePath) !== false) {
        $_isConsentExcluded = true;
        break;
    }
}

// Only check consent for logged-in users on non-excluded pages
// Wrap in try-catch to prevent 500 errors if consent table doesn't exist
if (!$_isConsentExcluded && !empty($_SESSION['uid'])) {
    try {
        // Use SELECT * to avoid column mismatch errors
        $_consentUser = fetch_one('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);

        // Super admins are exempt from consent requirement
        if ($_consentUser && $_consentUser['role'] === 'super_admin') {
            // Skip consent check for super admins
        } elseif ($_consentUser && function_exists('user_needs_consent') && user_needs_consent($_consentUser)) {
            // Redirect to consent page
            header('Location: ' . BASE_URL . '/consent.php');
            exit;
        }
    } catch (Exception $e) {
        // Consent table might not exist yet - silently continue
        error_log('Consent check failed: ' . $e->getMessage());
    }
}
