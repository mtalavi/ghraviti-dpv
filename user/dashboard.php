<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$u = current_user();
$regs = fetch_all(
  'SELECT e.*, er.id AS reg_id, er.status, er.ref_number, er.has_reference FROM event_registrations er JOIN events e ON er.event_id = e.id WHERE er.user_id=? ORDER BY e.start_datetime DESC',
  [$u['id']]
);
$qrFile = qr_path_for_code($u['dp_code']);
if (!file_exists($qrFile)) {
  generate_qr_png($u['dp_code'], $qrFile);
}
$notice = flash('success');
$error = flash('error');

// Handle add reference number (one-time)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ref') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    flash('error', 'Session expired. Please refresh the page.');
  } else {
    $eventId = (int) ($_POST['event_id'] ?? 0);
    $ref = trim($_POST['ref_number'] ?? '');
    if (!preg_match('/^\d{12}$/', $ref)) {
      flash('error', 'Reference number must be exactly 12 digits.');
    } else {
      $reg = fetch_one('SELECT id, ref_number FROM event_registrations WHERE event_id=? AND user_id=?', [$eventId, $u['id']]);
      if (!$reg) {
        flash('error', 'Registration not found for this event.');
      } elseif (!empty($reg['ref_number'])) {
        flash('error', 'Reference already set for this event.');
      } else {
        execute_query('UPDATE event_registrations SET ref_number=?, has_reference=1 WHERE id=?', [$ref, $reg['id']]);
        log_action('event_add_ref', 'event', $eventId, ['user_id' => $u['id'], 'ref' => $ref]);
        flash('success', 'Reference number saved.');
      }
    }
  }
  redirect(BASE_URL . '/user/dashboard.php');
}

