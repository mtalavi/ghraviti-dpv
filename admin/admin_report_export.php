<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
if (!is_super_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$adminId = isset($_GET['admin_id']) ? (int) $_GET['admin_id'] : null;
$isSummary = isset($_GET['summary']) && $_GET['summary'] === '1';
$isDetailed = isset($_GET['detailed']) && $_GET['detailed'] === '1';

header('Content-Type: text/csv; charset=UTF-8');

// UTF-8 BOM for Excel/Arabic support
echo chr(0xEF) . chr(0xBB) . chr(0xBF);

$out = fopen('php://output', 'w');

if ($isSummary) {
    // Summary export: All admins with stats
    header('Content-Disposition: attachment; filename="admin_summary_report_' . date('Y-m-d') . '.csv"');

    fputcsv($out, ['Admin Name', 'DP Code', 'Email', 'Users Created', 'Total Actions', 'Last Activity']);

    $admins = fetch_users_decrypted("
        SELECT 
            u.id,
            u.dp_code,
            u.full_name,
            u.email,
            (SELECT COUNT(*) FROM users created WHERE created.created_by = u.id) as users_created,
            (SELECT COUNT(*) FROM activity_logs WHERE actor_user_id = u.id) as total_actions,
            (SELECT MAX(created_at) FROM activity_logs WHERE actor_user_id = u.id) as last_activity
        FROM users u
        WHERE u.role = 'admin'
        ORDER BY users_created DESC
    ");

    foreach ($admins as $admin) {
        fputcsv($out, [
            $admin['full_name'],
            $admin['dp_code'],
            $admin['email'],
            (int) $admin['users_created'],
            (int) $admin['total_actions'],
            $admin['last_activity'] ?: 'Never'
        ]);
    }

} elseif ($adminId) {
    $admin = fetch_user_decrypted("SELECT * FROM users WHERE id = ? AND role = 'admin'", [$adminId]);

    if (!$admin) {
        exit('Admin not found');
    }

    $filename = 'admin_report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $admin['dp_code']) . '_' . date('Y-m-d') . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    if ($isDetailed) {
        // Detailed export: Users created + All activities
        fputcsv($out, ['=== ADMIN INFO ===']);
        fputcsv($out, ['Admin Name', $admin['full_name']]);
        fputcsv($out, ['DP Code', $admin['dp_code']]);
        fputcsv($out, ['Email', $admin['email']]);
        fputcsv($out, ['Report Date', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        // Users created section
        fputcsv($out, ['=== USERS REGISTERED ===']);
        $users = fetch_users_decrypted(
            "SELECT dp_code, full_name, email, mobile, emirate, profession, created_at 
             FROM users WHERE created_by = ? ORDER BY created_at DESC",
            [$adminId]
        );

        if ($users) {
            fputcsv($out, ['DP Code', 'Full Name', 'Email', 'Mobile', 'Emirate', 'Profession', 'Created At']);
            foreach ($users as $user) {
                fputcsv($out, [
                    $user['dp_code'],
                    $user['full_name'],
                    $user['email'],
                    $user['mobile'],
                    $user['emirate'],
                    $user['profession'],
                    $user['created_at']
                ]);
            }
        } else {
            fputcsv($out, ['No users registered']);
        }

        fputcsv($out, []);

        // Activities section
        fputcsv($out, ['=== ACTIVITY LOG ===']);
        $activities = fetch_all(
            "SELECT action, entity_type, entity_id, meta, ip_address, created_at 
             FROM activity_logs WHERE actor_user_id = ? ORDER BY created_at DESC",
            [$adminId]
        );

        if ($activities) {
            fputcsv($out, ['Action', 'Entity Type', 'Entity ID', 'Details', 'IP Address', 'Date']);
            foreach ($activities as $act) {
                fputcsv($out, [
                    $act['action'],
                    $act['entity_type'],
                    $act['entity_id'],
                    $act['meta'],
                    $act['ip_address'],
                    $act['created_at']
                ]);
            }
        } else {
            fputcsv($out, ['No activities recorded']);
        }

    } else {
        // Simple export: Just activities with DP numbers
        fputcsv($out, ['Action', 'Entity Type', 'Entity ID', 'Details (DP Numbers etc)', 'IP Address', 'Date']);

        $activities = fetch_all(
            "SELECT action, entity_type, entity_id, meta, ip_address, created_at 
             FROM activity_logs WHERE actor_user_id = ? ORDER BY created_at DESC",
            [$adminId]
        );

        foreach ($activities as $act) {
            fputcsv($out, [
                $act['action'],
                $act['entity_type'],
                $act['entity_id'],
                $act['meta'],
                $act['ip_address'],
                $act['created_at']
            ]);
        }
    }

} else {
    exit('Please specify admin_id or summary=1');
}

fclose($out);
exit;
