<?php
/**
 * Secure Image Server
 * Serves protected images (avatars, emirates_id) with authentication
 * Direct file access is blocked via .htaccess
 */
require_once __DIR__ . '/includes/init.php';

$type = $_GET['type'] ?? '';
$file = $_GET['file'] ?? '';

// Validate type
if (!in_array($type, ['avatar', 'eid', 'event'], true)) {
    http_response_code(400);
    exit('Invalid type');
}

// Validate filename (prevent directory traversal)
$file = basename($file);
if ($file === '' || preg_match('/\.\./', $file)) {
    http_response_code(400);
    exit('Invalid file');
}

// Build path based on type
$basePath = __DIR__ . '/assets/uploads/';
$subDir = '';
if ($type === 'avatar') {
    $subDir = 'avatars/';
} elseif ($type === 'eid') {
    $subDir = 'ids/';
} elseif ($type === 'event') {
    $subDir = 'events/';
} else {
    http_response_code(400);
    exit('Invalid type');
}

$filePath = $basePath . $subDir . $file;

// SECURITY: Validate path to prevent directory traversal
// Normalize slashes for cross-platform compatibility (Windows/Linux)
$normalizedFilePath = str_replace('\\', '/', $filePath);
$normalizedBasePath = str_replace('\\', '/', $basePath . $subDir);

// First check: Ensure file exists
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('Not found');
}

// Second check: Use realpath for final validation
$realFilePath = realpath($filePath);
$realBasePath = realpath($basePath . $subDir);

// Normalize slashes for realpath comparison too
if ($realFilePath !== false) {
    $realFilePath = str_replace('\\', '/', $realFilePath);
}
if ($realBasePath !== false) {
    $realBasePath = str_replace('\\', '/', $realBasePath);
}

// Ensure file is within the allowed directory
if ($realFilePath === false || $realBasePath === false || strpos($realFilePath, $realBasePath) !== 0) {
    http_response_code(404);
    exit('Not found');
}

// Authentication check
$user = current_user();

if ($type === 'eid') {
    // Emirates ID: Only admins can view
    if (!$user || !in_array($user['role'], ['admin', 'super_admin'])) {
        http_response_code(403);
        exit('Access denied');
    }
} elseif ($type === 'avatar') {
    // Avatar: Logged in users OR admins can view
    // Also allow if it's the user's own avatar
    if (!$user) {
        http_response_code(403);
        exit('Access denied');
    }
    // CORS: Allow html2canvas to render avatars in card screenshots
    // Strict check against BASE_URL to prevent unauthorized embedding
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    // Normalize BASE_URL (remove trailing slash)
    $allowedOrigin = rtrim(BASE_URL, '/');

    // Check if origin matches exactly OR if it's running on localhost (for dev)
    if ($origin === $allowedOrigin) {
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Access-Control-Allow-Credentials: true');
    }
} elseif ($type === 'event') {
    // Event banners are public - no authentication required
}

// Get MIME type using finfo (most reliable method)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath);
if ($mimeType === false) {
    $mimeType = 'image/jpeg'; // Fallback for edge cases
}

// Validate MIME (only images allowed)
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mimeType, $allowedMimes)) {
    http_response_code(403);
    exit('Invalid file type');
}

// Cache headers (1 hour)
$etag = md5_file($filePath);
$lastModified = filemtime($filePath);

header('Cache-Control: private, max-age=3600');
header('ETag: "' . $etag . '"');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');

// Check if client has cached version
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH'], '"') === $etag) {
    http_response_code(304);
    exit;
}

// Serve the file efficiently
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));

// Stream file in chunks to avoid loading entire file in memory
$fp = fopen($filePath, 'rb');
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 8192); // 8KB chunks
        flush(); // Force output to client
    }
    fclose($fp);
} else {
    // Fallback to readfile if fopen fails
    readfile($filePath);
}
exit;