render_header('Dashboard');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Dubai Clock Widget -->
  <div
    style="background: linear-gradient(135deg, #059669 0%, #10b981 100%); border-radius: 16px; padding: 16px; text-align: center; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
    <div id="dubaiClockUser" style="font-size: 28px; font-family: monospace; font-weight: 700; color: #ffffff;">--:--:--
    </div>
    <div id="dubaiDateUser" style="font-size: 14px; color: #ffffff; margin-top: 4px;">Loading...</div>
  </div>
  <script>
    (function () {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
      const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

      function updateDubaiTimeUser() {
        const now = new Date();
        const dubaiOffset = 4 * 60;
        const localOffset = now.getTimezoneOffset();
        const dubaiTime = new Date(now.getTime() + (localOffset + dubaiOffset) * 60000);

        const hours = String(dubaiTime.getHours()).padStart(2, '0');
        const mins = String(dubaiTime.getMinutes()).padStart(2, '0');
        const secs = String(dubaiTime.getSeconds()).padStart(2, '0');

        const dayName = dayNames[dubaiTime.getDay()];
        const day = dubaiTime.getDate();
        const month = dubaiTime.getMonth() + 1;
        const monthName = monthNames[dubaiTime.getMonth()];
        const year = dubaiTime.getFullYear();

        document.getElementById('dubaiClockUser').textContent = '🕐 ' + hours + ':' + mins + ':' + secs;
        document.getElementById('dubaiDateUser').textContent = dayName + ', ' + day + '/' + month + ' (' + monthName + ') ' + year + ' - Dubai';
      }

      updateDubaiTimeUser();
      setInterval(updateDubaiTimeUser, 1000);
    })();
  </script>
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
  <div class="grid md:grid-cols-3 gap-6">
    <div class="md:col-span-1">
      <div class="dpv-section-card flex flex-col items-center text-center">

        <p class="text-xs uppercase tracking-wide text-slate-500">DPV ID</p>
        <p class="text-2xl font-bold text-slate-900" style="font-family: var(--dpv-font-mono);"><?= h($u['dp_code']) ?>
        </p>
        <p class="text-lg font-semibold text-slate-800 text-center"><?= h($u['full_name']) ?></p>
        <img class="mt-4 w-36 h-36" src="<?= qr_url_for_code($u['dp_code']) ?>" alt="QR">
        <a class="mt-4 text-emerald-700 text-sm underline" href="<?= BASE_URL ?>/user/card.php" target="_blank">Download
          DP Card</a>
      </div>
    </div>
    <div class="md:col-span-2 space-y-6">
      <div class="dpv-section-card">
        <div class="flex items-center justify-between mb-3">
          <div>
            <p class="text-xs uppercase text-slate-500">Events</p>
            <h2 class="text-xl font-bold text-slate-900">My events</h2>
          </div>
        </div>
        <div class="space-y-3">
          <?php if (!$regs): ?>
            <p class="text-slate-600 text-sm">No events yet. Admin will add you when you check in.</p>
          <?php endif; ?>
          <?php foreach ($regs as $row): ?>
            <div
              class="border border-slate-200 rounded-2xl p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div>
                <p class="font-semibold text-slate-900"><?= h($row['name']) ?></p>
                <p class="text-sm text-slate-500">📅 <?= format_dubai_datetime($row['start_datetime'], 'full') ?> -
                  <?= h($row['location']) ?>
                </p>
                <?php if (!empty($row['ref_number'])): ?>
                  <p class="text-xs text-slate-500">Reference No: <?= h($row['ref_number']) ?></p>
                <?php else: ?>
                  <p class="text-xs text-slate-500">Reference: Not provided</p>
                <?php endif; ?>
              </div>
              <div class="flex items-center gap-2">
                <span class="dpv-badge dpv-badge--success"><?= h(str_replace('_', ' ', $row['status'])) ?></span>
                <?php if (empty($row['ref_number'])): ?>
                  <button type="button" class="dpv-quick-btn dpv-quick-btn--edit" data-add-ref="1"
                    data-event-id="<?= $row['id'] ?>" data-event-name="<?= h($row['name']) ?>"
                    data-event-when="<?= format_dubai_datetime($row['start_datetime'], 'full') ?>"
                    data-event-location="<?= h($row['location']) ?>">
                    Add reference number
                  </button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="bg-white rounded-3xl shadow border border-slate-100 p-6 space-y-4">
        <!-- Verification Notice - Clickable to toggle profile -->
        <div id="profileToggle"
          class="bg-gradient-to-r from-amber-50 to-orange-50 border-2 border-amber-300 rounded-2xl p-5 cursor-pointer hover:shadow-lg transition-all duration-300 select-none">
          <div class="flex items-center gap-4">
            <div
              class="flex-shrink-0 w-14 h-14 bg-amber-400 text-white rounded-full flex items-center justify-center shadow-lg text-2xl">
              ✅
            </div>
            <div class="flex-1">
              <h3 class="text-xl font-extrabold text-amber-800 mb-1">⚠️ Please Verify Your Information</h3>
              <p class="text-base text-amber-700">Double-check all your details below. If you notice any discrepancies,
                please contact the administrator immediately.</p>
            </div>
            <div id="toggleArrow" class="text-amber-600 text-3xl font-bold transition-transform duration-300">
              ▼
            </div>
          </div>
          <p class="text-sm text-amber-600 mt-3 text-center font-medium">👆 Tap here to show/hide your profile details
          </p>
        </div>

        <!-- Profile Details - Hidden by default for privacy -->
        <div id="profileDetails" class="hidden">
          <h2 class="text-xl font-bold text-slate-900 mb-3">My profile (read-only)</h2>
          <div class="grid md:grid-cols-2 gap-3 text-sm text-slate-700">
            <div><span class="font-semibold">Full Name:</span> <?= h($u['full_name']) ?></div>
            <div><span class="font-semibold">Arabic Name:</span> <?= h($u['full_name_ar']) ?></div>
            <div><span class="font-semibold">Gender:</span> <?= h($u['gender']) ?></div>
            <div><span class="font-semibold">Nationality:</span> <?= h($u['nationality']) ?></div>
            <div><span class="font-semibold">Emirate:</span> <?= h($u['emirate']) ?></div>
            <div><span class="font-semibold">Area / Community:</span> <?= h($u['area']) ?></div>
            <div><span class="font-semibold">Email:</span> <?= h($u['email']) ?></div>
            <div><span class="font-semibold">Mobile:</span> <?= h($u['mobile']) ?></div>
            <div><span class="font-semibold">Emirates ID:</span> <?= h($u['emirates_id']) ?></div>
            <div><span class="font-semibold">Date of Birth:</span> <?= h($u['date_of_birth']) ?></div>
            <div><span class="font-semibold">Profession:</span> <?= h($u['profession']) ?></div>
            <div class="md:col-span-2"><span class="font-semibold">Skills:</span> <?= h($u['skills']) ?></div>
          </div>
        </div>
      </div>

      <script>
        // Simple toggle for profile privacy
        document.getElementById('profileToggle').addEventListener('click', function () {
          var details = document.getElementById('profileDetails');
          var arrow = document.getElementById('toggleArrow');
          if (details.classList.contains('hidden')) {
            details.classList.remove('hidden');
            arrow.textContent = '▲';
            arrow.style.transform = 'rotate(0deg)';
          } else {
            details.classList.add('hidden');
            arrow.textContent = '▼';
            arrow.style.transform = 'rotate(0deg)';
          }
        });
      </script>

      <div class="bg-white rounded-3xl shadow border border-slate-100 p-6">
        <h2 class="text-xl font-bold text-slate-900 mb-3">Change password</h2>
        <form method="post" action="<?= BASE_URL ?>/auth/password.php" class="grid sm:grid-cols-2 gap-3">
          <input type="password" name="current" required placeholder="Current password"
            class="rounded-xl border border-slate-200 px-3 py-3 focus:border-emerald-400 focus:ring">
          <input type="password" name="newpass" required placeholder="New password (min 8 chars)"
            class="rounded-xl border border-slate-200 px-3 py-3 focus:border-emerald-400 focus:ring">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <button class="sm:col-span-2 bg-slate-900 hover:bg-slate-800 text-white py-3 rounded-xl font-semibold">Update
            password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<div id="refModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-40">
  <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md space-y-4">
    <div class="space-y-1">
      <p class="text-xs uppercase text-slate-500">Event</p>
      <div class="font-bold text-lg text-slate-900" id="refEventName"></div>
      <div class="text-sm text-slate-600" id="refEventMeta"></div>
    </div>
    <div class="space-y-2">
      <label class="text-sm font-semibold text-slate-800">Reference No (12 digits)</label>
      <p class="text-xs font-semibold text-red-600">Reference number sent to you by SMS from Dubai Police. Double-check
        before saving. It cannot be changed later.</p>
      <input id="refInput" maxlength="12" inputmode="numeric" pattern="\\d{12}" class="input-field text-center"
        placeholder="Enter 12-digit reference">
      <div id="refPreview"
        class="hidden text-center text-xl font-bold text-slate-900 bg-slate-50 border border-slate-200 rounded-xl py-3">
      </div>
      <p class="text-xs text-slate-500 text-center">After saving, you cannot edit this reference.</p>
    </div>
    <div class="flex flex-wrap gap-2 justify-center">
      <button type="button" class="btn-ghost" id="refCancel">Cancel</button>
      <button type="button" class="btn-ghost" id="refReview">Review number</button>
      <form id="refForm" method="post" class="hidden">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="add_ref">
        <input type="hidden" name="event_id" id="refEventId">
        <input type="hidden" name="ref_number" id="refNumberField">
      </form>
      <button type="button" class="btn-primary" id="refSubmit">Confirm & save</button>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('refModal');
    var refInput = document.getElementById('refInput');
    var refPreview = document.getElementById('refPreview');
    var refEventName = document.getElementById('refEventName');
    var refEventMeta = document.getElementById('refEventMeta');
    var refEventId = document.getElementById('refEventId');
    var refNumberField = document.getElementById('refNumberField');
    var refCancel = document.getElementById('refCancel');
    var refReview = document.getElementById('refReview');
    var refSubmit = document.getElementById('refSubmit');

    function digitsOnly(val) {
      return (val || '').replace(/\\D+/g, '').slice(0, 12);
    }
    function hideModal() {
      if (modal) {
        modal.classList.add('hidden');
      }
      if (refInput) { refInput.value = ''; }
      if (refPreview) { refPreview.classList.add('hidden'); refPreview.textContent = ''; }
    }
    function showModal(data) {
      if (!modal || !refInput) return;
      refEventName.textContent = data.name || '';
      refEventMeta.textContent = (data.when || '') + (data.location ? ' - ' + data.location : '');
      refEventId.value = data.id || '';
      refNumberField.value = '';
      refInput.value = '';
      refPreview.classList.add('hidden');
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      refInput.focus();
    }
    document.querySelectorAll('[data-add-ref]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showModal({
          id: btn.getAttribute('data-event-id'),
          name: btn.getAttribute('data-event-name'),
          when: btn.getAttribute('data-event-when'),
          location: btn.getAttribute('data-event-location')
        });
      });
    });
    if (refInput) {
      refInput.addEventListener('input', function () {
        refInput.value = digitsOnly(refInput.value);
      });
    }
    if (refCancel) refCancel.addEventListener('click', hideModal);
    if (refReview) {
      refReview.addEventListener('click', function () {
        var val = digitsOnly(refInput.value);
        if (val.length !== 12) {
          alert('Reference number must be exactly 12 digits.');
          return;
        }
        refPreview.textContent = 'Reference: ' + val;
        refPreview.classList.remove('hidden');
        refNumberField.value = val;
      });
    }
    if (refSubmit) {
      refSubmit.addEventListener('click', function () {
        var val = digitsOnly(refInput.value);
        if (val.length !== 12) {
          alert('Reference number must be exactly 12 digits.');
          return;
        }
        refNumberField.value = val;
        document.getElementById('refForm').submit();
      });
    }
    // Close modal on backdrop click
    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) hideModal();
      });
    }
  });
</script>
<?php render_footer(); ?>