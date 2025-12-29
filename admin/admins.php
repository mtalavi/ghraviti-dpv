<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
if (!is_super_admin()) {
  http_response_code(403);
  exit('Forbidden');
}

$errors = [];
$notice = null;
$allPermissions = ['manage_users', 'manage_events', 'manage_attendees', 'manage_exports', 'view_logs'];
$defaultPermissions = ['manage_events']; // new admins start with only event access

// Human-readable permission labels with icons
$permissionLabels = [
  'manage_users' => ['icon' => '👥', 'label' => 'Users', 'description' => 'Create, edit, and manage users'],
  'manage_events' => ['icon' => '📅', 'label' => 'Events', 'description' => 'Create and manage events'],
  'manage_attendees' => ['icon' => '✅', 'label' => 'Attendees', 'description' => 'Check-in and manage attendees'],
  'manage_exports' => ['icon' => '📊', 'label' => 'Exports', 'description' => 'Export data and reports'],
  'view_logs' => ['icon' => '📋', 'label' => 'Logs', 'description' => 'View activity logs'],
];

// Human-readable role labels
$roleLabels = [
  'user' => ['label' => 'User', 'color' => 'slate', 'icon' => '👤'],
  'admin' => ['label' => 'Admin', 'color' => 'blue', 'icon' => '⚙️'],
  'super_admin' => ['label' => 'Super Admin', 'color' => 'purple', 'icon' => '👑'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Session expired. Please refresh the page.';
  } else {
    $action = $_POST['action'] ?? '';
    $uid = (int) ($_POST['user_id'] ?? 0);
    if ($action === 'update_role') {
      $role = $_POST['role'] ?? 'admin';
      if (!in_array($role, ['user', 'admin', 'super_admin'], true)) {
        $role = 'admin';
      }
      execute_query('UPDATE users SET role=? WHERE id=?', [$role, $uid]);
      $notice = 'Role updated successfully!';
      log_action('role_change', 'user', $uid, ['role' => $role]);
    } elseif ($action === 'reset_password') {
      $target = fetch_user_decrypted('SELECT email, full_name, dp_code FROM users WHERE id=?', [$uid]);
      if (!$target) {
        $errors[] = 'User not found.';
      } else {
        $newpass = trim($_POST['new_password'] ?? '');
        if ($newpass === '') {
          $newpass = generate_password(12);
        } elseif (strlen($newpass) < 8) {
          $errors[] = 'New password must be at least 8 characters.';
        }
        if (!$errors) {
          execute_query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($newpass, PASSWORD_DEFAULT), $uid]);
          log_action('reset_password', 'user', $uid);
          if (!empty($target['email'])) {
            sendDPVEmail('pass_change', $target['email'], $target['full_name']);
          }
          $notice = 'Password reset successfully! New password: ' . $newpass;
        }
      }
    } elseif ($action === 'delete') {
      if ($uid === (int) (current_user()['id'] ?? 0)) {
        $errors[] = 'You cannot delete your own account.';
      } else {
        execute_query('DELETE FROM users WHERE id=?', [$uid]);
        $notice = 'User deleted successfully.';
        log_action('delete_user', 'user', $uid);
      }
    } elseif ($action === 'save_permissions') {
      $allowed = $_POST['perm'] ?? [];
      $allowed = array_values(array_intersect($allPermissions, $allowed));
      save_admin_permissions($uid, $allPermissions, $allowed);
      $notice = 'Permissions updated successfully!';
      log_action('update_permissions', 'user', $uid, ['perms' => $allowed]);
    } elseif ($action === 'seed_permissions') {
      // reset admin permissions to default for this user
      save_admin_permissions($uid, $allPermissions, $defaultPermissions);
      $notice = 'Permissions reset to default (Events only).';
      log_action('update_permissions', 'user', $uid, ['perms' => $defaultPermissions, 'reset' => true]);
    }
  }
}

