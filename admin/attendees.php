<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_attendees');

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$event = $eventId ? fetch_one('SELECT * FROM events WHERE id=?', [$eventId]) : null;
if (!$event) {
  exit('Event not found.');
}

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Security token failed.';
  } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
      $dp = strtoupper(trim($_POST['dp_code'] ?? ''));
      $ref = trim($_POST['ref_number'] ?? '');
      $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dp]);
      if (!$user) {
        $err = 'DP code not found. Please create the user first.';
      } else {
        $existing = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$eventId, $user['id']]);
        if ($existing) {
          execute_query('UPDATE event_registrations SET ref_number=?, has_reference=? WHERE id=?', [$ref, $ref !== '' ? 1 : 0, $existing['id']]);
          $msg = 'Reference updated for ' . $dp;
        } else {
          execute_query('INSERT INTO event_registrations (event_id,user_id,ref_number,has_reference,status,checkin_time) VALUES (?,?,?,?,?,NOW())', [$eventId, $user['id'], $ref, $ref !== '' ? 1 : 0, 'checked_in']);
          $msg = 'Added and checked in ' . $dp;
          log_action('admin_add_attendee', 'event', $eventId, ['dp_code' => $dp]);
        }
      }
    } elseif ($action === 'status') {
      $id = (int) $_POST['reg_id'];
      $status = $_POST['status'] ?? 'registered';
      $allowed = ['registered', 'checked_in', 'checked_out', 'absent', 'cancelled'];
      if (!in_array($status, $allowed, true)) {
        $status = 'registered';
      }
      $timeField = '';
      if ($status === 'checked_in') {
        $timeField = 'checkin_time=NOW(), checkout_time=NULL';
      } elseif ($status === 'checked_out') {
        $timeField = 'checkout_time=NOW()';
      }
      $sql = 'UPDATE event_registrations SET status=?';
      if ($timeField) {
        $sql .= ', ' . $timeField;
      }
      $sql .= ' WHERE id=? AND event_id=?';
      execute_query($sql, [$status, $id, $eventId]);
      $msg = 'Status updated.';
      log_action('attendee_status', 'event_registrations', $id, ['status' => $status, 'event_id' => $eventId]);
    } elseif ($action === 'ref_update') {
      $id = (int) $_POST['reg_id'];
      $ref = trim($_POST['ref_number'] ?? '');
      execute_query('UPDATE event_registrations SET ref_number=?, has_reference=? WHERE id=? AND event_id=?', [$ref, $ref !== '' ? 1 : 0, $id, $eventId]);
      $msg = 'Reference updated.';
    } elseif ($action === 'delete') {
      $id = (int) $_POST['reg_id'];
      execute_query('DELETE FROM event_registrations WHERE id=? AND event_id=?', [$id, $eventId]);
      $msg = 'Removed attendee.';
      log_action('delete_attendee', 'event_registrations', $id);
    }
  }
}

$attendees = fetch_users_decrypted(
  'SELECT er.*, u.dp_code, u.full_name, u.email, u.mobile, u.nationality
     FROM event_registrations er
     JOIN users u ON er.user_id = u.id
     WHERE er.event_id=?
     ORDER BY u.dp_code ASC',
  [$eventId]
);

render_header('Attendees');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">✅ Event Attendees</p>
        <h1 class="dpv-page-header__title"><?= h($event['name']) ?></h1>
        <p class="dpv-page-header__subtitle">Manage registrations, check-ins, exports, and print views for this event.
        </p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= BASE_URL ?>/admin/export.php?event_id=<?= $eventId ?>" class="dpv-page-header__btn">
          📊 Export CSV
        </a>
        <a href="javascript:window.print()" class="dpv-page-header__btn">
          🖨️ Print
        </a>
        <a href="<?= BASE_URL ?>/admin/events.php" class="dpv-page-header__btn">
          ← Back to Events
        </a>
      </div>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="dpv-alert dpv-alert--success">
      <span>✅</span>
      <span><?= h($msg) ?></span>
    </div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="dpv-alert dpv-alert--error">
      <span>⚠️</span>
      <span><?= h($err) ?></span>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-3xl shadow border border-slate-100 p-6 space-y-4">
    <h2 class="text-lg font-semibold text-slate-900">Add attendee by DP code</h2>
    <form method="post" class="grid sm:grid-cols-4 gap-3">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add">
      <input name="dp_code" required placeholder="DP1234" class="input-field">
      <input name="ref_number" placeholder="Reference number (optional)" class="input-field">
      <button class="btn-primary sm:col-span-2 justify-center">Add / Check-in</button>
    </form>
    <p class="text-xs text-slate-500">If the DP code is missing, create the user first in Users module.</p>
  </div>

  <div class="bg-white rounded-3xl shadow border border-slate-100 overflow-auto">
    <table class="min-w-full table-lite">
      <thead class="bg-slate-50">
        <tr>
          <th>DP</th>
          <th>Name</th>
          <th>Contact</th>
          <th>Status</th>
          <th>Ref</th>
          <th>Vest</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$attendees): ?>
          <tr>
            <td colspan="7" class="p-4 text-slate-500">No attendees yet.</td>
          </tr>
        <?php endif; ?>
        <?php foreach ($attendees as $a): ?>
          <tr class="border-t border-slate-100">
            <td class="font-mono font-semibold text-slate-900"><?= h($a['dp_code']) ?></td>
            <td class="text-slate-800"><?= h($a['full_name']) ?></td>
            <td class="text-sm text-slate-600">
              <div>Email: <?= h($a['email']) ?></div>
              <div>Mobile: <?= h($a['mobile']) ?></div>
              <div>Nationality: <?= h($a['nationality']) ?></div>
            </td>
            <td class="text-sm">
              <form method="post" class="flex items-center gap-2">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="reg_id" value="<?= $a['id'] ?>">
                <select name="status" class="rounded-lg border border-slate-200 px-2 py-1 text-sm">
                  <?php foreach (['registered', 'checked_in', 'checked_out', 'absent', 'cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $a['status'] === $st ? 'selected' : '' ?>><?= $st ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="text-emerald-700" type="submit">Update</button>
              </form>
            </td>
            <td class="text-sm text-slate-600">
              <form method="post" class="flex items-center gap-2">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="ref_update">
                <input type="hidden" name="reg_id" value="<?= $a['id'] ?>">
                <input name="ref_number" value="<?= h($a['ref_number']) ?>" class="input-field"
                  placeholder="Reference number">
                <button class="btn-ghost" type="submit">Save</button>
              </form>
            </td>
            <td class="text-sm text-slate-600">
              <?php if (!empty($a['vest_number'])): ?>
                <span class="font-mono font-semibold text-indigo-700"><?= h($a['vest_number']) ?></span>
                <?php if ($a['status'] === 'checked_out'): ?>
                  <?php if ($a['vest_returned']): ?>
                    <span class="ml-1 text-xs text-emerald-600">✓</span>
                  <?php else: ?>
                    <span class="ml-1 text-xs text-red-600">✗</span>
                  <?php endif; ?>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-slate-400">-</span>
              <?php endif; ?>
            </td>
            <td class="text-sm space-x-2">
              <form method="post" style="display:inline" onsubmit="return confirm('Remove attendee?');">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="reg_id" value="<?= $a['id'] ?>">
                <button class="text-red-600" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_footer(); ?>