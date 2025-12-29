<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
if (!is_super_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

// Human-readable action labels with icons and descriptions
$actionLabels = [
    'create_user' => ['icon' => '👤', 'label' => 'Created User', 'color' => 'emerald', 'description' => 'Registered a new user in the system'],
    'update_user' => ['icon' => '✏️', 'label' => 'Updated User', 'color' => 'blue', 'description' => 'Modified user profile information'],
    'delete_user' => ['icon' => '🗑️', 'label' => 'Deleted User', 'color' => 'red', 'description' => 'Removed user from the system'],
    'login' => ['icon' => '🔓', 'label' => 'Logged In', 'color' => 'purple', 'description' => 'Admin signed into the system'],
    'logout' => ['icon' => '🔒', 'label' => 'Logged Out', 'color' => 'slate', 'description' => 'Admin signed out of the system'],
    'role_change' => ['icon' => '👑', 'label' => 'Changed Role', 'color' => 'amber', 'description' => 'Modified user role/permissions level'],
    'reset_password' => ['icon' => '🔑', 'label' => 'Reset Password', 'color' => 'orange', 'description' => 'Reset password for a user'],
    'update_permissions' => ['icon' => '🔐', 'label' => 'Updated Permissions', 'color' => 'cyan', 'description' => 'Changed user access permissions'],
    'check_in' => ['icon' => '✅', 'label' => 'Event Check-in', 'color' => 'green', 'description' => 'Checked in attendee at event'],
    'check_out' => ['icon' => '🚪', 'label' => 'Event Check-out', 'color' => 'gray', 'description' => 'Checked out attendee from event'],
    'create_event' => ['icon' => '📅', 'label' => 'Created Event', 'color' => 'indigo', 'description' => 'Created a new event'],
    'update_event' => ['icon' => '📝', 'label' => 'Updated Event', 'color' => 'violet', 'description' => 'Modified event details'],
    'delete_event' => ['icon' => '❌', 'label' => 'Deleted Event', 'color' => 'rose', 'description' => 'Removed an event'],
    'export_data' => ['icon' => '📊', 'label' => 'Exported Data', 'color' => 'teal', 'description' => 'Exported system data'],
    'vest_assign' => ['icon' => '🦺', 'label' => 'Assigned Vest', 'color' => 'yellow', 'description' => 'Assigned vest to attendee'],
    'vest_return' => ['icon' => '↩️', 'label' => 'Vest Returned', 'color' => 'lime', 'description' => 'Attendee returned vest'],
];

// Entity type labels
$entityLabels = [
    'user' => ['icon' => '👤', 'label' => 'User'],
    'event' => ['icon' => '📅', 'label' => 'Event'],
    'attendee' => ['icon' => '🎫', 'label' => 'Attendee'],
    'vest' => ['icon' => '🦺', 'label' => 'Vest'],
    'export' => ['icon' => '📊', 'label' => 'Export'],
    'session' => ['icon' => '🔐', 'label' => 'Session'],
];

/**
 * Format meta JSON to human-readable format
 */
function formatMeta($metaJson, $action)
{
    if (empty($metaJson))
        return null;

    $meta = json_decode($metaJson, true);
    if (!is_array($meta))
        return null;

    $parts = [];

    // Handle common meta fields
    if (isset($meta['role'])) {
        $roleLabels = ['user' => 'User', 'admin' => 'Admin', 'super_admin' => 'Super Admin'];
        $parts[] = 'New role: ' . ($roleLabels[$meta['role']] ?? $meta['role']);
    }

    if (isset($meta['perms'])) {
        $permLabels = [
            'manage_users' => 'Users',
            'manage_events' => 'Events',
            'manage_attendees' => 'Attendees',
            'manage_exports' => 'Exports',
            'view_logs' => 'Logs',
        ];
        $readablePerms = array_map(fn($p) => $permLabels[$p] ?? $p, $meta['perms']);
        $parts[] = 'Permissions: ' . (empty($readablePerms) ? 'None' : implode(', ', $readablePerms));
    }

    if (isset($meta['reset']) && $meta['reset']) {
        $parts[] = '(Reset to default)';
    }

    if (isset($meta['dp_code'])) {
        $parts[] = 'DP: ' . $meta['dp_code'];
    }

    if (isset($meta['event_id'])) {
        $parts[] = 'Event #' . $meta['event_id'];
    }

    if (isset($meta['vest_number'])) {
        $parts[] = 'Vest #' . $meta['vest_number'];
    }

    if (isset($meta['email'])) {
        $parts[] = 'Email: ' . $meta['email'];
    }

    if (isset($meta['status'])) {
        $parts[] = 'Status: ' . ucfirst(str_replace('_', ' ', $meta['status']));
    }

    if (isset($meta['reason'])) {
        $parts[] = 'Reason: ' . $meta['reason'];
    }

    // If no specific handling, show key-value pairs nicely
    if (empty($parts)) {
        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $readableKey = ucfirst(str_replace('_', ' ', $key));
            $parts[] = "$readableKey: $value";
        }
    }

    return empty($parts) ? null : implode(' • ', $parts);
}

