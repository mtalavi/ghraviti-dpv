<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('view_logs');

$logs = fetch_all(
  'SELECT l.*, u.full_name FROM activity_logs l LEFT JOIN users u ON l.actor_user_id=u.id ORDER BY l.created_at DESC LIMIT 200'
);

render_header('Activity Logs');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">📋 Audit Trail</p>
        <h1 class="dpv-page-header__title">Activity Log</h1>
        <p class="dpv-page-header__subtitle">Security-first audit trail for all critical actions.</p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= dashboard_url() ?>" class="dpv-page-header__btn">
          🏠 Dashboard
        </a>
      </div>
    </div>
  </div>
  <div class="bg-white rounded-3xl shadow border border-slate-100 overflow-auto">
    <table class="min-w-full table-lite">
      <thead class="bg-slate-50">
        <tr>
          <th>When</th>
          <th>Actor</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Meta</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$logs): ?>
          <tr>
            <td colspan="6" class="p-4 text-slate-500">No logs yet.</td>
          </tr><?php endif; ?>
        <?php foreach ($logs as $log): ?>
          <tr class="border-t border-slate-100 align-top">
            <td class="text-sm text-slate-600"><?= format_dubai_datetime($log['created_at'], 'full') ?></td>
            <td class="text-sm text-slate-800"><?= h($log['full_name'] ?: 'System') ?></td>
            <td class="text-sm font-semibold text-slate-900"><?= h($log['action']) ?></td>
            <td class="text-sm text-slate-700"><?= h($log['entity_type']) ?>
              <?= $log['entity_id'] ? '#' . h($log['entity_id']) : '' ?>
            </td>
            <td class="text-xs text-slate-600 whitespace-pre-wrap"><?= h($log['meta']) ?></td>
            <td class="text-xs text-slate-500"><?= h($log['ip_address']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>