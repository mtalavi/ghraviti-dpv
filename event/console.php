<?php
require_once __DIR__ . '/../includes/init.php';

$slug = $_GET['slug'] ?? '';
$event = fetch_one('SELECT * FROM events WHERE console_slug=?', [$slug]);
if (!$event) {
    exit('Event not found.');
}

// --- API / AJAX HANDLER ---
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json');

    // Auth Check
    $sessKey = 'ea_' . $slug;
    if (!isset($_SESSION[$sessKey])) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired. Please reload.']);
        exit;
    }

    if (!csrf_check($_POST['csrf'] ?? '')) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid security token.']);
        exit;
    }

    $action = $_POST['action'] ?? 'lookup';
    $response = ['status' => 'success'];

    try {
        if ($action === 'lookup') {
            $input = trim($_POST['input_code'] ?? '');
            if (empty($input))
                throw new Exception('Empty code.');

            // Find User/Reg
            $reg = fetch_one('SELECT er.*, u.dp_code, u.full_name, u.email, u.mobile FROM event_registrations er JOIN users u ON er.user_id=u.id WHERE er.event_id=? AND er.ref_number=?', [$event['id'], $input]);
            $user = null;
            $type = 'none';

            if ($reg) {
                $user = fetch_user_decrypted('SELECT * FROM users WHERE id=?', [$reg['user_id']]);
                $type = 'ref';
            } else {
                $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$input]);
                if ($user) {
                    $reg = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], $user['id']]);
                    $type = 'dp';
                }
            }

            if (!$user) {
                $response['scenario'] = 'D_NOT_FOUND';
                $response['sound'] = 'error';
            } else {
                $response['user'] = [
                    'full_name' => $user['full_name'],
                    'dp_code' => $user['dp_code'],
                    'mobile' => $user['mobile']
                ];

                if ($reg) {
                    $response['reg'] = [
                        'id' => $reg['id'],
                        'ref_number' => $reg['ref_number'],
                        'status' => $reg['status'],
                        'checkin_time' => $reg['checkin_time'],
                        'checkout_time' => $reg['checkout_time'],
                        'vest_number' => $reg['vest_number'] ?? null,
                        'vest_returned' => (int) ($reg['vest_returned'] ?? 0)
                    ];

                    if ($reg['status'] === 'checked_in') {
                        $response['scenario'] = 'E_CHECKOUT';
                        $response['sound'] = 'warning';
                    } elseif ($reg['status'] === 'checked_out') {
                        $response['scenario'] = 'F_ALREADY_OUT';
                        $response['sound'] = 'warning';
                    } else {
                        // Not checked in
                        if (!empty($reg['ref_number'])) {
                            $response['scenario'] = 'A_CONFIRM_CHECKIN';
                            $response['sound'] = 'success';
                        } else {
                            $response['scenario'] = 'B_MISSING_REF';
                            $response['sound'] = 'warning';
                        }
                    }
                } else {
                    $response['scenario'] = 'C_NOT_REGISTERED';
                    $response['sound'] = 'warning';
                }
            }

        } elseif (in_array($action, ['confirm_checkin', 'confirm_checkout', 'confirm_ref_checkin', 'confirm_noref_checkin', 'register_checkin'])) {

            $dp = trim($_POST['dp_code'] ?? '');
            $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dp]);
            if (!$user)
                throw new Exception('User not found.');

            $reg = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], $user['id']]);

            if ($action === 'confirm_checkin') {
                if ($reg) {
                    execute_query("UPDATE event_registrations SET status='checked_in', checkin_time=NOW(), checkout_time=NULL WHERE id=?", [$reg['id']]);
                    $response['message'] = "$dp Checked In";
                    $response['sound'] = 'success';
                    log_action('checkin', 'event', $event['id'], ['dp_code' => $dp]);
                    // Send check-in email
                    if (!empty($user['email'])) {
                        sendDPVEmail('check_in', $user['email'], $user['full_name'], [
                            'EVENT_NAME' => $event['name'] ?? ''
                        ]);
                    }
                }
            } elseif ($action === 'confirm_checkout') {
                if ($reg) {
                    execute_query("UPDATE event_registrations SET status='checked_out', checkout_time=NOW() WHERE id=?", [$reg['id']]);
                    $response['message'] = "$dp Checked Out";
                    $response['sound'] = 'success';
                    log_action('checkout', 'event', $event['id'], ['dp_code' => $dp]);
                    // Send check-out email
                    if (!empty($user['email'])) {
                        sendDPVEmail('check_out', $user['email'], $user['full_name'], [
                            'EVENT_NAME' => $event['name'] ?? ''
                        ]);
                    }
                }
            } elseif ($action === 'confirm_ref_checkin') {
                $ref = trim($_POST['ref_number'] ?? '');
                if (!preg_match('/^\d{12}$/', $ref))
                    throw new Exception('Reference must be 12 digits.');
                if ($reg) {
                    execute_query("UPDATE event_registrations SET ref_number=?, has_reference=1, status='checked_in', checkin_time=NOW(), checkout_time=NULL WHERE id=?", [$ref, $reg['id']]);
                    $response['message'] = "Saved & Checked In";
                    $response['sound'] = 'success';
                    // Send check-in email
                    if (!empty($user['email'])) {
                        sendDPVEmail('check_in', $user['email'], $user['full_name'], [
                            'EVENT_NAME' => $event['name'] ?? ''
                        ]);
                    }
                }
            } elseif ($action === 'confirm_noref_checkin') {
                if ($reg) {
                    execute_query("UPDATE event_registrations SET ref_number=NULL, has_reference=0, status='checked_in', checkin_time=NOW(), checkout_time=NULL WHERE id=?", [$reg['id']]);
                    $response['message'] = "Checked In (No Ref)";
                    $response['sound'] = 'success';
                    // Send check-in email
                    if (!empty($user['email'])) {
                        sendDPVEmail('check_in', $user['email'], $user['full_name'], [
                            'EVENT_NAME' => $event['name'] ?? ''
                        ]);
                    }
                }
            } elseif ($action === 'register_checkin') {
                if ($reg)
                    throw new Exception('Already registered.');

                $ref = trim($_POST['ref_number'] ?? '');
                $hasRef = !empty($ref);
                if ($hasRef && !preg_match('/^\d{12}$/', $ref))
                    throw new Exception('Reference must be 12 digits.');

                // Capacity check
                $cap = (int) ($event['capacity'] ?? 0);
                if ($cap > 0) {
                    $curr = fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=?', [$event['id']])['c'];
                    if ($curr >= $cap)
                        throw new Exception('Event full.');
                }

                execute_query(
                    "INSERT INTO event_registrations (event_id, user_id, ref_number, has_reference, status, checkin_time) VALUES (?, ?, ?, ?, 'checked_in', NOW())",
                    [$event['id'], $user['id'], $hasRef ? $ref : null, $hasRef ? 1 : 0]
                );
                $response['message'] = "Registered & Checked In";
                $response['sound'] = 'success';
                log_action('checkin_add', 'event', $event['id'], ['dp_code' => $dp]);
                // Send check-in email
                if (!empty($user['email'])) {
                    sendDPVEmail('check_in', $user['email'], $user['full_name'], [
                        'EVENT_NAME' => $event['name'] ?? ''
                    ]);
                }
            }
        } elseif ($action === 'ref_update') {
            $id = (int) $_POST['reg_id'];
            $ref = trim($_POST['ref_number'] ?? '');
            if ($ref !== '' && !preg_match('/^\d{12}$/', $ref))
                throw new Exception('Reference must be 12 digits.');
            execute_query('UPDATE event_registrations SET ref_number=?, has_reference=? WHERE id=? AND event_id=?', [$ref, $ref !== '' ? 1 : 0, $id, $event['id']]);
            $response['message'] = 'Reference updated';
            $response['sound'] = 'success';
        } elseif ($action === 'vest_update') {
            // Save vest number after check-in
            $dp = trim($_POST['dp_code'] ?? '');
            $vestNumber = trim($_POST['vest_number'] ?? '');
            $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dp]);
            if (!$user)
                throw new Exception('User not found.');
            $reg = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], $user['id']]);
            if (!$reg)
                throw new Exception('Registration not found.');
            execute_query('UPDATE event_registrations SET vest_number=? WHERE id=?', [$vestNumber !== '' ? $vestNumber : null, $reg['id']]);
            $response['message'] = $vestNumber !== '' ? "Vest $vestNumber assigned" : "No vest assigned";
            $response['sound'] = 'success';
            log_action('vest_assign', 'event', $event['id'], ['dp_code' => $dp, 'vest' => $vestNumber]);
        } elseif ($action === 'vest_checkout') {
            // Verify vest return on check-out
            $dp = trim($_POST['dp_code'] ?? '');
            $vestReturned = (int) ($_POST['vest_returned'] ?? 0);
            $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dp]);
            if (!$user)
                throw new Exception('User not found.');
            $reg = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], $user['id']]);
            if (!$reg)
                throw new Exception('Registration not found.');
            execute_query("UPDATE event_registrations SET status='checked_out', checkout_time=NOW(), vest_returned=? WHERE id=?", [$vestReturned, $reg['id']]);
            $response['message'] = $vestReturned ? "$dp Checked Out (Vest Returned)" : "$dp Checked Out (Vest NOT Returned)";
            $response['sound'] = 'success';
            log_action('checkout', 'event', $event['id'], ['dp_code' => $dp, 'vest_returned' => $vestReturned]);
            // Send check-out email
            if (!empty($user['email'])) {
                sendDPVEmail('check_out', $user['email'], $user['full_name'], [
                    'EVENT_NAME' => $event['name'] ?? ''
                ]);
            }
        } elseif ($action === 'get_recent_attendees') {
            // Get recent attendees for auto-refresh
            $attendees = fetch_users_decrypted('SELECT er.*, u.dp_code, u.full_name FROM event_registrations er JOIN users u ON er.user_id=u.id WHERE er.event_id=? ORDER BY er.checkin_time DESC LIMIT 50', [$event['id']]);
            $response['attendees'] = array_map(function ($a) {
                return [
                    'id' => $a['id'],
                    'dp_code' => $a['dp_code'],
                    'full_name' => $a['full_name'],
                    'status' => $a['status'],
                    'checkin_time' => $a['checkin_time'] ? date('H:i', strtotime($a['checkin_time'])) : null,
                    'checkout_time' => $a['checkout_time'] ? date('H:i', strtotime($a['checkout_time'])) : null,
                    'ref_number' => $a['ref_number'],
                    'vest_number' => $a['vest_number'],
                    'vest_returned' => (int) ($a['vest_returned'] ?? 0)
                ];
            }, $attendees);
        }

        // Always return updated stats
        $response['stats'] = [
            'total' => fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=?', [$event['id']])['c'] ?? 0,
            'in' => fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=? AND status="checked_in"', [$event['id']])['c'] ?? 0,
            'out' => fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=? AND status="checked_out"', [$event['id']])['c'] ?? 0,
        ];

    } catch (Exception $e) {
        $response['status'] = 'error';
        $response['message'] = $e->getMessage();
        $response['sound'] = 'error';
    }

    echo json_encode($response);
    exit;
}