/**
 * Format time ago
 */
function timeAgo($datetime)
{
    $time = strtotime($datetime);
    $diff = time() - $time;

    if ($diff < 60)
        return 'Just now';
    if ($diff < 3600)
        return floor($diff / 60) . ' min ago';
    if ($diff < 86400)
        return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800)
        return floor($diff / 86400) . ' days ago';

    return date('M j, Y', $time);
}

$selectedAdminId = isset($_GET['admin_id']) ? (int) $_GET['admin_id'] : null;

// Get all admins with their stats
$admins = fetch_users_decrypted("
    SELECT 
        u.id,
        u.dp_code,
        u.full_name,
        u.email,
        u.created_at,
        (SELECT COUNT(*) FROM users created WHERE created.created_by = u.id) as users_created,
        (SELECT COUNT(*) FROM activity_logs WHERE actor_user_id = u.id) as total_actions,
        (SELECT MAX(created_at) FROM activity_logs WHERE actor_user_id = u.id) as last_activity
    FROM users u
    WHERE u.role = 'admin'
    ORDER BY users_created DESC, u.created_at DESC
");

// Get details for selected admin
$adminDetails = null;
$registeredUsers = [];
$recentActivities = [];
$activityStats = [];

if ($selectedAdminId) {
    $adminDetails = fetch_user_decrypted("SELECT * FROM users WHERE id = ? AND role = 'admin'", [$selectedAdminId]);

    if ($adminDetails) {
        // Users registered by this admin
        $registeredUsers = fetch_users_decrypted(
            "SELECT id, dp_code, full_name, email, created_at FROM users WHERE created_by = ? ORDER BY created_at DESC LIMIT 100",
            [$selectedAdminId]
        );

        // Recent activities
        $recentActivities = fetch_all(
            "SELECT action, entity_type, entity_id, meta, ip_address, created_at 
             FROM activity_logs 
             WHERE actor_user_id = ? 
             ORDER BY created_at DESC 
             LIMIT 100",
            [$selectedAdminId]
        );

        // Decrypt user names for entity_type='user'
        foreach ($recentActivities as &$act) {
            $act['entity_name'] = null;
            $act['entity_dp_code'] = null;
            if ($act['entity_type'] === 'user' && !empty($act['entity_id'])) {
                $entityUser = fetch_user_decrypted('SELECT dp_code, full_name FROM users WHERE id = ?', [$act['entity_id']]);
                if ($entityUser) {
                    $act['entity_name'] = $entityUser['full_name'];
                    $act['entity_dp_code'] = $entityUser['dp_code'];
                }
            }
        }
        unset($act);

        // Activity stats by action type
        $activityStatsRaw = fetch_all(
            "SELECT action, COUNT(*) as count 
             FROM activity_logs 
             WHERE actor_user_id = ? 
             GROUP BY action 
             ORDER BY count DESC",
            [$selectedAdminId]
        );

        foreach ($activityStatsRaw as $stat) {
            $activityStats[$stat['action']] = (int) $stat['count'];
        }
    }
}

render_header('Admin Activity Reports');
?>
<style>
    /* Enhanced Reports Page Styles */
    .reports-header {
        background: linear-gradient(135deg, #059669 0%, #047857 100%);
        border-radius: 24px;
        padding: 28px;
        color: white;
        margin-bottom: 24px;
        box-shadow: 0 10px 40px rgba(5, 150, 105, 0.3);
    }

    .reports-header h1 {
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .reports-header p {
        opacity: 0.9;
        font-size: 0.9rem;
    }

    .header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
    }

    .header-btn {
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: white;
        padding: 10px 18px;
        border-radius: 12px;
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .header-btn:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: translateY(-2px);
    }

    /* Admin Cards Grid */
    .admins-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .admin-stat-card {
        background: white;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        border: 1px solid #e2e8f0;
        transition: all 0.3s ease;
    }

    .admin-stat-card:hover {
        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }

    .admin-stat-card.selected {
        border-color: #10b981;
        box-shadow: 0 8px 30px rgba(16, 185, 129, 0.2);
    }

    .admin-stat-header {
        padding: 16px 20px;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-bottom: 1px solid #e2e8f0;
    }

    .admin-stat-dp {
        font-family: 'Monaco', 'Menlo', monospace;
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
    }

    .admin-stat-name {
        font-size: 0.85rem;
        color: #64748b;
        margin-top: 2px;
    }

    .admin-stat-body {
        padding: 16px 20px;
    }

    .stat-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .stat-item {
        text-align: center;
    }

    .stat-item-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e293b;
    }

    .stat-item-value.highlight {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .stat-item-label {
        font-size: 0.75rem;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .admin-stat-footer {
        padding: 12px 20px;
        background: #f8fafc;
        border-top: 1px solid #f1f5f9;
        display: flex;
        gap: 8px;
    }

    .stat-btn {
        flex: 1;
        padding: 10px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 500;
        text-decoration: none;
        text-align: center;
        transition: all 0.2s ease;
    }

    .stat-btn.primary {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
    }

    .stat-btn.primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    }

    .stat-btn.secondary {
        background: white;
        color: #475569;
        border: 1px solid #e2e8f0;
    }

    .stat-btn.secondary:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }

    /* Details Panel */
    .details-panel {
        background: white;
        border-radius: 24px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        border: 1px solid #e2e8f0;
    }

    .details-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        border-bottom: 1px solid #bbf7d0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
    }

    .details-title {
        font-size: 1.2rem;
        font-weight: 700;
        color: #166534;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .details-subtitle {
        font-size: 0.85rem;
        color: #15803d;
        margin-top: 4px;
    }

    .details-body {
        padding: 24px;
    }

    /* Activity Stats */
    .activity-stats {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
        gap: 12px;
        margin-bottom: 24px;
    }

    .activity-stat-chip {
        padding: 14px;
        border-radius: 14px;
        text-align: center;
        transition: all 0.2s ease;
    }

    .activity-stat-chip:hover {
        transform: translateY(-2px);
    }

    .activity-stat-chip.emerald {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #065f46;
    }

    .activity-stat-chip.blue {
        background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        color: #1e40af;
    }

    .activity-stat-chip.red {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        color: #991b1b;
    }

    .activity-stat-chip.purple {
        background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
        color: #7c3aed;
    }

    .activity-stat-chip.slate {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        color: #475569;
    }

    .activity-stat-chip.amber {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
        color: #92400e;
    }

    .activity-stat-chip.orange {
        background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
        color: #c2410c;
    }

    .activity-stat-chip.cyan {
        background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
        color: #155e75;
    }

    .activity-stat-chip.green {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
        color: #166534;
    }

    .activity-stat-chip.indigo {
        background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
        color: #3730a3;
    }

    .activity-stat-chip.teal {
        background: linear-gradient(135deg, #ccfbf1 0%, #99f6e4 100%);
        color: #115e59;
    }

    .activity-stat-chip.gray {
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
        color: #374151;
    }

    .activity-stat-chip.yellow {
        background: linear-gradient(135deg, #fef9c3 0%, #fef08a 100%);
        color: #854d0e;
    }

    .activity-stat-chip.lime {
        background: linear-gradient(135deg, #ecfccb 0%, #d9f99d 100%);
        color: #3f6212;
    }

    .activity-stat-chip.violet {
        background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
        color: #5b21b6;
    }

    .activity-stat-chip.rose {
        background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%);
        color: #be123c;
    }

    .activity-stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .activity-stat-label {
        font-size: 0.75rem;
        margin-top: 4px;
    }

    /* Section */
    .section {
        margin-bottom: 24px;
    }

    .section-title {
        font-size: 1rem;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Users Table */
    .users-table-container {
        background: #f8fafc;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }

    .users-table {
        width: 100%;
        border-collapse: collapse;
    }

    .users-table thead {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    }

    .users-table th {
        padding: 14px 16px;
        text-align: left;
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .users-table td {
        padding: 12px 16px;
        border-top: 1px solid #f1f5f9;
        font-size: 0.9rem;
    }

    .users-table tbody tr:hover {
        background: #f1f5f9;
    }

    .dp-badge {
        font-family: 'Monaco', 'Menlo', monospace;
        font-size: 0.85rem;
        font-weight: 600;
        color: #10b981;
        background: #d1fae5;
        padding: 4px 10px;
        border-radius: 8px;
        display: inline-block;
    }

    /* Activity Timeline */
    .activity-timeline {
        max-height: 500px;
        overflow-y: auto;
        background: #f8fafc;
        border-radius: 16px;
        border: 1px solid #e2e8f0;
    }

    .activity-item {
        padding: 16px 20px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        gap: 14px;
        align-items: flex-start;
        transition: background 0.2s ease;
    }

    .activity-item:last-child {
        border-bottom: none;
    }

    .activity-item:hover {
        background: #f1f5f9;
    }

    .activity-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .activity-icon.emerald {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    }

    .activity-icon.blue {
        background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    }

    .activity-icon.red {
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    }

    .activity-icon.purple {
        background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    }

    .activity-icon.slate {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    }

    .activity-icon.amber {
        background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    }

    .activity-icon.orange {
        background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
    }

    .activity-icon.cyan {
        background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%);
    }

    .activity-icon.green {
        background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    }

    .activity-icon.indigo {
        background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
    }

    .activity-icon.teal {
        background: linear-gradient(135deg, #ccfbf1 0%, #99f6e4 100%);
    }

    .activity-icon.gray {
        background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    }

    .activity-icon.yellow {
        background: linear-gradient(135deg, #fef9c3 0%, #fef08a 100%);
    }

    .activity-icon.lime {
        background: linear-gradient(135deg, #ecfccb 0%, #d9f99d 100%);
    }

    .activity-icon.violet {
        background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
    }

    .activity-icon.rose {
        background: linear-gradient(135deg, #ffe4e6 0%, #fecdd3 100%);
    }

    .activity-content {
        flex: 1;
        min-width: 0;
    }

    .activity-title {
        font-weight: 600;
        color: #1e293b;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .activity-entity {
        font-size: 0.8rem;
        color: #64748b;
        background: #e2e8f0;
        padding: 2px 8px;
        border-radius: 6px;
    }

    .activity-details {
        font-size: 0.85rem;
        color: #475569;
        margin-top: 6px;
        line-height: 1.5;
    }

    .activity-meta {
        display: flex;
        gap: 12px;
        margin-top: 8px;
        font-size: 0.75rem;
        color: #94a3b8;
    }

    .activity-meta-item {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* Mobile Responsive */
    @media (max-width: 768px) {
        .reports-header {
            padding: 20px;
            border-radius: 20px;
        }

        .reports-header h1 {
            font-size: 1.4rem;
        }

        .header-actions {
            flex-direction: column;
        }

        .header-btn {
            text-align: center;
            justify-content: center;
        }

        .admins-grid {
            grid-template-columns: 1fr;
        }

        .details-header {
            flex-direction: column;
            align-items: flex-start;
        }

        .activity-stats {
            grid-template-columns: repeat(2, 1fr);
        }

        .activity-item {
            flex-direction: column;
        }

        .activity-icon {
            width: 36px;
            height: 36px;
            font-size: 1rem;
        }
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #64748b;
    }

    .empty-state-icon {
        font-size: 3rem;
        margin-bottom: 12px;
    }

    .empty-state-text {
        font-size: 0.95rem;
    }
</style>

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
    <!-- Header -->
    <div class="reports-header">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div>
                <p class="text-xs uppercase opacity-75 mb-1">👑 Super Admin Panel</p>
                <h1>
                    <span>📊</span>
                    Activity Reports
                </h1>
                <p>Track admin actions, user registrations, and detailed activity logs</p>
            </div>
            <div class="header-actions">
                <a href="<?= BASE_URL ?>/admin/admin_report_export.php?summary=1" class="header-btn">
                    📥 Export Summary (CSV)
                </a>
                <a href="<?= BASE_URL ?>/admin/admins.php" class="header-btn">
                    👥 Manage Admins
                </a>
                <a href="<?= dashboard_url() ?>" class="header-btn">
                    🏠 Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Admins Grid -->
    <div class="admins-grid">
        <?php if (!$admins): ?>
            <div class="admin-stat-card">
                <div class="empty-state">
                    <div class="empty-state-icon">👤</div>
                    <div class="empty-state-text">No admins found in the system</div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($admins as $admin): ?>
            <div class="admin-stat-card <?= $selectedAdminId === (int) $admin['id'] ? 'selected' : '' ?>">
                <div class="admin-stat-header">
                    <div class="admin-stat-dp"><?= h($admin['dp_code']) ?></div>
                    <div class="admin-stat-name"><?= h($admin['full_name']) ?></div>
                </div>
                <div class="admin-stat-body">
                    <div class="stat-row">
                        <div class="stat-item">
                            <div class="stat-item-value highlight"><?= (int) $admin['users_created'] ?></div>
                            <div class="stat-item-label">Users Created</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-item-value"><?= (int) $admin['total_actions'] ?></div>
                            <div class="stat-item-label">Total Actions</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-item-value" style="font-size: 0.9rem; color: #64748b;">
                                <?= $admin['last_activity'] ? timeAgo($admin['last_activity']) : 'Never' ?>
                            </div>
                            <div class="stat-item-label">Last Active</div>
                        </div>
                    </div>
                </div>
                <div class="admin-stat-footer">
                    <a href="?admin_id=<?= $admin['id'] ?>" class="stat-btn primary">
                        👁️ View Details
                    </a>
                    <a href="<?= BASE_URL ?>/admin/admin_report_export.php?admin_id=<?= $admin['id'] ?>"
                        class="stat-btn secondary">
                        📥 CSV
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($adminDetails): ?>
        <!-- Admin Details Panel -->
        <div class="details-panel">
            <div class="details-header">
                <div>
                    <div class="details-title">
                        <span>👤</span>
                        <?= h($adminDetails['dp_code']) ?> - <?= h($adminDetails['full_name']) ?>
                    </div>
                    <div class="details-subtitle">
                        📧 <?= h($adminDetails['email']) ?>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/admin/admin_report_export.php?admin_id=<?= $adminDetails['id'] ?>&detailed=1"
                    class="stat-btn primary" style="flex: none;">
                    📥 Export Full Report (CSV)
                </a>
            </div>

            <div class="details-body">
                <!-- Activity Stats by Type -->
                <?php if (!empty($activityStats)): ?>
                    <div class="section">
                        <div class="section-title">
                            <span>📈</span>
                            Activity Breakdown
                        </div>
                        <div class="activity-stats">
                            <?php foreach ($activityStats as $action => $count): ?>
                                <?php
                                $actionInfo = $actionLabels[$action] ?? [
                                    'icon' => '📋',
                                    'label' => ucfirst(str_replace('_', ' ', $action)),
                                    'color' => 'slate',
                                    'description' => ''
                                ];
                                ?>
                                <div class="activity-stat-chip <?= $actionInfo['color'] ?>"
                                    title="<?= h($actionInfo['description']) ?>">
                                    <div class="activity-stat-value">
                                        <span><?= $actionInfo['icon'] ?></span>
                                        <?= $count ?>
                                    </div>
                                    <div class="activity-stat-label"><?= h($actionInfo['label']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Users Registered -->
                <div class="section">
                    <div class="section-title">
                        <span>👥</span>
                        Users Registered (<?= count($registeredUsers) ?>)
                    </div>

                    <?php if (!$registeredUsers): ?>
                        <div class="empty-state" style="padding: 24px;">
                            <div class="empty-state-icon">📭</div>
                            <div class="empty-state-text">No users registered by this admin yet</div>
                        </div>
                    <?php else: ?>
                        <div class="users-table-container">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>DP Code</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registeredUsers as $user): ?>
                                        <tr>
                                            <td><span class="dp-badge"><?= h($user['dp_code']) ?></span></td>
                                            <td style="color: #1e293b; font-weight: 500;"><?= h($user['full_name']) ?></td>
                                            <td style="color: #64748b;"><?= h($user['email']) ?></td>
                                            <td style="color: #94a3b8; font-size: 0.85rem;">
                                                <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                                <span style="color: #cbd5e1;">•</span>
                                                <?= date('g:i A', strtotime($user['created_at'])) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Activity Timeline -->
                <div class="section">
                    <div class="section-title">
                        <span>📋</span>
                        Recent Activity (Last 100)
                    </div>

                    <?php if (!$recentActivities): ?>
                        <div class="empty-state" style="padding: 24px;">
                            <div class="empty-state-icon">📭</div>
                            <div class="empty-state-text">No activities recorded for this admin</div>
                        </div>
                    <?php else: ?>
                        <div class="activity-timeline">
                            <?php foreach ($recentActivities as $act): ?>
                                <?php
                                $actionInfo = $actionLabels[$act['action']] ?? [
                                    'icon' => '📋',
                                    'label' => ucfirst(str_replace('_', ' ', $act['action'])),
                                    'color' => 'slate',
                                    'description' => ''
                                ];
                                $entityInfo = $entityLabels[$act['entity_type']] ?? [
                                    'icon' => '📄',
                                    'label' => ucfirst($act['entity_type'] ?? 'Unknown')
                                ];
                                $formattedMeta = formatMeta($act['meta'], $act['action']);
                                ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?= $actionInfo['color'] ?>">
                                        <?= $actionInfo['icon'] ?>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            <?= h($actionInfo['label']) ?>
                                            <?php if ($act['entity_type']): ?>
                                                <span class="activity-entity">
                                                    <?= $entityInfo['icon'] ?>                 <?= h($entityInfo['label']) ?>
                                                    <?php if ($act['entity_id']): ?>
                                                        #<?= h($act['entity_id']) ?>
                                                        <?php if (!empty($act['entity_name'])): ?>
                                                            <strong style="color:#059669;">(<?= h($act['entity_dp_code'] ?? '') ?> -
                                                                <?= h($act['entity_name']) ?>)</strong>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($formattedMeta): ?>
                                            <div class="activity-details">
                                                <?= h($formattedMeta) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="activity-meta">
                                            <span class="activity-meta-item">
                                                <span>🕐</span>
                                                <?= date('M j, Y', strtotime($act['created_at'])) ?> at
                                                <?= date('g:i A', strtotime($act['created_at'])) ?>
                                            </span>
                                            <?php if ($act['ip_address']): ?>
                                                <span class="activity-meta-item">
                                                    <span>🌐</span>
                                                    <?= h($act['ip_address']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>