$q = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT id, dp_code, full_name, email, mobile, emirates_id, emirate, role, created_at FROM users WHERE role='admin'";
if ($q !== '') {
  // For encrypted fields, use blind index for exact match + dp_code LIKE
  $emailHash = function_exists('blind_index') ? blind_index($q) : null;
  $mobileHash = function_exists('blind_index') ? blind_index($q) : null;

  $searchParts = ["dp_code LIKE ?"];
  $like = "%$q%";
  $params[] = $like;

  if ($emailHash) {
    $searchParts[] = "email_hash = ?";
    $params[] = $emailHash;
  }
  if ($mobileHash) {
    $searchParts[] = "mobile_hash = ?";
    $params[] = $mobileHash;
  }

  $sql .= " AND (" . implode(" OR ", $searchParts) . ")";
}
$sql .= " ORDER BY created_at DESC LIMIT 200";
$admins = fetch_users_decrypted($sql, $params);

// Client-side filter for name search (full_name is encrypted)
if ($q !== '' && $admins) {
  $admins = array_filter($admins, function ($u) use ($q) {
    if (stripos($u['dp_code'] ?? '', $q) !== false)
      return true;
    if (stripos($u['full_name'] ?? '', $q) !== false)
      return true;
    if (stripos($u['email'] ?? '', $q) !== false)
      return true;
    if (stripos($u['mobile'] ?? '', $q) !== false)
      return true;
    return false;
  });
  $admins = array_values($admins);
}