// --- REGULAR PAGE LOAD ---

$sessKey = 'ea_' . $slug;
$blockKey = 'ea_block_' . $slug;
$attemptKey = 'ea_attempts_' . $slug;
$getRealIp = function (): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
};
$ip = $getRealIp();
$throttleFile = sys_get_temp_dir() . '/dpv_ea_' . sha1($slug . '|' . $ip) . '.json';
$throttleBlockedSeconds = 0;

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION[$sessKey], $_SESSION[$attemptKey], $_SESSION[$blockKey]);
    if (file_exists($throttleFile)) {
        @unlink($throttleFile);
    }
    redirect('console.php?slug=' . urlencode($slug));
}

$readThrottle = function () use ($throttleFile) {
    if (!file_exists($throttleFile))
        return ['count' => 0, 'blocked_until' => 0];
    $raw = @file_get_contents($throttleFile);
    return $raw ? (json_decode($raw, true) ?: ['count' => 0, 'blocked_until' => 0]) : ['count' => 0, 'blocked_until' => 0];
};
$writeThrottle = function (array $data) use ($throttleFile) {
    @file_put_contents($throttleFile, json_encode($data));
};
$resetThrottle = function () use ($throttleFile) {
    if (file_exists($throttleFile))
        @unlink($throttleFile);
};

// CSV Export - check BEFORE login form (admins can download directly)
if (isset($_GET['export']) && $_GET['export'] === 'checkedin') {
    // Check if user has access: event console logged in OR admin panel logged in
    $isEventAdmin = isset($_SESSION[$sessKey]);
    $currentUser = current_user();
    $isPanelAdmin = $currentUser && in_array($currentUser['role'], ['admin', 'super_admin']);

    if (!$isEventAdmin && !$isPanelAdmin) {
        header('HTTP/1.1 403 Forbidden');
        exit('Access denied. Please login first.');
    }

    // Create safe filename: event name (transliterated) + event date
    $eventDate = !empty($event['start_datetime']) ? date('Y-m-d', strtotime($event['start_datetime'])) : date('Y-m-d');
    $safeEventName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $event['name']);
    $safeEventName = preg_replace('/_+/', '_', trim($safeEventName, '_')); // Clean up multiple underscores
    if (empty($safeEventName) || $safeEventName === '_') {
        $safeEventName = 'Event_' . $event['id'];
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeEventName . '_' . $eventDate . '.csv"');

    // UTF-8 BOM for Arabic/Excel compatibility
    echo "\xEF\xBB\xBF";

    // Select only needed columns
    $rows = fetch_users_decrypted('
        SELECT 
            u.dp_code,
            u.v_number,
            u.full_name,
            u.full_name_ar,
            u.gender,
            u.nationality,
            u.nationality_ar,
            u.emirate,
            u.area,
            u.email,
            u.mobile,
            u.emirates_id,
            u.emirates_id_expiry,
            u.date_of_birth,
            er.ref_number as reference_number,
            er.status as event_status,
            er.checkin_time,
            er.checkout_time,
            er.vest_number,
            er.vest_returned
        FROM event_registrations er 
        JOIN users u ON er.user_id = u.id 
        WHERE er.event_id = ? 
        ORDER BY u.dp_code ASC
    ', [$event['id']]);

    $out = fopen('php://output', 'w');
    if (!empty($rows)) {
        // Add virtual columns to headers
        $headers = array_keys($rows[0]);
        $headers[] = 'emirate_ar';
        $headers[] = 'vest_status';
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            // Add emirate_ar column (extract Arabic part from "Abu Dhabi (أبو ظبي)")
            $r['emirate_ar'] = preg_match('/\(([^\)]+)\)$/', $r['emirate'] ?? '', $m) ? $m[1] : ($r['emirate'] ?? '');
            // Add vest_status column
            if (empty($r['vest_number'])) {
                $r['vest_status'] = 'No Vest';
            } elseif ($r['event_status'] === 'checked_out') {
                $r['vest_status'] = $r['vest_returned'] ? 'Returned' : 'Not Returned';
            } else {
                $r['vest_status'] = 'Assigned';
            }
            fputcsv($out, $r);
        }
    } else {
        fputcsv($out, ['No data']);
    }
    fclose($out);
    exit;
}

