<?php
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=UTF-8');

function json_response(int $code, $data): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function ensure_console_session(string $slug): void
{
    $key = 'ea_' . $slug;
    if (empty($_SESSION[$key])) {
        json_response(401, ['error' => 'UNAUTHORIZED']);
    }
}

// Garbage collection for old lock files (1% probability)
function gc_old_lock_files(): void
{
    // Run GC with 1% probability
    if (random_int(1, 100) !== 1) {
        return;
    }

    $tempDir = sys_get_temp_dir();
    // Clean both idempotency locks and race condition locks
    $patterns = ['/dpv_idem_*.lock', '/dpv_lock_*.lock'];
    $threshold = time() - (24 * 60 * 60); // 24 hours ago

    foreach ($patterns as $pattern) {
        foreach (glob($tempDir . $pattern) as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }
}


// CSRF from header
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!csrf_check($csrfHeader)) {
    json_response(403, ['error' => 'CSRF_INVALID']);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(400, ['error' => 'INVALID_JSON']);
}

$uuid = trim($payload['uuid'] ?? '');
$action = $payload['action'] ?? '';
$dpCode = strtoupper(trim($payload['dp_code'] ?? ''));
$ref = trim($payload['ref_number'] ?? '');
$noRef = !empty($payload['no_ref']);
$slug = trim($payload['event_slug'] ?? '');
$timestamp = $payload['timestamp'] ?? '';

if ($uuid === '' || $dpCode === '' || $slug === '' || $action === '') {
    json_response(400, ['error' => 'MISSING_FIELDS']);
}

$event = fetch_one('SELECT * FROM events WHERE console_slug=?', [$slug]);
if (!$event) {
    json_response(404, ['error' => 'EVENT_NOT_FOUND']);
}

ensure_console_session($slug);

// Probabilistic garbage collection for old lock files
gc_old_lock_files();

// Idempotency via temp file
$idemFile = sys_get_temp_dir() . '/dpv_idem_' . sha1($uuid) . '.lock';
if (file_exists($idemFile)) {
    json_response(200, ['status' => 'ok', 'idempotent' => true]);
}

// Validate ref length if provided
if (!$noRef && $ref !== '' && !preg_match('/^\d{12}$/', $ref)) {
    json_response(400, ['error' => 'INVALID_REF']);
}

$user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dpCode]);
if (!$user) {
    json_response(404, ['error' => 'DP_NOT_FOUND']);
}

$refVal = $noRef ? null : ($ref !== '' ? $ref : null);
$hasRef = $noRef ? 0 : ($refVal ? 1 : 0);

// =====================================================
// CRITICAL FIX: Race Condition Prevention using flock
// Prevents duplicate check-ins on concurrent requests
// =====================================================
$lockFile = sys_get_temp_dir() . '/dpv_lock_' . md5($slug . '_' . $user['id']) . '.lock';
$fp = fopen($lockFile, 'c+');

// Try to acquire exclusive lock (Non-blocking)
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    fclose($fp);
    // Return 429 to client so they can retry
    json_response(429, ['error' => 'REQUEST_IN_PROGRESS', 'message' => 'Please wait and try again...']);
}

try {
    // Re-fetch registration status INSIDE the lock (critical for race condition prevention)
    $reg = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], $user['id']]);

    if ($action === 'checkin') {
        if ($reg) {
            execute_query("UPDATE event_registrations SET status='checked_in', ref_number=?, has_reference=?, checkin_time=NOW(), checkout_time=NULL WHERE id=?", [$refVal, $hasRef, $reg['id']]);
        } else {
            execute_query(
                'INSERT INTO event_registrations (event_id,user_id,ref_number,has_reference,status,checkin_time) VALUES (?,?,?,?,?,NOW())',
                [$event['id'], $user['id'], $refVal, $hasRef, 'checked_in']
            );
        }
        @file_put_contents($idemFile, '1');
        flock($fp, LOCK_UN);
        fclose($fp);
        json_response(200, ['status' => 'ok', 'action' => 'checkin']);

    } elseif ($action === 'checkout') {
        if (!$reg) {
            flock($fp, LOCK_UN);
            fclose($fp);
            json_response(400, ['error' => 'NOT_REGISTERED']);
        }
        execute_query("UPDATE event_registrations SET status='checked_out', ref_number=?, has_reference=?, checkout_time=NOW() WHERE id=?", [$refVal, $hasRef, $reg['id']]);
        @file_put_contents($idemFile, '1');
        flock($fp, LOCK_UN);
        fclose($fp);
        json_response(200, ['status' => 'ok', 'action' => 'checkout']);

    } elseif ($action === 'register') {
        if ($reg) {
            flock($fp, LOCK_UN);
            fclose($fp);
            json_response(400, ['error' => 'ALREADY_REGISTERED']);
        }
        execute_query(
            'INSERT INTO event_registrations (event_id,user_id,ref_number,has_reference,status) VALUES (?,?,?,?,?)',
            [$event['id'], $user['id'], $refVal, $hasRef, 'registered']
        );
        @file_put_contents($idemFile, '1');
        flock($fp, LOCK_UN);
        fclose($fp);
        json_response(200, ['status' => 'ok', 'action' => 'register']);

    } else {
        flock($fp, LOCK_UN);
        fclose($fp);
        json_response(400, ['error' => 'INVALID_ACTION']);
    }
} catch (Exception $e) {
    // Always release lock on error
    flock($fp, LOCK_UN);
    fclose($fp);
    json_response(500, ['error' => 'SERVER_ERROR']);
}
