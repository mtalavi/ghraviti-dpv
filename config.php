<?php
/**
 * Core configuration for DPV hub.
 * SECURITY: All credentials are loaded from .dpv_keys file (outside web root).
 */

// =====================================================
// CREDENTIAL LOADING FROM SECURE .dpv_keys FILE
// =====================================================
function load_secure_credentials(): array
{
    static $credentials = null;
    if ($credentials !== null)
        return $credentials;

    $credentials = [];

    // Priority 1: Home directory (most secure - /home/dpvhub/.dpv_keys)
    $homeKeyFile = dirname(__DIR__, 2) . '/.dpv_keys';
    if (file_exists($homeKeyFile)) {
        $credentials = parse_ini_file($homeKeyFile) ?: [];
        return $credentials;
    }

    // Priority 2: One level up from project
    $parentKeyFile = dirname(__DIR__) . '/.dpv_keys';
    if (file_exists($parentKeyFile)) {
        $credentials = parse_ini_file($parentKeyFile) ?: [];
        return $credentials;
    }

    return $credentials;
}

// Load credentials from secure file
$secureCredentials = load_secure_credentials();

// Database Configuration (only password is sensitive)
define('DB_HOST', 'localhost');
define('DB_NAME', 'dpvhub_db');
define('DB_USER', 'dpvhub_user');

// Password from secure .dpv_keys file
$dbPass = $secureCredentials['DB_PASS'] ?? getenv('DB_PASS');
if (!$dbPass) {
    die('CRITICAL: DB_PASS not found in .dpv_keys file!');
}
define('DB_PASS', $dbPass);

// Brevo API Key for email sending
$brevoKey = $secureCredentials['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?: '';
define('BREVO_API_KEY', $brevoKey);

// BASE_URL detection: CLI-safe for cron jobs
// Priority: ENV variable > Auto-detect (web) > CLI fallback
$baseOverride = getenv('BASE_URL');
if ($baseOverride) {
    $base = '/' . trim($baseOverride, '/');
} else if (php_sapi_name() === 'cli') {
    // CLI mode (cron jobs): use empty base as safe default
    // Set BASE_URL env variable if you need specific value in CLI
    $base = '';
} else {
    // Web mode: auto-detect from document root
    $doc = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
    $app = rtrim(str_replace('\\', '/', __DIR__), '/');
    $base = '';
    if ($doc && strpos($app, $doc) === 0) {
        $base = rtrim(substr($app, strlen($doc)), '/');
        $base = $base ? '/' . ltrim($base, '/') : '';
    }
}
define('BASE_URL', $base);

define('APP_NAME', 'DPV hub');
define('APP_SLOGAN', 'Fusion of volunteering & patriotism');

// Upload/asset locations
define('UPLOAD_ROOT', __DIR__ . '/assets/uploads/');
define('AVATAR_DIR', UPLOAD_ROOT . 'avatars/');
define('QR_DIR', UPLOAD_ROOT . 'qr/');
define('CARD_DIR', UPLOAD_ROOT . 'cards/');
define('ID_DOC_DIR', UPLOAD_ROOT . 'ids/');
define('EVENT_BANNER_DIR', UPLOAD_ROOT . 'events/');
define('AVATAR_MAX_KB', 500);
define('ID_DOC_MAX_KB', 1500);
define('EVENT_BANNER_MAX_KB', 2000);

// Local QR scanner script (no external dependency)
define('HTML5_QR', BASE_URL . '/assets/js/html5-qrcode.min.js');

// Session cookie options (adjust secure to true when HTTPS is enabled on your domain).
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', 1);
}