// Login
if (!isset($_SESSION[$sessKey])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        $throttle = $readThrottle();
        if (!csrf_check($_POST['csrf'] ?? '')) {
            $err = 'Session expired. Please refresh the page.';
        } elseif (!empty($throttle['blocked_until']) && time() < $throttle['blocked_until']) {
            $remaining = max(0, $throttle['blocked_until'] - time());
            $err = 'Too many attempts. Try again in ' . ceil($remaining / 60) . ' minutes.';
            $throttleBlockedSeconds = $remaining;
        } elseif (password_verify($_POST['password'], $event['console_password_hash'])) {
            session_regenerate_id(true);  // SECURITY: Prevent session fixation
            $_SESSION[$sessKey] = true;
            $resetThrottle();
            redirect('console.php?slug=' . urlencode($slug));
        } else {
            $err = 'Wrong password.';
            $count = ($throttle['count'] ?? 0) + 1;
            $blocked = $throttle['blocked_until'] ?? 0;
            if ($count >= 5) {
                $blocked = time() + 300;
                $count = 0;
                $err = 'Too many attempts. Try again in 5 minutes.';
            }
            $writeThrottle(['count' => $count, 'blocked_until' => $blocked]);
        }
    }
    render_header('Event Console', false);
    ?>
    <style>
        .login-card {
            background: white;
            border: 1px solid #f1f5f9;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border-radius: 1.5rem;
            width: 100%;
            max-width: 28rem;
            overflow: hidden;
        }

        .login-banner {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 1;
            object-fit: cover;
            object-position: center;
        }

        .login-content {
            padding: 2rem;
        }
    </style>
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-50 via-white to-emerald-50 p-6">
        <form method="post" class="login-card" id="consoleLoginForm" data-blocked="<?= $throttleBlockedSeconds ?>">
            <?php if (!empty($event['banner_image'])): ?>
                <img src="<?= BASE_URL ?>/image.php?type=event&file=<?= urlencode(basename($event['banner_image'])) ?>"
                    alt="<?= h($event['name']) ?>" class="login-banner">
            <?php endif; ?>
            <div class="login-content space-y-4">
                <h1 class="text-2xl font-bold text-slate-900 text-center">Event Console</h1>
                <p class="text-sm text-slate-600 text-center"><?= h($event['name']) ?></p>
                <?php if ($err): ?>
                    <div class="bg-red-50 text-red-700 px-3 py-2 rounded-lg text-center"><?= $err ?></div>
                <?php endif; ?>
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="password" name="password" placeholder="Event admin password"
                    class="w-full rounded-xl border border-slate-200 px-3 py-3 focus:border-emerald-400 focus:ring"
                    required>
                <button class="btn-primary w-full justify-center" type="submit">Enter console</button>
            </div>
        </form>
    </div>
    <?php
    render_footer();
    exit;
}

// Initial Data
$countTotal = fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=?', [$event['id']])['c'] ?? 0;
$countIn = fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=? AND status="checked_in"', [$event['id']])['c'] ?? 0;
$countOut = fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=? AND status="checked_out"', [$event['id']])['c'] ?? 0;
$attendees = fetch_users_decrypted('SELECT er.*, u.dp_code, u.full_name FROM event_registrations er JOIN users u ON er.user_id=u.id WHERE er.event_id=? ORDER BY er.checkin_time DESC LIMIT 50', [$event['id']]);

render_header('Event Console', false);
?>
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<style>
    /* Base */
    .glass-panel {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
    }

    /* Orange color (not in Tailwind 2.2 by default) */
    .bg-orange-500 {
        background-color: #f97316 !important;
    }

    .bg-orange-600 {
        background-color: #ea580c !important;
    }

    .hover\:bg-orange-600:hover {
        background-color: #ea580c !important;
    }

    .text-orange-100 {
        color: #ffedd5 !important;
    }

    .text-orange-600 {
        color: #ea580c !important;
    }

    .text-orange-700 {
        color: #c2410c !important;
    }

    .bg-orange-100 {
        background-color: #ffedd5 !important;
    }

    .border-orange-200 {
        border-color: #fed7aa !important;
    }

    /* Premium Button */
    .btn-primary {
        background-color: #10b981;
        color: white;
        font-weight: bold;
        padding: 0.75rem 1.5rem;
        border-radius: 0.75rem;
        transition: all 0.2s;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .btn-primary:hover {
        background-color: #059669;
    }

    /* Animations */
    .animate-pop {
        animation: pop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    @keyframes pop {
        0% {
            transform: scale(0.9);
            opacity: 0;
        }

        100% {
            transform: scale(1);
            opacity: 1;
        }
    }

    .animate-slideUp {
        animation: slideUp 0.3s ease-out;
    }

    @keyframes slideUp {
        from {
            transform: translateY(100%);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .animate-fadeIn {
        animation: fadeIn 0.2s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    /* Modal must be above everything */
    #actionModal {
        z-index: 9999 !important;
    }

    /* Mobile Modal - Bottom Sheet */
    @media (max-width: 640px) {
        #actionModal {
            align-items: flex-end !important;
            padding: 0 !important;
            padding-bottom: 60px !important;
            /* Space for fixed footer */
        }

        #actionModalContent {
            width: 100% !important;
            max-width: 100% !important;
            border-radius: 1.5rem 1.5rem 0 0 !important;
            max-height: 80vh;
            overflow-y: auto;
            margin-bottom: 0;
        }

        #actionModalContent.scale-100 {
            animation: slideUp 0.3s ease-out;
        }
    }

    /* Camera on mobile */
    @media (max-width: 640px) {
        #cam {
            height: 50vw !important;
            max-height: 280px;
        }
    }

    /* Focus states */
    input:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.3);
    }

    /* Sticky Footer - Fixed at bottom but below modal */
    .sticky-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        padding: 12px 16px;
        border-top: 1px solid #e2e8f0;
        z-index: 100;
        box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    /* Add padding to body/content to account for fixed footer */
    body {
        padding-bottom: 60px !important;
        /* Prevent pull-to-refresh on mobile (Android & iOS) */
        overscroll-behavior-y: contain;
    }

    /* Smooth all transitions */
    button,
    a,
    input {
        transition: all 0.15s ease;
    }
</style>

