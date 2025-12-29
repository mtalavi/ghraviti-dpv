<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_events');

$msg = null;
if (isset($_GET['saved'])) {
  $msg = 'Event saved.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $msg = 'CSRF failed.';
  } else {
    $eid = (int) $_POST['id'];
    // Fetch event to get banner path before deleting
    $event = fetch_one('SELECT banner_image FROM events WHERE id=?', [$eid]);

    // CASCADE: Delete all registrations for this event (prevents orphan records)
    delete_event_registrations($eid);

    execute_query('DELETE FROM events WHERE id=?', [$eid]);
    // Delete banner file if it exists
    if ($event && !empty($event['banner_image']) && file_exists($event['banner_image'])) {
      @unlink($event['banner_image']);
    }
    log_action('event_delete', 'event', $eid);
    $msg = 'Event deleted.';
  }
}

// AJAX: Reset event password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
  header('Content-Type: application/json');
  if (!csrf_check($_POST['csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'CSRF failed']);
    exit;
  }
  $eid = (int) ($_POST['id'] ?? 0);
  $event = fetch_one('SELECT * FROM events WHERE id=?', [$eid]);
  if (!$event) {
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
  }
  $newPw = generate_numeric_code(6);
  $hash = password_hash($newPw, PASSWORD_DEFAULT);
  execute_query('UPDATE events SET console_password_hash=? WHERE id=?', [$hash, $eid]);
  log_action('event_password_reset', 'event', $eid);
  echo json_encode(['success' => true, 'password' => $newPw]);
  exit;
}

$events = fetch_all(
  'SELECT e.*, u.full_name AS creator FROM events e JOIN users u ON e.created_by=u.id ORDER BY e.start_datetime DESC'
);

// PERF-01 FIX: Pre-fetch all event statistics to avoid N+1 queries
$eventStats = [];
if ($events) {
  $statsRaw = fetch_all(
    "SELECT 
      event_id,
      COUNT(*) as total,
      SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) as checked_in,
      SUM(CASE WHEN status = 'checked_out' THEN 1 ELSE 0 END) as checked_out,
      SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END) as registered
    FROM event_registrations
    GROUP BY event_id"
  );
  foreach ($statsRaw as $stat) {
    $eventStats[(int) $stat['event_id']] = $stat;
  }
}

$noticePw = $_SESSION['event_pw_notice'] ?? null;
unset($_SESSION['event_pw_notice']);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$absoluteBase = $host ? $scheme . '://' . $host . BASE_URL : BASE_URL;