render_header('Admin Users');
?>
<style>
  /* Enhanced Admin Page Styles */
  .admin-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 24px;
    padding: 28px;
    color: white;
    margin-bottom: 24px;
    box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
  }

  .admin-header h1 {
    font-size: 1.75rem;
    font-weight: 700;
    margin-bottom: 8px;
  }

  .admin-header p {
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

  /* Stats Grid */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }

  .stat-card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
    text-align: center;
  }

  .stat-value {
    font-size: 2rem;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .stat-label {
    font-size: 0.85rem;
    color: #64748b;
    margin-top: 4px;
  }

  /* Admin Cards - Mobile */
  .admin-card {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
    margin-bottom: 16px;
    transition: all 0.3s ease;
  }

  .admin-card:hover {
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
  }

  .admin-card-header {
    padding: 16px 20px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
  }

  .admin-info {
    flex: 1;
  }

  .admin-dp {
    font-family: 'Monaco', 'Menlo', monospace;
    font-size: 1rem;
    font-weight: 700;
    color: #1e293b;
    letter-spacing: 0.5px;
  }

  .admin-name {
    font-size: 0.9rem;
    color: #475569;
    margin-top: 2px;
  }

  .admin-contact {
    font-size: 0.8rem;
    color: #64748b;
    margin-top: 4px;
  }

  .role-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }

  .role-badge.admin {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    border: 1px solid #93c5fd;
  }

  .role-badge.super_admin {
    background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
    color: #7c3aed;
    border: 1px solid #c4b5fd;
  }

  .role-badge.user {
    background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
    color: #475569;
    border: 1px solid #cbd5e1;
  }

  .admin-card-body {
    padding: 20px;
    display: none;
  }

  .admin-card.expanded .admin-card-body {
    display: block;
  }

  .expand-icon {
    font-size: 1.2rem;
    transition: transform 0.3s ease;
    color: #94a3b8;
  }

  .admin-card.expanded .expand-icon {
    transform: rotate(180deg);
  }

  /* Permission Chips */
  .permissions-section {
    margin-bottom: 20px;
  }

  .permissions-title {
    font-size: 0.85rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .permission-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .perm-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 14px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    background: #f8fafc;
    color: #64748b;
    cursor: pointer;
    user-select: none;
    transition: all 0.2s ease;
    font-size: 0.85rem;
  }

  .perm-chip:hover {
    border-color: #cbd5e1;
    background: #f1f5f9;
  }

  .perm-chip input {
    display: none;
  }

  .perm-chip.perm-active {
    border-color: #10b981;
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.2);
  }

  .perm-icon {
    font-size: 1rem;
  }

  .perm-label {
    font-weight: 500;
  }

  /* Action Buttons */
  .action-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .action-group {
    background: #f8fafc;
    border-radius: 14px;
    padding: 14px;
  }

  .action-group-title {
    font-size: 0.75rem;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
  }

  .action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 10px 16px;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 500;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    width: 100%;
  }

  .action-btn.primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
  }

  .action-btn.primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
  }

  .action-btn.secondary {
    background: white;
    color: #475569;
    border: 1px solid #e2e8f0;
  }

  .action-btn.secondary:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
  }

  .action-btn.danger {
    background: white;
    color: #dc2626;
    border: 1px solid #fecaca;
  }

  .action-btn.danger:hover {
    background: #fef2f2;
    border-color: #f87171;
  }

  /* Desktop Table */
  .admin-table-container {
    background: white;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
  }

  .admin-table {
    width: 100%;
    border-collapse: collapse;
  }

  .admin-table thead {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  }

  .admin-table th {
    padding: 16px 20px;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #e2e8f0;
  }

  .admin-table td {
    padding: 16px 20px;
    vertical-align: top;
    border-bottom: 1px solid #f1f5f9;
  }

  .admin-table tbody tr:hover {
    background: #fafbfc;
  }

  .admin-table tbody tr:last-child td {
    border-bottom: none;
  }

  /* Quick Actions Row */
  .quick-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
  }

  .quick-btn {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
    border: none;
    cursor: pointer;
  }

  .quick-btn.edit {
    background: #dbeafe;
    color: #1e40af;
  }

  .quick-btn.edit:hover {
    background: #bfdbfe;
  }

  .quick-btn.manage {
    background: #f3e8ff;
    color: #7c3aed;
  }

  .quick-btn.manage:hover {
    background: #e9d5ff;
  }

  /* Search Box */
  .search-container {
    background: white;
    border-radius: 20px;
    padding: 16px 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
    border: 1px solid #e2e8f0;
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .search-input {
    flex: 1;
    padding: 12px 16px;
    border-radius: 12px;
    border: 2px solid #e2e8f0;
    font-size: 0.9rem;
    transition: all 0.2s ease;
  }

  .search-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
  }

  .search-btn {
    padding: 12px 24px;
    border-radius: 12px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 500;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
  }

  .search-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(102, 126, 234, 0.3);
  }

  /* Modal */
  .admin-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
    z-index: 1000;
    padding: 20px;
    overflow-y: auto;
  }

  .admin-modal.active {
    display: flex;
    justify-content: center;
    align-items: flex-start;
  }

  .modal-content {
    background: white;
    border-radius: 24px;
    width: 100%;
    max-width: 500px;
    margin-top: 60px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
    animation: modalSlide 0.3s ease;
  }

  @keyframes modalSlide {
    from {
      opacity: 0;
      transform: translateY(-20px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .modal-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #1e293b;
  }

  .modal-close {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #f1f5f9;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    color: #64748b;
    transition: all 0.2s ease;
  }

  .modal-close:hover {
    background: #e2e8f0;
    color: #1e293b;
  }

  .modal-body {
    padding: 24px;
  }

  /* Alerts */
  .alert {
    padding: 14px 18px;
    border-radius: 14px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.9rem;
  }

  .alert-success {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    color: #065f46;
    border: 1px solid #34d399;
  }

  .alert-error {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
    border: 1px solid #f87171;
  }

  /* Mobile specific */
  @media (max-width: 768px) {
    .admin-header {
      padding: 20px;
      border-radius: 20px;
    }

    .admin-header h1 {
      font-size: 1.4rem;
    }

    .header-actions {
      flex-direction: column;
    }

    .header-btn {
      text-align: center;
      justify-content: center;
    }

    .stats-grid {
      grid-template-columns: repeat(2, 1fr);
    }

    .stat-card {
      padding: 14px;
    }

    .stat-value {
      font-size: 1.5rem;
    }

    .desktop-view {
      display: none !important;
    }

    .mobile-view {
      display: block !important;
    }
  }

  @media (min-width: 769px) {
    .mobile-view {
      display: none !important;
    }

    .desktop-view {
      display: block !important;
    }
  }

  /* Input in table */
  .table-input {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.85rem;
    width: 100%;
    max-width: 180px;
    transition: all 0.2s ease;
  }

  .table-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
  }

  .table-select {
    padding: 8px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.85rem;
    background: white;
    cursor: pointer;
  }

  .inline-form {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    margin-bottom: 8px;
  }
</style>

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">
  <!-- Header -->
  <div class="admin-header">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
      <div>
        <p class="text-xs uppercase opacity-75 mb-1">👑 Super Admin Panel</p>
        <h1>Admin Management</h1>
        <p>Manage admin users, roles, and granular permissions</p>
      </div>
      <div class="header-actions">
        <a href="<?= BASE_URL ?>/admin/admin_reports.php" class="header-btn">
          📊 Activity Reports
        </a>
        <a href="<?= BASE_URL ?>/admin/users.php" class="header-btn">
          ➕ Create User
        </a>
        <a href="<?= dashboard_url() ?>" class="header-btn">
          🏠 Dashboard
        </a>
      </div>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($notice): ?>
    <div class="alert alert-success">
      <span>✅</span>
      <span><?= h($notice) ?></span>
    </div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-error">
      <span>⚠️</span>
      <span><?= implode('<br>', array_map('h', $errors)) ?></span>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><?= count($admins) ?></div>
      <div class="stat-label">Total Admins</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= count(array_filter($admins, fn($a) => ($a['role'] ?? '') === 'super_admin')) ?></div>
      <div class="stat-label">Super Admins</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= count(array_filter($admins, fn($a) => ($a['role'] ?? '') === 'admin')) ?></div>
      <div class="stat-label">Regular Admins</div>
    </div>
  </div>

  <!-- Search -->
  <form method="get" class="search-container">
    <span style="font-size: 1.2rem;">🔍</span>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by DP code, name, email, or mobile..."
      class="search-input">
    <button type="submit" class="search-btn">Search</button>
  </form>

  <!-- Mobile View -->
  <div class="mobile-view space-y-4">
    <?php if (!$admins): ?>
      <div class="admin-card">
        <div class="admin-card-header">
          <div class="admin-info">
            <div class="admin-dp">No admin users found</div>
            <div class="admin-name text-slate-500">Try adjusting your search criteria</div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($admins as $row): ?>
      <?php
      $perms = permissions_for_user($row['id']);
      $roleInfo = $roleLabels[$row['role']] ?? $roleLabels['admin'];
      ?>
      <div class="admin-card" data-admin-id="<?= $row['id'] ?>">
        <div class="admin-card-header" onclick="toggleCard(this.parentElement)">
          <div class="admin-info">
            <div class="admin-dp"><?= h($row['dp_code']) ?></div>
            <div class="admin-name"><?= h($row['full_name']) ?></div>
            <div class="admin-contact">
              <?php if ($row['email']): ?>📧 <?= h($row['email']) ?><?php endif; ?>
              <?php if ($row['mobile']): ?> • 📱 <?= h($row['mobile']) ?><?php endif; ?>
            </div>
          </div>
          <span class="role-badge <?= $row['role'] ?>">
            <?= $roleInfo['icon'] ?>   <?= $roleInfo['label'] ?>
          </span>
          <span class="expand-icon">▼</span>
        </div>

        <div class="admin-card-body">
          <!-- Permissions Section -->
          <div class="permissions-section">
            <div class="permissions-title">🔐 Access Permissions</div>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="save_permissions">
              <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
              <div class="permission-grid">
                <?php foreach ($allPermissions as $permKey): ?>
                  <?php
                  $active = array_key_exists($permKey, $perms) ? $perms[$permKey] : in_array($permKey, $defaultPermissions, true);
                  $permInfo = $permissionLabels[$permKey] ?? ['icon' => '📋', 'label' => ucfirst(str_replace('_', ' ', $permKey))];
                  ?>
                  <label class="perm-chip <?= $active ? 'perm-active' : '' ?>">
                    <input type="checkbox" name="perm[]" value="<?= $permKey ?>" <?= $active ? 'checked' : '' ?>>
                    <span class="perm-icon"><?= $permInfo['icon'] ?></span>
                    <span class="perm-label"><?= $permInfo['label'] ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div style="margin-top: 12px; display: flex; gap: 8px;">
                <button class="action-btn primary" type="submit" style="flex: 1;">💾 Save Permissions</button>
              </div>
            </form>
            <form method="post" style="margin-top: 8px;">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="action" value="seed_permissions">
              <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
              <button class="action-btn secondary" type="submit" style="width: 100%;">🔄 Reset to Default</button>
            </form>
          </div>

          <!-- Actions Section -->
          <div class="action-section">
            <div class="action-group">
              <div class="action-group-title">Quick Actions</div>
              <a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $row['id'] ?>" class="action-btn secondary"
                style="margin-bottom: 8px;">
                ✏️ Edit Profile
              </a>
            </div>

            <div class="action-group">
              <div class="action-group-title">Change Role</div>
              <form method="post" style="display: flex; gap: 8px;">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="update_role">
                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                <select name="role" class="table-select" style="flex: 1;">
                  <?php foreach ($roleLabels as $roleKey => $roleData): ?>
                    <option value="<?= $roleKey ?>" <?= $row['role'] === $roleKey ? 'selected' : '' ?>>
                      <?= $roleData['icon'] ?>     <?= $roleData['label'] ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="action-btn primary" style="width: auto;">Save</button>
              </form>
            </div>

            <div class="action-group">
              <div class="action-group-title">Reset Password</div>
              <form method="post" style="display: flex; gap: 8px;">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                <input type="text" name="new_password" placeholder="Auto-generate if empty" class="table-input"
                  style="flex: 1;">
                <button type="submit" class="action-btn secondary" style="width: auto;">🔑 Reset</button>
              </form>
            </div>

            <div class="action-group">
              <div class="action-group-title">Danger Zone</div>
              <form method="post"
                onsubmit="return confirm('⚠️ Are you sure you want to delete this admin? This action cannot be undone!');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                <button type="submit" class="action-btn danger" style="width: 100%;">🗑️ Delete Admin</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Desktop View -->
  <div class="desktop-view">
    <div class="admin-table-container">
      <table class="admin-table">
        <thead>
          <tr>
            <th>Admin</th>
            <th>Contact</th>
            <th>Role</th>
            <th>Permissions</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$admins): ?>
            <tr>
              <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                No admin users found. Try adjusting your search criteria.
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($admins as $row): ?>
            <?php
            $perms = permissions_for_user($row['id']);
            $roleInfo = $roleLabels[$row['role']] ?? $roleLabels['admin'];
            ?>
            <tr>
              <td>
                <div class="admin-dp"><?= h($row['dp_code']) ?></div>
                <div class="admin-name"><?= h($row['full_name']) ?></div>
              </td>
              <td>
                <?php if ($row['email']): ?>
                  <div style="font-size: 0.85rem; color: #475569;">📧 <?= h($row['email']) ?></div>
                <?php endif; ?>
                <?php if ($row['mobile']): ?>
                  <div style="font-size: 0.85rem; color: #64748b;">📱 <?= h($row['mobile']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="role-badge <?= $row['role'] ?>">
                  <?= $roleInfo['icon'] ?>   <?= $roleInfo['label'] ?>
                </span>
              </td>
              <td>
                <form method="post" style="display: flex; flex-direction: column; gap: 8px;">
                  <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                  <input type="hidden" name="action" value="save_permissions">
                  <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                  <div class="permission-grid">
                    <?php foreach ($allPermissions as $permKey): ?>
                      <?php
                      $active = array_key_exists($permKey, $perms) ? $perms[$permKey] : in_array($permKey, $defaultPermissions, true);
                      $permInfo = $permissionLabels[$permKey] ?? ['icon' => '📋', 'label' => ucfirst(str_replace('_', ' ', $permKey))];
                      ?>
                      <label class="perm-chip <?= $active ? 'perm-active' : '' ?>"
                        title="<?= $permInfo['description'] ?? '' ?>">
                        <input type="checkbox" name="perm[]" value="<?= $permKey ?>" <?= $active ? 'checked' : '' ?>>
                        <span class="perm-icon"><?= $permInfo['icon'] ?></span>
                        <span class="perm-label"><?= $permInfo['label'] ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <div style="display: flex; gap: 6px;">
                    <button type="submit" class="quick-btn edit" style="padding: 6px 10px;">💾 Save</button>
                  </div>
                </form>
              </td>
              <td>
                <div style="display: flex; flex-direction: column; gap: 8px; min-width: 200px;">
                  <a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $row['id'] ?>" class="quick-btn edit">
                    ✏️ Edit Profile
                  </a>

                  <button type="button" class="quick-btn manage" onclick="openModal('modal-<?= $row['id'] ?>')">
                    ⚙️ Manage
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modals for Desktop Actions -->
<?php foreach ($admins as $row): ?>
  <?php $roleInfo = $roleLabels[$row['role']] ?? $roleLabels['admin']; ?>
  <div class="admin-modal" id="modal-<?= $row['id'] ?>">
    <div class="modal-content">
      <div class="modal-header">
        <span class="modal-title">⚙️ Manage <?= h($row['dp_code']) ?></span>
        <button class="modal-close" onclick="closeModal('modal-<?= $row['id'] ?>')">&times;</button>
      </div>
      <div class="modal-body">
        <div style="margin-bottom: 20px;">
          <div class="action-group-title" style="margin-bottom: 8px;">Change Role</div>
          <form method="post" style="display: flex; gap: 8px;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
            <select name="role" class="table-select" style="flex: 1;">
              <?php foreach ($roleLabels as $roleKey => $roleData): ?>
                <option value="<?= $roleKey ?>" <?= $row['role'] === $roleKey ? 'selected' : '' ?>>
                  <?= $roleData['icon'] ?>     <?= $roleData['label'] ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="action-btn primary" style="width: auto; padding: 10px 20px;">Save</button>
          </form>
        </div>

        <div style="margin-bottom: 20px;">
          <div class="action-group-title" style="margin-bottom: 8px;">Reset Password</div>
          <form method="post" style="display: flex; gap: 8px;">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
            <input type="text" name="new_password" placeholder="Auto-generate if empty" class="table-input"
              style="flex: 1;">
            <button type="submit" class="action-btn secondary" style="width: auto;">🔑 Reset</button>
          </form>
        </div>

        <div style="margin-bottom: 20px;">
          <div class="action-group-title" style="margin-bottom: 8px;">Reset Permissions</div>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="seed_permissions">
            <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
            <button type="submit" class="action-btn secondary" style="width: 100%;">🔄 Reset to Default
              Permissions</button>
          </form>
        </div>

        <div>
          <div class="action-group-title" style="margin-bottom: 8px; color: #dc2626;">⚠️ Danger Zone</div>
          <form method="post"
            onsubmit="return confirm('⚠️ Are you sure you want to delete this admin? This action cannot be undone!');">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
            <button type="submit" class="action-btn danger" style="width: 100%;">🗑️ Delete Admin</button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<script>
  // Toggle mobile card expansion
  function toggleCard(card) {
    card.classList.toggle('expanded');
  }

  // Permission chip toggle - improved to handle label clicks correctly
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.perm-chip').forEach(function (chip) {
      var cb = chip.querySelector('input[type="checkbox"]');
      if (!cb) return;

      // Listen for the checkbox change event (which handles both direct and label clicks)
      cb.addEventListener('change', function () {
        chip.classList.toggle('perm-active', cb.checked);
      });

      // Initialize active state
      chip.classList.toggle('perm-active', cb.checked);
    });
  });

  // Modal functions
  function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
  }

  // Close modal on outside click
  document.querySelectorAll('.admin-modal').forEach(modal => {
    modal.addEventListener('click', function (e) {
      if (e.target === this) {
        closeModal(this.id);
      }
    });
  });

  // Close modal on Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.admin-modal.active').forEach(modal => {
        closeModal(modal.id);
      });
    }
  });
</script>
<?php render_footer(); ?>