<div class="min-h-screen bg-gradient-to-br from-slate-100 via-slate-50 to-emerald-50 p-4 font-sans">
    <div class="max-w-3xl mx-auto space-y-5">

        <?php if (!empty($event['banner_image'])): ?>
            <!-- Event Banner -->
            <div class="w-full aspect-[3/1] rounded-3xl overflow-hidden shadow-lg">
                <img src="<?= BASE_URL ?>/image.php?type=event&file=<?= urlencode(basename($event['banner_image'])) ?>"
                    alt="<?= h($event['name']) ?>" class="w-full h-full object-cover">
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div
            class="bg-white border border-slate-100 rounded-3xl shadow-sm p-5 text-center space-y-2 relative overflow-hidden">
            <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-emerald-400 to-teal-500"></div>
            <form method="get" class="absolute right-4 top-4">
                <input type="hidden" name="slug" value="<?= h($slug) ?>">
                <button
                    class="text-xs font-bold text-slate-400 hover:text-red-500 uppercase tracking-wider transition-colors"
                    name="logout" value="1" type="submit">Logout</button>
            </form>

            <p class="text-xs font-bold uppercase tracking-widest text-emerald-600">Event Console</p>
            <h1 class="text-3xl font-black text-slate-900 tracking-tight"><?= h($event['name']) ?></h1>
            <p class="text-sm font-medium text-slate-500 flex items-center justify-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd"
                        d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                        clip-rule="evenodd" />
                </svg>
                <?= h($event['location']) ?>
            </p>

            <div class="grid grid-cols-3 gap-3 pt-2">
                <div class="bg-slate-50 rounded-2xl p-3 border border-slate-100">
                    <p class="text-xs text-slate-500 uppercase font-bold">Total</p>
                    <p class="text-2xl font-black text-slate-800" id="statTotal"><?= $countTotal ?></p>
                </div>
                <div class="bg-emerald-50 rounded-2xl p-3 border border-emerald-100">
                    <p class="text-xs text-emerald-600 uppercase font-bold">In</p>
                    <p class="text-2xl font-black text-emerald-700" id="statIn"><?= $countIn ?></p>
                </div>
                <div class="bg-orange-100 rounded-2xl p-3 border border-orange-200">
                    <p class="text-xs text-orange-600 uppercase font-bold">Out</p>
                    <p class="text-2xl font-black text-orange-700" id="statOut"><?= $countOut ?></p>
                </div>
            </div>

            <div id="offlineStatus"
                class="hidden mt-2 inline-flex items-center gap-2 px-3 py-1 rounded-full bg-slate-100 text-xs font-medium text-slate-600">
                <span class="w-2 h-2 rounded-full bg-slate-400"></span> Initializing...
            </div>
            <button id="queueBtn"
                class="hidden mt-2 inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-100 text-xs font-medium text-amber-800 hover:bg-amber-200 transition-colors"
                onclick="processNextInQueue()">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span> <span id="queueCount">0</span>
                pending scans (Click to Sync)
            </button>
        </div>

        <!-- Scanner / Input -->
        <div class="bg-white border border-slate-100 rounded-3xl shadow-sm p-6 space-y-4">
            <div class="flex flex-col items-center justify-center space-y-3">
                <!-- Camera -->
                <div id="camContainer" class="relative group hidden w-full flex justify-center">
                    <video id="cam"
                        class="w-full max-w-sm h-64 sm:h-80 rounded-3xl bg-slate-900 object-cover shadow-2xl border-4 border-slate-100"
                        autoplay playsinline muted></video>
                    <div
                        class="absolute inset-0 border-4 border-emerald-400/50 rounded-3xl pointer-events-none animate-pulse">
                    </div>
                    <!-- Loading Overlay -->
                    <div id="camLoading"
                        class="absolute inset-0 bg-black/30 backdrop-blur-sm rounded-3xl flex items-center justify-center hidden z-20">
                        <div class="w-12 h-12 border-4 border-white border-t-transparent rounded-full animate-spin">
                        </div>
                    </div>
                    <button type="button" onclick="stopCamera()"
                        class="absolute top-4 right-4 bg-black/50 text-white rounded-full p-2 hover:bg-black/70 z-10">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider hidden" id="scanStatus">Scanning...
                </p>
            </div>

            <!-- Manual Input -->
            <div id="manualInputContainer" class="hidden space-y-2">
                <div
                    class="flex items-center border border-slate-200 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-emerald-400 bg-slate-50">
                    <span class="bg-slate-100 text-slate-500 font-bold px-4 py-3 border-r border-slate-200">DP</span>
                    <input type="tel" inputmode="numeric" id="manual_code" placeholder="Code (e.g. 123)"
                        class="flex-1 bg-transparent border-none text-slate-900 text-lg px-4 py-3 outline-none placeholder:text-slate-400"
                        autocomplete="off">
                </div>
                <div class="grid grid-cols-4 gap-2">
                    <button
                        class="col-span-2 bg-slate-900 text-white rounded-xl py-3 font-bold hover:bg-slate-800 transition-colors shadow-lg"
                        onclick="submitManual()">Go</button>
                    <button
                        class="bg-emerald-500 text-white rounded-xl py-3 font-bold hover:bg-emerald-600 transition-colors shadow-lg flex items-center justify-center"
                        onclick="startScan()">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                        </svg>
                    </button>
                    <button class="text-slate-400 hover:text-slate-600 font-bold py-3"
                        onclick="toggleManual()">Close</button>
                </div>
            </div>

            <!-- Main Buttons -->
            <div class="grid grid-cols-2 gap-3" id="mainButtons">
                <button
                    class="py-4 text-emerald-700 font-bold bg-emerald-50 hover:bg-emerald-100 rounded-2xl transition-colors flex flex-col items-center justify-center gap-2 shadow-sm border border-emerald-100"
                    type="button" onclick="startScan()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                    </svg>
                    Scan QR
                </button>
                <button
                    class="py-4 text-slate-700 font-bold bg-slate-50 hover:bg-slate-100 rounded-2xl transition-colors flex flex-col items-center justify-center gap-2 shadow-sm border border-slate-100"
                    type="button" onclick="toggleManual()">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Manual Input
                </button>
            </div>
        </div>

        <!-- Attendees List -->
        <div class="bg-white border border-slate-100 rounded-3xl shadow-sm p-5 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                    <span class="w-2 h-6 bg-slate-500 rounded-full"></span>
                    Recent Attendees
                </h3>
            </div>
            <div class="grid gap-3" id="attendeeList">
                <?php if (!$attendees): ?>
                    <div class="p-6 text-slate-400 bg-slate-50 rounded-2xl text-center italic">No attendees yet.</div>
                <?php endif; ?>
                <?php foreach ($attendees as $a): ?>
                    <div
                        class="border border-slate-100 rounded-2xl p-4 space-y-3 bg-white hover:shadow-md transition-shadow">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="font-bold text-slate-900"><?= h($a['full_name']) ?></p>
                                <p class="text-xs text-slate-500 font-mono"><?= h($a['dp_code']) ?></p>
                                <div class="mt-1 flex flex-col gap-1">
                                    <?php if ($a['checkin_time']): ?>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] uppercase font-bold text-slate-400">In:</span>
                                            <span
                                                class="text-xs font-mono text-slate-600"><?= date('H:i', strtotime($a['checkin_time'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($a['checkout_time']): ?>
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] uppercase font-bold text-slate-400">Out:</span>
                                            <span
                                                class="text-xs font-mono text-slate-600"><?= date('H:i', strtotime($a['checkout_time'])) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <!-- Vest Info -->
                                    <div class="flex items-center gap-2">
                                        <span class="text-[10px] uppercase font-bold text-slate-400">Vest:</span>
                                        <?php if (!empty($a['vest_number'])): ?>
                                            <span
                                                class="text-xs font-mono font-semibold text-indigo-600"><?= h($a['vest_number']) ?></span>
                                            <?php if ($a['status'] === 'checked_out'): ?>
                                                <?php if ($a['vest_returned']): ?>
                                                    <span class="text-[10px] text-emerald-600 font-bold">✓ Returned</span>
                                                <?php else: ?>
                                                    <span class="text-[10px] text-red-600 font-bold">✗ Not Returned</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 italic">No Vest</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php
                            $stClass = $a['status'] === 'checked_out'
                                ? 'bg-orange-100 text-orange-700'
                                : 'bg-emerald-100 text-emerald-700';
                            $stLabel = str_replace('_', ' ', $a['status']);
                            ?>
                            <span
                                class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wide <?= $stClass ?>"><?= h($stLabel) ?></span>
                        </div>
                        <!-- Restored Reference Edit Form -->
                        <div class="flex items-center gap-2 pt-2 border-t border-slate-50">
                            <input id="ref_<?= $a['id'] ?>" value="<?= h($a['ref_number']) ?>" inputmode="numeric"
                                pattern="\d{12}" maxlength="12" autocomplete="off"
                                class="flex-1 bg-slate-50 border-none rounded-lg px-3 py-1.5 text-xs focus:ring-1 focus:ring-emerald-400"
                                placeholder="Reference...">
                            <button class="text-xs font-bold text-slate-500 hover:text-emerald-600 px-2"
                                onclick="performAction('ref_update', {reg_id: <?= $a['id'] ?>, ref_number: el('ref_<?= $a['id'] ?>').value})">Save</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<!-- ACTION MODAL -->
<div id="actionModal"
    class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm flex items-center justify-center z-50 p-4 hidden opacity-0 transition-opacity duration-200">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden relative transform scale-95 transition-transform duration-200"
        id="actionModalContent">
        <!-- Dynamic Content -->
        <div id="modalHeader" class="p-6 text-center"></div>
        <div id="modalBody" class="p-6 space-y-4"></div>
    </div>
</div>

<script>
    // --- STATE ---
    let activeStream = null;
    let html5Scanner = null;
    let html5Loaded = false;
    let isScanning = false;
    let isProcessing = false;
    let modalOpen = false;
    let scannerPaused = false;
    let cooldownUntil = 0;       // Timestamp until scans are blocked
    let currentScenario = null;
    let currentDP = null;
    const COOLDOWN_MS = 2000;    // 2 second cooldown after any popup closes

    // --- UTILS ---
    const el = (id) => document.getElementById(id);
    const playTone = (type) => {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            const t = audioCtx.currentTime;
            if (type === 'success') {
                osc.type = 'sine'; osc.frequency.setValueAtTime(800, t); osc.frequency.exponentialRampToValueAtTime(1200, t + 0.1);
                gain.gain.setValueAtTime(0.4, t); gain.gain.exponentialRampToValueAtTime(0.01, t + 0.3);
                osc.start(t); osc.stop(t + 0.3);
            } else if (type === 'warning') {
                osc.type = 'triangle'; osc.frequency.setValueAtTime(400, t);
                gain.gain.setValueAtTime(0.3, t); gain.gain.linearRampToValueAtTime(0.01, t + 0.25);
                osc.start(t); osc.stop(t + 0.25);
            } else {
                osc.type = 'sawtooth'; osc.frequency.setValueAtTime(200, t); osc.frequency.linearRampToValueAtTime(100, t + 0.4);
                gain.gain.setValueAtTime(0.4, t); gain.gain.linearRampToValueAtTime(0.01, t + 0.4);
                osc.start(t); osc.stop(t + 0.4);
            }
        } catch (e) { }
    };

    // --- API CALL ---
    const api = async (action, data = {}) => {
        const fd = new FormData();
        fd.append('csrf', document.querySelector('meta[name="csrf-token"]').content);
        fd.append('ajax', '1');
        fd.append('action', action);
        for (const k in data) fd.append(k, data[k]);

        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const json = await res.json();
            if (json.stats) {
                el('statTotal').textContent = json.stats.total;
                el('statIn').textContent = json.stats.in;
                el('statOut').textContent = json.stats.out;
            }
            return json;
        } catch (e) {
            return { status: 'error', message: 'Connection error' };
        }
    };

    // --- SCANNER CONTROL ---
    function pauseScanner() {
        scannerPaused = true;
        if (activeStream) {
            el('cam').pause();
        }
        if (html5Scanner) {
            try { html5Scanner.pause(true); } catch (e) { }
        }
    }

    function resumeScanner() {
        scannerPaused = false;
        if (activeStream && isScanning) {
            el('cam').play();
        }
        if (html5Scanner && isScanning) {
            try { html5Scanner.resume(); } catch (e) { }
        }
    }

    // --- MODAL LOGIC ---
    const showModal = (htmlHeader, htmlBody) => {
        pauseScanner(); // ALWAYS pause scanner when showing modal
        modalOpen = true;

        el('modalHeader').innerHTML = htmlHeader;
        el('modalBody').innerHTML = htmlBody;
        const m = el('actionModal');
        m.classList.remove('hidden');
        void m.offsetWidth;
        m.classList.remove('opacity-0');
        el('actionModalContent').classList.remove('scale-95');
        el('actionModalContent').classList.add('scale-100');
    };

    const closeModal = () => {
        modalOpen = false;
        // Set cooldown to prevent immediate re-scan when camera is still on same QR
        cooldownUntil = Date.now() + COOLDOWN_MS;
        const m = el('actionModal');
        m.classList.add('opacity-0');
        el('actionModalContent').classList.add('scale-95');
        el('actionModalContent').classList.remove('scale-100');
        setTimeout(() => {
            m.classList.add('hidden');
            resumeScanner();
        }, 300);
    };

    // --- ACTIONS ---
    window.performAction = async (action, extraData = {}) => {
        if (isProcessing) return;
        isProcessing = true;

        const btn = document.activeElement;
        const originalText = btn ? btn.innerText : '';
        if (btn) { btn.disabled = true; btn.innerText = '...'; }

        const data = { dp_code: currentDP, ...extraData };
        const res = await api(action, data);

        isProcessing = false;
        if (btn) { btn.disabled = false; btn.innerText = originalText; }

        if (res.status === 'success') {
            playTone(res.sound || 'success');

            // --- Instant Feedback: Refresh list immediately instead of waiting for timer ---
            await refreshAttendeeList();

            // Check if this is a check-in action that should trigger vest popup
            if (['confirm_checkin', 'confirm_ref_checkin', 'confirm_noref_checkin', 'register_checkin'].includes(action)) {
                showVestPopup(); // Show vest popup after check-in
            } else {
                closeModal();
            }
        } else {
            playTone('error');
            alert(res.message);
        }
    };

    // --- VEST POPUP ---
    window.showVestPopup = () => {
        const btnStyle = "width:100%;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;cursor:pointer;font-size:1rem;";
        const btnDisabledStyle = "width:100%;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;font-size:1rem;opacity:0.5;cursor:not-allowed;";
        const inputStyle = "width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:0.75rem;font-size:1.25rem;font-family:monospace;text-align:center;box-sizing:border-box;";

        const header = `<div style="background:#6366f1;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0">
        <h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">🎽 Assign Vest</h2>
        <p style="color:#e0e7ff;margin:0.25rem 0 0 0;font-size:0.875rem">Enter vest number for ${currentDP}</p>
    </div>`;

        const body = `<div style="display:flex;flex-direction:column;gap:1rem">
        <div>
            <label style="display:block;font-size:0.75rem;font-weight:bold;color:#64748b;text-transform:uppercase;margin-bottom:0.25rem">Vest Number</label>
            <input id="vestInput" type="text" maxlength="20" style="${inputStyle}" placeholder="e.g. V001" autofocus oninput="updateVestButtons()">
        </div>
        <button id="saveVestBtn" style="${btnDisabledStyle}background:#6366f1;" onclick="saveVest()" disabled>Save Vest Number</button>
        <button id="skipVestBtn" style="${btnStyle}background:#94a3b8;" onclick="skipVest()">No Vest / Skip</button>
    </div>`;

        showModal(header, body);
        setTimeout(() => { const v = el('vestInput'); if (v) v.focus(); }, 100);
    };

    // Update vest popup button states based on input
    window.updateVestButtons = () => {
        const vestInput = el('vestInput');
        const saveBtn = el('saveVestBtn');
        const skipBtn = el('skipVestBtn');
        if (!vestInput || !saveBtn || !skipBtn) return;

        const hasValue = vestInput.value.trim() !== '';

        if (hasValue) {
            // Has vest number: enable Save, disable Skip
            saveBtn.disabled = false;
            saveBtn.style.opacity = '1';
            saveBtn.style.cursor = 'pointer';
            skipBtn.disabled = true;
            skipBtn.style.opacity = '0.5';
            skipBtn.style.cursor = 'not-allowed';
        } else {
            // No vest number: disable Save, enable Skip
            saveBtn.disabled = true;
            saveBtn.style.opacity = '0.5';
            saveBtn.style.cursor = 'not-allowed';
            skipBtn.disabled = false;
            skipBtn.style.opacity = '1';
            skipBtn.style.cursor = 'pointer';
        }
    };

    window.saveVest = async () => {
        const vestNumber = (el('vestInput')?.value || '').trim();
        if (!vestNumber) {
            alert('Please enter a vest number or click Skip');
            return;
        }
        await api('vest_update', { dp_code: currentDP, vest_number: vestNumber });
        playTone('success');
        closeModal();
    };

    window.skipVest = async () => {
        await api('vest_update', { dp_code: currentDP, vest_number: '' });
        playTone('success');
        closeModal();
    };

    // Current vest number for checkout flow
    let currentVestNumber = null;

    window.showVestCheckoutPopup = (vestNumber) => {
        currentVestNumber = vestNumber;
        const btnStyle = "width:100%;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;cursor:pointer;font-size:1rem;";

        // If no vest was assigned, show simplified checkout popup
        if (!vestNumber) {
            const header = `<div style="background:#64748b;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0">
            <h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">👋 Check Out</h2>
            <p style="color:#e2e8f0;margin:0.25rem 0 0 0;font-size:0.875rem">Checkout for ${currentDP}</p>
        </div>`;

            const body = `<div style="display:flex;flex-direction:column;gap:1rem">
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:1rem;text-align:center">
                <p style="color:#64748b;margin:0;font-size:0.875rem">This person had no vest assigned</p>
                <p style="color:#94a3b8;margin:0.5rem 0 0 0;font-size:0.75rem">No vest was assigned to this person</p>
            </div>
            <button style="${btnStyle}background:#10b981;" onclick="confirmVestReturn(1)">✓ Confirm Checkout</button>
            <button style="width:100%;color:#94a3b8;font-weight:bold;padding:0.5rem;background:none;border:none;cursor:pointer" onclick="closeModal()">Cancel</button>
        </div>`;

            showModal(header, body);
            return;
        }

        // Has vest - show full vest return popup
        const header = `<div style="background:#f97316;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0">
        <h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">🎽 Vest Return</h2>
        <p style="color:#fed7aa;margin:0.25rem 0 0 0;font-size:0.875rem">Verify vest return for ${currentDP}</p>
    </div>`;

        const vestInfo = `<div style="background:#f8fafc;border:2px solid #6366f1;border-radius:0.75rem;padding:1rem;text-align:center">
            <p style="font-size:0.75rem;color:#64748b;text-transform:uppercase;font-weight:bold;margin:0">Assigned Vest</p>
            <p style="font-size:2rem;font-weight:900;color:#6366f1;margin:0.25rem 0 0 0">${vestNumber}</p>
           </div>`;

        const body = `<div style="display:flex;flex-direction:column;gap:1rem">
        ${vestInfo}
        <button style="${btnStyle}background:#10b981;" onclick="confirmVestReturn(1)">✓ Vest Returned</button>
        <button style="${btnStyle}background:#ef4444;" onclick="confirmVestReturn(0)">✗ Vest NOT Returned</button>
        <button style="width:100%;color:#94a3b8;font-weight:bold;padding:0.5rem;background:none;border:none;cursor:pointer" onclick="closeModal()">Cancel</button>
    </div>`;

        showModal(header, body);
    };

    window.confirmVestReturn = async (returned) => {
        if (isProcessing) return;
        isProcessing = true;

        const res = await api('vest_checkout', { dp_code: currentDP, vest_returned: returned });

        isProcessing = false;
        if (res.status === 'success') {
            playTone('success');
            closeModal();
        } else {
            playTone('error');
            alert(res.message);
        }
    };

    // --- LOOKUP ---
    window.lookup = async (code) => {
        // Block if modal open, processing, paused, or in cooldown period
        if (modalOpen || isProcessing || scannerPaused) return;
        if (Date.now() < cooldownUntil) return; // Still in cooldown

        // OFFLINE CHECK
        if (!navigator.onLine) {
            const q = getQueue();
            q.push({ code: code, time: Date.now() });
            saveQueue(q);
            playTone('warning');
            showModal(
                `<div style="background:#334155;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white">Offline Mode</h2></div>`,
                `<div style="text-align:center;display:flex;flex-direction:column;gap:1rem">
                <div style="width:4rem;height:4rem;margin:0 auto;background:#f1f5f9;border-radius:50%;display:flex;align-items:center;justify-content:center">
                    <svg style="width:2rem;height:2rem;color:#64748b" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728"></path></svg>
                </div>
                <p style="font-size:1.125rem;color:#334155">Saved to offline queue</p>
                <button style="width:100%;background:#1e293b;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;cursor:pointer" onclick="closeModal()">OK</button>
             </div>`
            );
            return;
        }

        isProcessing = true;
        el('camLoading').classList.remove('hidden');

        const res = await api('lookup', { input_code: code });

        el('camLoading').classList.add('hidden');
        isProcessing = false;

        if (res.status === 'error') {
            playTone('error');
            alert(res.message);
            return;
        }

        lastScannedCode = code;
        // Don't set lastScannedTime yet

        currentScenario = res.scenario;
        if (res.user) currentDP = res.user.dp_code;
        playTone(res.sound);

        // Build UI based on Scenario - using inline styles for reliability
        let header = '';
        let body = '';

        const userHtml = res.user ? `
        <div style="text-align:center;padding:1rem 0">
            <h3 style="font-size:1.25rem;font-weight:bold;color:#0f172a;margin:0">${res.user.full_name}</h3>
            <p style="color:#64748b;font-family:monospace;font-size:1.125rem;margin:0.25rem 0 0 0">${res.user.dp_code}</p>
        </div>` : '';

        const btnStyle = "width:100%;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;cursor:pointer;font-size:1rem;";
        const btnCancel = `<button style="width:100%;color:#94a3b8;font-weight:bold;padding:0.5rem;background:none;border:none;cursor:pointer" onclick="closeModal()">Cancel</button>`;
        const inputStyle = "width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:0.75rem;font-size:1.125rem;font-family:monospace;text-align:center;letter-spacing:0.1em;box-sizing:border-box;";

        if (currentScenario === 'A_CONFIRM_CHECKIN') {
            header = `<div style="background:#10b981;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">User Found</h2><p style="color:#d1fae5;margin:0.25rem 0 0 0;font-size:0.875rem">Ready to Check In</p></div>`;
            body = `${userHtml}
            <div style="background:#f8fafc;border-radius:0.75rem;padding:0.75rem;border:1px solid #e2e8f0;text-align:center">
                <p style="font-size:0.75rem;color:#94a3b8;text-transform:uppercase;font-weight:bold;margin:0">Ref</p>
                <p style="color:#1e293b;font-family:monospace;margin:0.25rem 0 0 0">${res.reg.ref_number}</p>
            </div>
            <button style="${btnStyle}background:#10b981;" onclick="performAction('confirm_checkin')">✓ Check In</button>
            ${btnCancel}`;
        }
        else if (currentScenario === 'B_MISSING_REF') {
            header = `<div style="background:#f59e0b;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">Missing Reference</h2><p style="color:#fef3c7;margin:0.25rem 0 0 0;font-size:0.875rem">Registered but no reference number</p></div>`;
            body = `${userHtml}
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:bold;color:#64748b;text-transform:uppercase;margin-bottom:0.25rem">Reference Number (12 digits)</label>
                <input id="refInput" type="tel" inputmode="numeric" maxlength="12" style="${inputStyle}" placeholder="000000000000" oninput="this.value=this.value.replace(/\\D/g,'')">
            </div>
            <button style="${btnStyle}background:#f59e0b;" onclick="performAction('confirm_ref_checkin', {ref_number: el('refInput').value})">Save & Check In</button>
            <div style="text-align:center;font-size:0.75rem;font-weight:bold;color:#cbd5e1;margin:0.5rem 0">— OR —</div>
            <button style="${btnStyle}background:#e2e8f0;color:#475569;" onclick="performAction('confirm_noref_checkin')">Check In without Reference</button>
            ${btnCancel}`;
        }
        else if (currentScenario === 'C_NOT_REGISTERED') {
            header = `<div style="background:#ef4444;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">Not Registered</h2><p style="color:#fecaca;margin:0.25rem 0 0 0;font-size:0.875rem">User exists but not in this event</p></div>`;
            body = `${userHtml}
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:0.75rem;padding:0.75rem;text-align:center">
                <p style="color:#b91c1c;font-size:0.875rem;margin:0">This volunteer is not registered for this event</p>
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;font-weight:bold;color:#64748b;text-transform:uppercase;margin-bottom:0.25rem">Reference Number (Optional)</label>
                <input id="regRefInput" type="tel" inputmode="numeric" maxlength="12" style="${inputStyle}" placeholder="000000000000" oninput="this.value=this.value.replace(/\\D/g,'')">
            </div>
            <button style="${btnStyle}background:#ef4444;" onclick="performAction('register_checkin', {ref_number: el('regRefInput').value})">Register & Check In</button>
            <button style="width:100%;color:#94a3b8;font-weight:bold;padding:0.5rem;background:none;border:none;cursor:pointer" onclick="closeModal()">Cancel (Don't Register)</button>`;
        }
        else if (currentScenario === 'E_CHECKOUT') {
            // Show vest checkout popup instead of regular checkout
            const vestNum = res.reg?.vest_number || null;
            showVestCheckoutPopup(vestNum);
            return; // Don't show the regular modal, vest popup handles it
        }
        else if (currentScenario === 'F_ALREADY_OUT') {
            header = `<div style="background:#64748b;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">Already Checked Out</h2></div>`;
            body = `${userHtml}
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.75rem;padding:0.75rem;text-align:center">
                <p style="color:#475569;font-size:0.875rem;margin:0">This volunteer has already checked out</p>
            </div>
            <button style="${btnStyle}background:#1e293b;" onclick="closeModal()">OK</button>`;
        }
        else if (currentScenario === 'D_NOT_FOUND') {
            header = `<div style="background:#dc2626;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">Not Found</h2></div>`;
            body = `<div style="text-align:center;display:flex;flex-direction:column;gap:1rem">
            <div style="width:4rem;height:4rem;margin:0 auto;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center">
                <svg style="width:2rem;height:2rem;color:#ef4444" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </div>
            <p style="font-size:1.125rem;font-weight:600;color:#1e293b;margin:0">Code not recognized</p>
            <p style="color:#64748b;font-size:0.875rem;margin:0">This DP code does not exist in the system</p>
            <button style="${btnStyle}background:#1e293b;" onclick="closeModal()">OK</button>
        </div>`;
        }

        if (header && body) {
            showModal(header, body);
        }
    };

    // --- SCANNER ---
    // Detect iOS/Safari for special handling
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    const needsHtml5Fallback = isIOS || isSafari || !('BarcodeDetector' in window);

    window.startScan = async () => {
        el('mainButtons').classList.add('hidden');
        el('manualInputContainer').classList.add('hidden');
        el('camContainer').classList.remove('hidden');
        el('scanStatus').classList.remove('hidden');
        isScanning = true;

        // iOS/Safari specific camera constraints
        const constraints = {
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 }
            },
            audio: false
        };

        try {
            // For iOS Safari, always use Html5Qrcode (more reliable)
            if (needsHtml5Fallback) {
                await startHtml5Scanner();
                return;
            }

            // Try native BarcodeDetector for supported browsers
            if ('BarcodeDetector' in window) {
                const detector = new BarcodeDetector({ formats: ['qr_code', 'code_128', 'code_39'] });
                activeStream = await navigator.mediaDevices.getUserMedia(constraints);
                const video = el('cam');
                video.srcObject = activeStream;

                // Wait for video to be ready
                await new Promise((resolve) => {
                    video.onloadedmetadata = () => {
                        video.play().then(resolve).catch(resolve);
                    };
                });

                const loop = async () => {
                    if (!activeStream || !isScanning) return;
                    if (scannerPaused || modalOpen || isProcessing) {
                        requestAnimationFrame(loop);
                        return;
                    }
                    try {
                        const detection = await detector.detect(video);
                        if (detection.length > 0) {
                            const code = detection[0].rawValue.trim();
                            if (code) await lookup(code);
                        }
                    } catch (e) {
                        // Silently continue on detection errors
                    }
                    requestAnimationFrame(loop);
                };
                requestAnimationFrame(loop);
            } else {
                await startHtml5Scanner();
            }
        } catch (e) {
            console.error('Camera error:', e);
            // Fallback to Html5Qrcode on any error
            try {
                await startHtml5Scanner();
            } catch (e2) {
                console.error('Scanner fallback error:', e2);
                alert('Unable to access camera. Please check permissions and try again.');
                stopCamera();
            }
        }
    };

    window.stopCamera = () => {
        isScanning = false;
        if (activeStream) { activeStream.getTracks().forEach(t => t.stop()); activeStream = null; }
        if (html5Scanner) { html5Scanner.stop().catch(() => { }); html5Scanner = null; }
        el('camContainer').classList.add('hidden');
        el('scanStatus').classList.add('hidden');
        el('mainButtons').classList.remove('hidden');
    };

    window.startHtml5Scanner = async () => {
        await ensureHtml5();

        // Stop any existing scanner first
        if (html5Scanner) {
            try { await html5Scanner.stop(); } catch (e) { }
        }

        html5Scanner = new Html5Qrcode("cam", { verbose: false });

        // iOS-optimized configuration
        const config = {
            fps: isIOS ? 5 : 10,  // Lower FPS for iOS battery
            qrbox: function (viewfinderWidth, viewfinderHeight) {
                // Responsive qrbox - smaller on mobile
                const minDimension = Math.min(viewfinderWidth, viewfinderHeight);
                return { width: Math.floor(minDimension * 0.7), height: Math.floor(minDimension * 0.7) };
            },
            aspectRatio: isIOS ? 1.0 : 1.7777778,  // Square for iOS, 16:9 for others
            disableFlip: false,
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: false  // Force use of jsQR for consistency
            }
        };

        try {
            await html5Scanner.start(
                { facingMode: "environment" },
                config,
                (decodedText, decodedResult) => {
                    if (scannerPaused || modalOpen || isProcessing) return;
                    lookup(decodedText.trim());
                },
                (errorMessage) => {
                    // Ignore scan errors (no QR in view)
                }
            );
        } catch (e) {
            console.error('Html5Scanner start error:', e);
            // If camera permission denied or not available
            if (e.includes && (e.includes('NotAllowed') || e.includes('NotFound'))) {
                alert('Camera access denied. Please enable camera permissions in your browser settings.');
                stopCamera();
            }
            throw e;
        }
    };

    window.ensureHtml5 = () => new Promise((resolve) => {
        if (html5Loaded) return resolve();
        const s = document.createElement('script');
        s.src = "<?= HTML5_QR ?>";
        s.onload = () => { html5Loaded = true; resolve(); };
        document.body.appendChild(s);
    });

    // --- MANUAL INPUT ---
    window.toggleManual = () => {
        const c = el('manualInputContainer');
        const b = el('mainButtons');
        if (c.classList.contains('hidden')) {
            c.classList.remove('hidden');
            b.classList.add('hidden');
            el('manual_code').focus();
        } else {
            c.classList.add('hidden');
            b.classList.remove('hidden');
        }
    };

    window.submitManual = () => {
        const val = el('manual_code').value.trim();
        if (!val) return;
        const code = 'DP' + val; // Prefix
        lookup(code);
        el('manual_code').value = '';
    };

    // --- OFFLINE QUEUE ---
    // More reliable online check (navigator.onLine can be unreliable on some devices)
    const isOnline = () => {
        // Basic check first
        if (!navigator.onLine) return false;
        // If we think we're online, trust it (actual API call will fail if not)
        return true;
    };

    // Safe localStorage operations with fallback
    const getQueue = () => {
        try {
            const data = localStorage.getItem('ea_queue_<?= h($slug) ?>');
            if (!data) return [];
            const parsed = JSON.parse(data);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.warn('Queue read error:', e);
            return [];
        }
    };

    const saveQueue = (q) => {
        try {
            localStorage.setItem('ea_queue_<?= h($slug) ?>', JSON.stringify(q));
            // Verify save worked
            const verify = getQueue();
            if (verify.length !== q.length) {
                console.warn('Queue save verification failed');
            }
        } catch (e) {
            console.error('Queue save error:', e);
            alert('Warning: Could not save to offline queue. Storage may be full.');
        }
        updateQueueUI();
    };

    const updateQueueUI = () => {
        const q = getQueue();
        const countEl = el('queueCount');
        const btnEl = el('queueBtn');
        if (countEl) countEl.textContent = q.length;
        if (btnEl) {
            if (q.length > 0 && isOnline()) btnEl.classList.remove('hidden');
            else btnEl.classList.add('hidden');
        }
    };

    window.processNextInQueue = async () => {
        if (modalOpen || isProcessing) return;
        const q = getQueue();
        if (q.length === 0) return;

        // Reset cooldown to allow immediate processing of queued items
        cooldownUntil = 0;

        const item = q.shift();
        saveQueue(q);

        // Handle both old format (string) and new format (object)
        const code = typeof item === 'string' ? item : item.code;
        await lookup(code);

        // If more items and no modal open, process next (recursive)
        const remaining = getQueue();
        if (remaining.length > 0 && !modalOpen && navigator.onLine) {
            setTimeout(() => processNextInQueue(), 500);
        }
    };

    // Auto-sync when back online
    window.syncOfflineQueue = () => {
        const q = getQueue();
        if (q.length > 0 && navigator.onLine && !modalOpen) {
            showModal(
                `<div style="background:#10b981;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0"><h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">Back Online</h2></div>`,
                `<div style="text-align:center;display:flex;flex-direction:column;gap:1rem">
                <p style="font-size:1.125rem;color:#334155;margin:0">You have <span style="font-weight:bold">${q.length}</span> queued scan(s)</p>
                <button style="width:100%;background:#10b981;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;cursor:pointer" onclick="closeModal(); cooldownUntil=0; setTimeout(processNextInQueue, 300);">Sync Now</button>
                <button style="width:100%;color:#94a3b8;font-weight:bold;padding:0.5rem;background:none;border:none;cursor:pointer" onclick="closeModal()">Later</button>
             </div>`
            );
        }
    };

    // --- STATUS INDICATOR ---
    const updateOnlineStatus = () => {
        const statusBox = document.getElementById('offlineStatus');
        if (navigator.onLine) {
            statusBox.classList.remove('hidden');
            statusBox.style.display = 'inline-flex';
            statusBox.innerHTML = '<span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Online';
        } else {
            statusBox.classList.remove('hidden');
            statusBox.style.display = 'inline-flex';
            statusBox.innerHTML = '<span class="w-2 h-2 rounded-full bg-red-500"></span> Offline';
        }
        updateQueueUI();
    };

    // Init
    window.addEventListener('online', () => { updateOnlineStatus(); setTimeout(syncOfflineQueue, 500); });
    window.addEventListener('offline', updateOnlineStatus);
    updateOnlineStatus();

    // --- AUTO-REFRESH ATTENDEES ---
    const escapeHtml = (str) => {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };

    const renderAttendeeCard = (a) => {
        const stClass = a.status === 'checked_out' ? 'bg-orange-100 text-orange-700' : 'bg-emerald-100 text-emerald-700';
        const stLabel = a.status.replace(/_/g, ' ');

        let vestHtml = '';
        if (a.vest_number) {
            vestHtml = `<span class="text-xs font-mono font-semibold text-indigo-600">${escapeHtml(a.vest_number)}</span>`;
            if (a.status === 'checked_out') {
                vestHtml += a.vest_returned
                    ? ' <span class="text-[10px] text-emerald-600 font-bold">✓ Returned</span>'
                    : ' <span class="text-[10px] text-red-600 font-bold">✗ Not Returned</span>';
            }
        } else {
            vestHtml = '<span class="text-xs text-slate-400 italic">No Vest</span>';
        }

        return `<div class="border border-slate-100 rounded-2xl p-4 space-y-3 bg-white hover:shadow-md transition-shadow">
            <div class="flex items-start justify-between">
                <div>
                    <p class="font-bold text-slate-900">${escapeHtml(a.full_name)}</p>
                    <p class="text-xs text-slate-500 font-mono">${escapeHtml(a.dp_code)}</p>
                    <div class="mt-1 flex flex-col gap-1">
                        ${a.checkin_time ? `<div class="flex items-center gap-2"><span class="text-[10px] uppercase font-bold text-slate-400">In:</span><span class="text-xs font-mono text-slate-600">${a.checkin_time}</span></div>` : ''}
                        ${a.checkout_time ? `<div class="flex items-center gap-2"><span class="text-[10px] uppercase font-bold text-slate-400">Out:</span><span class="text-xs font-mono text-slate-600">${a.checkout_time}</span></div>` : ''}
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] uppercase font-bold text-slate-400">Vest:</span>
                            ${vestHtml}
                        </div>
                    </div>
                </div>
                <span class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase tracking-wide ${stClass}">${escapeHtml(stLabel)}</span>
            </div>
            <div class="flex items-center gap-2 pt-2 border-t border-slate-50">
                <input id="ref_${a.id}" value="${escapeHtml(a.ref_number || '')}" inputmode="numeric" pattern="\\d{12}" maxlength="12"
                    autocomplete="off" class="flex-1 bg-slate-50 border-none rounded-lg px-3 py-1.5 text-xs focus:ring-1 focus:ring-emerald-400" placeholder="Reference...">
                <button class="text-xs font-bold text-slate-500 hover:text-emerald-600 px-2"
                    onclick="performAction('ref_update', {reg_id: ${a.id}, ref_number: el('ref_${a.id}').value})">Save</button>
            </div>
        </div>`;
    };

    const refreshAttendeeList = async () => {
        // Guard 1: Skip when offline
        if (!navigator.onLine) return;

        // Guard 2: Skip when modal open or processing
        if (modalOpen || isProcessing) return;

        // Guard 3 (Focus Guard): Skip if user is focused on any input in the attendee list
        // This prevents the input from losing focus while the admin is typing a reference number
        if (document.querySelector('#attendeeList input:focus')) return;

        try {
            const res = await api('get_recent_attendees');
            if (res.status === 'success' && res.attendees) {
                const list = el('attendeeList');
                if (!list) return;

                // Build new HTML
                const newHTML = res.attendees.length === 0
                    ? '<div class="p-6 text-slate-400 bg-slate-50 rounded-2xl text-center italic">No attendees yet.</div>'
                    : res.attendees.map(renderAttendeeCard).join('');

                // Only update if data actually changed (prevents unnecessary DOM churn)
                if (list.innerHTML !== newHTML) {
                    list.innerHTML = newHTML;
                }
            }
        } catch (e) {
            // Silently fail - will retry on next interval
        }
    };

    // Auto-refresh every 5 seconds
    setInterval(refreshAttendeeList, 5000);

    // --- ULTIMATE OFFLINE GUARD (Mobile & Desktop) ---

    // 1. Prevent refresh via keyboard shortcuts (Desktop)
    document.addEventListener('keydown', function (e) {
        if (!navigator.onLine) {
            if ((e.key === 'F5') || (e.ctrlKey && e.key === 'r') || (e.metaKey && e.key === 'r')) {
                e.preventDefault();
                showOfflineWarningModal();
            }
        }
    });

    // 2. Last line of defense - browser refresh button or gestures
    window.addEventListener('beforeunload', function (e) {
        if (!navigator.onLine) {
            e.preventDefault();
            e.returnValue = 'You are OFFLINE. Data might be lost.';
            return 'You are OFFLINE. Data might be lost.';
        }
    });

    // 3. Pull-to-refresh prevention for older iOS that doesn't support CSS overscroll-behavior
    document.addEventListener('touchmove', function (e) {
        if (!navigator.onLine && window.scrollY === 0 && e.touches[0].clientY > 0) {
            // CSS usually handles this, but this is a fallback safety net
            // Uncomment e.preventDefault() only if CSS doesn't work on target devices
        }
    }, { passive: false });

    // Display styled offline warning modal
    function showOfflineWarningModal() {
        playTone('error');
        showModal(
            `<div style="background:#ef4444;margin:-1.5rem;padding:1.5rem;text-align:center;margin-bottom:0">
                <h2 style="font-size:1.5rem;font-weight:900;color:white;margin:0">⚠️ NO CONNECTION</h2>
            </div>`,
            `<div style="text-align:center;display:flex;flex-direction:column;gap:1rem">
                <div style="width:4rem;height:4rem;margin:0 auto;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center">
                    <svg style="width:2rem;height:2rem;color:#ef4444" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728M5.636 18.364a9 9 0 010-12.728"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"></path></svg>
                </div>
                <p style="font-size:1.125rem;font-weight:bold;color:#1e293b;margin:0">
                    Wait! You are OFFLINE.
                </p>
                <p style="color:#64748b;font-size:0.9rem;line-height:1.5;margin:0">
                    Please do not refresh the page manually. <br>Wait for the green "Online" signal.
                </p>
                <button style="width:100%;background:#1e293b;color:white;font-weight:bold;padding:0.75rem;border-radius:0.75rem;border:none;cursor:pointer" onclick="closeModal()">
                    I Understand
                </button>
             </div>`
        );
    }

</script>
<?php render_footer(); ?>