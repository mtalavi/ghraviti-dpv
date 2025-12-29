<?php
/**
 * Optimized CSV Export with Streaming
 * Prevents memory exhaustion by fetching and writing rows one by one.
 */
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_exports');

// Disable time limit for large exports (prevents timeout on 50k+ records)
set_time_limit(0);

// Input params
$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : null;
$exportAll = isset($_GET['all']) && is_super_admin();
$allowedFields = [
    'dp_code',
    'v_number',
    'full_name',
    'full_name_ar',
    'gender',
    'nationality',
    'nationality_ar',
    'emirate',
    'emirate_ar',
    'area',
    'email',
    'mobile',
    'emirates_id',
    'emirates_id_expiry',
    'emirates_id_image',
    'date_of_birth',
    'profession',
    'skills',
    'profile_photo'
];
$fieldsRequest = $_POST['fields'] ?? [];

// Determine selected fields
$selectedFields = array_values(array_intersect($allowedFields, $fieldsRequest));
if (!$selectedFields) {
    $selectedFields = $allowedFields;
}

// Remove virtual fields from DB query (emirate_ar is derived from emirate)
$dbFields = array_filter($selectedFields, fn($f) => $f !== 'emirate_ar');

$stmt = null;
$filename = 'dpvhb_export.csv';

// 1. Prepare Query (Stream Mode)
try {
    if ($eventId) {
        // Event Attendees Logic
        $cols = implode(',', array_map(fn($c) => 'u.' . $c, $dbFields));
        $sql = "SELECT $cols, er.status, er.ref_number, er.has_reference, er.checkin_time, er.checkout_time 
                FROM event_registrations er 
                JOIN users u ON er.user_id = u.id 
                WHERE er.event_id = ? 
                ORDER BY u.dp_code ASC";

        $stmt = db()->prepare($sql);
        $stmt->execute([$eventId]);
        $filename = 'dpvhb_event_' . $eventId . '.csv';

        // Add event fields to output columns
        $selectedFields = array_merge($selectedFields, ['status', 'ref_number', 'has_reference', 'checkin_time', 'checkout_time']);

    } elseif ($exportAll) {
        // Export All Users
        $cols = implode(',', $dbFields);
        $sql = "SELECT $cols FROM users ORDER BY created_at DESC";

        $stmt = db()->prepare($sql);
        $stmt->execute();
        $filename = 'dpvhb_users_all.csv';

    } else {
        // Custom Selection Logic
        if (empty($_POST['dp_list'])) {
            exit('No DP codes supplied.');
        }
        $dpList = array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['dp_list'])));
        if (!$dpList) {
            exit('No DP codes supplied.');
        }

        $placeholders = implode(',', array_fill(0, count($dpList), '?'));
        $cols = implode(',', $dbFields);
        $sql = "SELECT $cols FROM users WHERE dp_code IN ($placeholders)";

        $stmt = db()->prepare($sql);
        $stmt->execute(array_values($dpList));
        $filename = 'dpvhb_custom_export.csv';
    }
} catch (Exception $e) {
    exit('Database error: ' . $e->getMessage());
}

// 2. Output Headers to force download
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// 3. Open Output Stream
$out = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel/Arabic rendering
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Write CSV Column Headers
fputcsv($out, $selectedFields);

// 4. Stream Rows (The Magic Part)
// Instead of fetchAll(), we use fetch() in a loop
if ($stmt) {
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Decrypt on the fly (one row at a time)
        if (function_exists('decrypt_user_data')) {
            $row = decrypt_user_data($row);
        }

        // Build ordered row based on selected fields
        $ordered = [];
        foreach ($selectedFields as $field) {
            if ($field === 'emirate_ar') {
                // Virtual field logic: Extract Arabic from "Abu Dhabi (أبو ظبي)"
                $val = $row['emirate'] ?? '';
                if (preg_match('/\(([^\)]+)\)$/', $val, $m)) {
                    $ordered[] = $m[1];
                } else {
                    $ordered[] = $val;
                }
            } else {
                $ordered[] = $row[$field] ?? '';
            }
        }

        // Write to stream immediately
        fputcsv($out, $ordered);
    }
} else {
    fputcsv($out, ['No data found']);
}

// Close stream
fclose($out);
exit;