render_header('Events');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">📅 Event Management</p>
        <h1 class="dpv-page-header__title">Events</h1>
        <p class="dpv-page-header__subtitle">Each event provides a public registration link and a password-protected
          Event Admin console.</p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= BASE_URL ?>/admin/event_form.php" class="dpv-page-header__btn">
          ➕ New Event
        </a>
        <a href="<?= dashboard_url() ?>" class="dpv-page-header__btn">
          🏠 Dashboard
        </a>
      </div>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($noticePw): ?>
    <div class="dpv-alert dpv-alert--success">
      <span>✅</span>
      <div>
        <div>New event console password: <strong><?= h($noticePw['password']) ?></strong></div>
        <?php if (!empty($noticePw['link'])): ?>
          <div>Console: <a class="underline" href="<?= h($noticePw['link']) ?>"
              target="_blank"><?= h($noticePw['link']) ?></a></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($msg): ?>
    <div class="dpv-alert dpv-alert--success">
      <span>✅</span>
      <span><?= h($msg) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!$events): ?>
    <div class="dpv-section-card dpv-text-center" style="padding: 3rem;">
      <div style="font-size: 4rem; margin-bottom: 1rem;">📅</div>
      <h3 class="dpv-font-bold" style="font-size: 1.25rem; color: var(--dpv-slate-700); margin-bottom: 0.5rem;">No Events
        Yet</h3>
      <p class="dpv-text-muted">Create your first event to get started.</p>
      <a href="<?= BASE_URL ?>/admin/event_form.php" class="dpv-btn dpv-btn--primary" style="margin-top: 1.5rem;">
        ➕ Create Event
      </a>
    </div>
  <?php endif; ?>

  <div class="space-y-4">
    <?php foreach ($events as $ev): ?>
      <?php
      $publicLink = $absoluteBase . '/event/register.php?slug=' . urlencode($ev['public_slug']);
      $consoleLink = $absoluteBase . '/event/console.php?slug=' . urlencode($ev['console_slug']);
      // Password display removed for security - use session notice on creation only
      $adminCopy = "Event: {$ev['name']}\nWhen: " . format_dubai_datetime($ev['start_datetime'], 'full') . "\nLocation: {$ev['location']}\nYou are the admin for this event.\nLogin link: {$consoleLink}\nNote: Password was shown at creation time only. Reset if forgotten.";

      // Event Statistics - using pre-fetched data
      $capacity = isset($ev['capacity']) ? (int) $ev['capacity'] : 0;
      $stats = $eventStats[(int) $ev['id']] ?? null;
      $statTotal = $stats ? (int) $stats['total'] : 0;
      $statIn = $stats ? (int) $stats['checked_in'] : 0;
      $statOut = $stats ? (int) $stats['checked_out'] : 0;
      $statAbsent = $stats ? (int) $stats['registered'] : 0;
      ?>
      <div class="bg-white border border-slate-100 shadow rounded-2xl p-4 space-y-4">
        <!-- Header Row -->
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div class="space-y-1">
            <div class="text-xs uppercase text-slate-500">Event</div>
            <div class="text-xl font-semibold text-slate-900"><?= h($ev['name']) ?></div>
            <div class="text-sm text-slate-600">📅 <?= format_dubai_datetime($ev['start_datetime'], 'full') ?></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button type="button"
              class="px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700 font-semibold border border-emerald-100 shadow-sm"
              data-copy="<?= $publicLink ?>">Copy event link</button>
            <button type="button"
              class="px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700 font-semibold border border-emerald-100 shadow-sm"
              data-copy="<?= h($adminCopy) ?>">Copy event admin link</button>
            <button type="button"
              class="px-3 py-2 rounded-lg bg-amber-50 text-amber-700 font-semibold border border-amber-100 shadow-sm hover:bg-amber-100 transition"
              data-reset-pw="<?= $ev['id'] ?>">🔑 Reset Password & Copy</button>
            <a href="<?= BASE_URL ?>/event/console.php?slug=<?= urlencode($ev['console_slug']) ?>&export=checkedin"
              class="px-3 py-2 rounded-lg bg-blue-50 text-blue-700 font-semibold border border-blue-100 shadow-sm hover:bg-blue-100 transition">Download
              CSV</a>
            <a href="<?= BASE_URL ?>/admin/event_form.php?id=<?= $ev['id'] ?>"
              class="px-3 py-2 rounded-lg bg-emerald-600 text-white font-semibold shadow-sm hover:bg-emerald-700 transition">Edit</a>
            <form method="post" action="" onsubmit="return confirm('Delete event?');">
              <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="id" value="<?= $ev['id'] ?>">
              <input type="hidden" name="action" value="delete">
              <button
                class="px-3 py-2 rounded-lg bg-red-50 text-red-700 font-semibold border border-red-100 shadow-sm hover:bg-red-100 transition"
                type="submit">Delete</button>
            </form>
          </div>
        </div>

        <!-- Statistics Row -->
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
          <?php if ($capacity > 0): ?>
            <div class="bg-slate-50 rounded-xl p-3 text-center border border-slate-100">
              <div class="text-2xl font-bold text-slate-800"><?= $capacity ?></div>
              <div class="text-xs text-slate-500 uppercase tracking-wide">Capacity</div>
            </div>
          <?php endif; ?>
          <div class="bg-blue-50 rounded-xl p-3 text-center border border-blue-100">
            <div class="text-2xl font-bold text-blue-700"><?= $statTotal ?></div>
            <div class="text-xs text-blue-600 uppercase tracking-wide">Registered</div>
          </div>
          <div class="bg-emerald-50 rounded-xl p-3 text-center border border-emerald-100">
            <div class="text-2xl font-bold text-emerald-700"><?= $statIn ?></div>
            <div class="text-xs text-emerald-600 uppercase tracking-wide">Checked In</div>
          </div>
          <div class="bg-purple-50 rounded-xl p-3 text-center border border-purple-100">
            <div class="text-2xl font-bold text-purple-700"><?= $statOut ?></div>
            <div class="text-xs text-purple-600 uppercase tracking-wide">Checked Out</div>
          </div>
          <div class="bg-amber-50 rounded-xl p-3 text-center border border-amber-100">
            <div class="text-2xl font-bold text-amber-700"><?= $statAbsent ?></div>
            <div class="text-xs text-amber-600 uppercase tracking-wide">Not Attended</div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
  const csrfToken = '<?= csrf_token() ?>';

  // Copy buttons
  document.querySelectorAll('button[data-copy]').forEach(btn => {
    btn.addEventListener('click', () => {
      const txt = btn.getAttribute('data-copy');
      if (!txt) return;
      navigator.clipboard.writeText(txt).then(() => {
        const old = btn.textContent;
        btn.textContent = 'Copied ✓';
        setTimeout(() => { btn.textContent = old || 'Copy'; }, 1200);
      });
    });
  });

  // Reset Password & Copy buttons
  document.querySelectorAll('button[data-reset-pw]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const eventId = btn.getAttribute('data-reset-pw');
      if (!confirm('Reset console password for this event? The new password will be copied to clipboard.')) return;

      const originalText = btn.textContent;
      btn.textContent = 'Resetting...';
      btn.disabled = true;

      try {
        const formData = new FormData();
        formData.append('action', 'reset_password');
        formData.append('id', eventId);
        formData.append('csrf', csrfToken);

        const res = await fetch(window.location.href.split('?')[0], {
          method: 'POST',
          body: formData
        });

        const data = await res.json();

        if (data.success && data.password) {
          await navigator.clipboard.writeText(data.password);
          btn.textContent = '✓ Password: ' + data.password + ' (Copied!)';
          btn.classList.remove('bg-amber-50', 'text-amber-700', 'border-amber-100');
          btn.classList.add('bg-emerald-50', 'text-emerald-700', 'border-emerald-100');
          setTimeout(() => {
            btn.textContent = originalText;
            btn.classList.remove('bg-emerald-50', 'text-emerald-700', 'border-emerald-100');
            btn.classList.add('bg-amber-50', 'text-amber-700', 'border-amber-100');
          }, 5000);
        } else {
          alert('Error: ' + (data.message || 'Unknown error'));
          btn.textContent = originalText;
        }
      } catch (e) {
        alert('Network error: ' + e.message);
        btn.textContent = originalText;
      }

      btn.disabled = false;
    });
  });
</script>
<?php render_footer(); ?>