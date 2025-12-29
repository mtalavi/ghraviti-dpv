<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_exports');

$fields = [
  'dp_code' => 'DP Code',
  'v_number' => 'V Number',
  'full_name' => 'Full Name',
  'full_name_ar' => 'Full Name (Arabic)',
  'gender' => 'Gender',
  'nationality' => 'Nationality',
  'nationality_ar' => 'Nationality (Arabic)',
  'emirate' => 'Emirate',
  'emirate_ar' => 'Emirate (Arabic)',
  'area' => 'Area',
  'email' => 'Email',
  'mobile' => 'Mobile',
  'emirates_id' => 'Emirates ID',
  'emirates_id_expiry' => 'Emirates ID Expiry',
  'date_of_birth' => 'Date of Birth',
  'profession' => 'Profession',
  'skills' => 'Skills'
];

render_header('Custom CSV Export');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">📊 Data Export</p>
        <h1 class="dpv-page-header__title">Custom CSV Export</h1>
        <p class="dpv-page-header__subtitle">Paste DP codes (comma / space / new line), select fields, download CSV.</p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= dashboard_url() ?>" class="dpv-page-header__btn">
          🏠 Dashboard
        </a>
      </div>
    </div>
  </div>

  <div class="bg-white border border-slate-100 shadow rounded-3xl p-6 space-y-4">
    <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 text-xs text-slate-600">
      For event-specific export, open the event attendees page. Output encoding is UTF-8.
    </div>
    <form id="customExportForm" method="post" action="<?= BASE_URL ?>/admin/export.php"
      class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="md:col-span-2">
        <label class="text-sm font-semibold text-slate-800">DP codes</label>
        <textarea name="dp_list" rows="6" class="input-field mt-1"
          placeholder="DP1000, DP1001&#10;DP1002 DP1003"></textarea>
        <p class="text-xs text-slate-500 mt-1">Separators: comma, space, or new line.</p>
      </div>
      <div class="md:col-span-1">
        <label class="text-sm font-semibold text-slate-800">Fields (tap to toggle)</label>
        <div class="flex flex-wrap gap-2 mt-2" id="fieldToggleWrap">
          <?php foreach ($fields as $key => $label): ?>
            <button type="button" class="choice-btn is-active" data-field="<?= $key ?>">
              <span class="choice-dot"></span><span><?= $label ?></span>
            </button>
            <input type="checkbox" name="fields[]" value="<?= $key ?>" checked class="hidden"
              data-field-check="<?= $key ?>">
          <?php endforeach; ?>
        </div>
      </div>
      <div class="md:col-span-3 flex flex-wrap items-center gap-3">
        <button class="btn-primary" type="submit">Export CSV</button>
        <p class="text-xs text-slate-500">CSV downloads immediately in browser.</p>
      </div>
    </form>
  </div>
</div>
<script>
  // Toggle multi-select buttons for fields
  document.addEventListener('DOMContentLoaded', function () {
    const wrap = document.getElementById('fieldToggleWrap');
    if (!wrap) return;
    wrap.querySelectorAll('button[data-field]').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-field');
        const check = document.querySelector(`input[data-field-check="${key}"]`);
        const isActive = btn.classList.toggle('is-active');
        if (check) check.checked = isActive;
      });
    });
  });
</script>
<style>
  .choice-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    color: #0f172a;
    font-weight: 600;
    transition: all 0.15s ease;
  }

  .choice-btn .choice-dot {
    width: 12px;
    height: 12px;
    border-radius: 999px;
    border: 2px solid #cbd5e1;
    background: #fff;
    flex-shrink: 0;
  }

  .choice-btn.is-active {
    border-color: #10b981;
    background: #ecfdf3;
    color: #065f46;
    box-shadow: 0 10px 20px rgba(16, 185, 129, 0.15);
  }

  .choice-btn.is-active .choice-dot {
    border-color: #10b981;
    background: #10b981;
  }
</style>
<?php render_footer(); ?>