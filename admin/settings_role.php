<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
if (!is_super_admin()) {
  http_response_code(403);
  exit('Forbidden');
}

$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please refresh the page.';
  } else {
    $val = trim($_POST['general_role_title'] ?? '');
    settings_set('general_role_title', $val);
    $notice = 'General role title updated.';
  }
}

$current = settings_get('general_role_title', '');

render_header('General Role Title');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">⚙️ Settings</p>
        <h1 class="dpv-page-header__title">General Role Title</h1>
        <p class="dpv-page-header__subtitle">Default role label shown on cards for new users (overridden if a
          user-specific role title is set).</p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= dashboard_url() ?>" class="dpv-page-header__btn">
          🏠 Dashboard
        </a>
      </div>
    </div>
  </div>

  <?php if ($notice): ?>
    <div class="dpv-alert dpv-alert--success">
      <span>✅</span>
      <span><?= h($notice) ?></span>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="dpv-alert dpv-alert--error">
      <span>⚠️</span>
      <span><?= h($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="post" class="bg-white rounded-3xl shadow border border-slate-100 p-6 space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div class="flex flex-col">
      <label class="text-sm font-semibold text-slate-700">General Role Title (default for new users)</label>
      <input name="general_role_title" class="input-field mt-1" value="<?= h($current) ?>"
        placeholder="e.g., DP Volunteer ID Card">
      <p class="text-xs text-slate-500 mt-1">Shown on card when user-specific role title is empty.</p>
    </div>
    <button class="btn-primary" type="submit">Save</button>
  </form>
</div>
<?php render_footer(); ?>