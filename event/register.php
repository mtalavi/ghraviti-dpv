<?php
require_once __DIR__ . '/../includes/init.php';

$slug = $_GET['slug'] ?? '';
$event = $slug ? fetch_one('SELECT * FROM events WHERE public_slug=?', [$slug]) : null;
if (!$event) {
  http_response_code(404);
  exit('Event not found.');
}

$error = null;
$success = null;
$capacity = isset($event['capacity']) ? (int) $event['capacity'] : 0;
$currentCount = fetch_one('SELECT COUNT(*) as c FROM event_registrations WHERE event_id=?', [$event['id']])['c'] ?? 0;
$isFull = $capacity > 0 && $currentCount >= $capacity;

// Inline login for event registration
if (!current_user() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'login') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please refresh the page.';
  } elseif (throttle_attempt('login_event_' . $slug, 5, 300)) {
    $error = 'Too many attempts. Please wait a few minutes.';
  } else {
    $dp = strtoupper(trim($_POST['dp_code'] ?? ''));
    $pass = $_POST['password'] ?? '';
    $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dp]);
    if ($user && $user['password_hash'] && password_verify($pass, $user['password_hash'])) {
      session_regenerate_id(true);
      $_SESSION['uid'] = $user['id'];
      log_action('login', 'user', $user['id']);
      unset($_SESSION['throttle']['login_event_' . $slug]);
      // If event is full after login, redirect to dashboard
      if ($isFull) {
        flash('error', 'Registration is closed (capacity reached).');
        header('Location: ' . BASE_URL . '/user/dashboard.php');
        exit;
      }
    } elseif ($user && empty($user['password_hash'])) {
      $error = 'Please contact DPV hub admin to receive your login credentials.';
    } else {
      $error = 'Invalid DP code or password.';
    }
  }
}

if (current_user() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'register') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please refresh the page.';
  } else {
    $u = current_user();
    // Prevent duplicate registrations
    $existing = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], $u['id']]);
    if ($existing) {
      $error = 'You are already registered for this event.';
    } elseif ($isFull) {
      $error = 'Registration is closed (capacity reached).';
    } else {
      $hasRef = $_POST['has_reference'] ?? 'no';
      $ref = trim($_POST['ref_number'] ?? '');
      if ($hasRef === 'yes') {
        if ($ref === '' || !preg_match('/^\d{12}$/', $ref)) {
          $error = 'Reference number must be exactly 12 digits.';
        }
      } else {
        $ref = null;
      }
      if (!$error) {
        $status = 'registered';
        execute_query('INSERT INTO event_registrations (event_id,user_id,ref_number,has_reference,status) VALUES (?,?,?,?,?)', [$event['id'], $u['id'], $ref ?: null, $hasRef === 'yes' ? 1 : 0, $status]);
        log_action('event_register', 'event', $event['id'], ['user_id' => $u['id'], 'ref' => $ref]);
        if (!empty($u['email'])) {
          sendDPVEmail('event_reg', $u['email'], $u['full_name'], [
            'EVENT_NAME' => $event['name'] ?? ''
          ]);
        }
        header('Location: ' . BASE_URL . '/user/dashboard.php');
        exit;
      }
    }
  }
}

$userReg = null;
if (current_user()) {
  $userReg = fetch_one('SELECT * FROM event_registrations WHERE event_id=? AND user_id=?', [$event['id'], current_user()['id']]);
}
$hasRefFlag = $userReg ? (int) $userReg['has_reference'] : 1;
$alreadyRegistered = current_user() && $userReg;

render_header('Event registration', false);
?>
<style>
  .break-anywhere {
    word-break: break-word;
    overflow-wrap: anywhere;
  }

  .event-banner {
    position: relative;
    width: calc(100% + 3rem);
    margin: -1.5rem -1.5rem 1.5rem -1.5rem;
    aspect-ratio: 3 / 1;
    overflow: hidden;
    border-radius: 1.5rem 1.5rem 0 0;
  }

  @media (min-width: 640px) {
    .event-banner {
      width: calc(100% + 4rem);
      margin: -2rem -2rem 1.5rem -2rem;
    }
  }

  .event-banner img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .event-banner::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 50%;
    background: linear-gradient(to top, rgba(0, 0, 0, 0.4), transparent);
  }
</style>
<div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-emerald-50 p-4 sm:p-6">
  <div
    class="max-w-4xl mx-auto bg-white rounded-3xl shadow-2xl border border-slate-100 p-6 sm:p-8 space-y-6 break-anywhere">

    <?php if (!empty($event['banner_image'])): ?>
      <div class="event-banner">
        <img src="<?= BASE_URL ?>/image.php?type=event&file=<?= urlencode(basename($event['banner_image'])) ?>"
          alt="<?= h($event['name']) ?>">
      </div>
    <?php endif; ?>

    <div class="flex items-start justify-between gap-4 flex-wrap">
      <div class="space-y-1">
        <p class="text-xs uppercase text-emerald-700 font-semibold">Event</p>
        <h1 class="text-3xl font-bold text-slate-900 break-anywhere"><?= h($event['name']) ?></h1>
        <p class="text-sm text-slate-600 break-anywhere">📅
          <?= format_dubai_datetime($event['start_datetime'], 'full') ?>
          at
          <?= h($event['location']) ?>
        </p>
        <?php if (!empty($event['description'])): ?>
          <p class="text-sm text-slate-600 mt-1 break-anywhere"><?= nl2br(h($event['description'])) ?></p>
        <?php endif; ?>
      </div>
      <span class="dpv-badge dpv-badge--success">DPV hub</span>
    </div>

    <?php if ($isFull && !$alreadyRegistered): ?>
      <div class="dpv-alert dpv-alert--warning">
        <span>⚠️</span>
        <span>Registration is closed (capacity reached).</span>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="dpv-alert dpv-alert--error">
        <span>⚠️</span>
        <span><?= $error ?></span>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="dpv-alert dpv-alert--success">
        <span>✅</span>
        <span><?= $success ?></span>
      </div>
    <?php endif; ?>
    <?php if ($alreadyRegistered): ?>
      <div class="dpv-alert dpv-alert--success">
        <span>✅</span>
        <span>You are already registered for this event.</span>
      </div>
      <a href="<?= BASE_URL ?>/user/dashboard.php" class="dpv-btn dpv-btn--primary">
        🏠 Back to dashboard
      </a>
    <?php endif; ?>

    <?php if (!current_user()): ?>
      <div class="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-3 break-anywhere">
        <h2 class="text-lg font-semibold text-slate-900">Login with DP Code to continue</h2>
        <form method="post" class="grid sm:grid-cols-2 gap-3">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="step" value="login">
          <div class="sm:col-span-2">
            <label class="text-sm font-semibold text-slate-700">DP Code</label>
            <input name="dp_code" class="input-field mt-1" placeholder="DP1234" required>
          </div>
          <div class="sm:col-span-2">
            <label class="text-sm font-semibold text-slate-700">Password</label>
            <input type="password" name="password" class="input-field mt-1" placeholder="••••••••" required>
          </div>
          <button class="btn-primary sm:col-span-2 justify-center">Login & continue</button>
        </form>
        <p class="text-xs text-slate-500">If you do not know your password, please contact your DPV hub admin.</p>
      </div>
    <?php elseif (!$alreadyRegistered && !$isFull): ?>
      <div class="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-3 break-anywhere">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-xs uppercase text-slate-500">Logged in as</p>
            <h3 class="text-lg font-semibold text-slate-900"><?= h(current_user()['full_name']) ?>
              (<?= h(current_user()['dp_code']) ?>)</h3>
          </div>
          <?php if ($userReg): ?><span
              class="pill bg-emerald-50 text-emerald-700"><?= h($userReg['status']) ?></span><?php endif; ?>
        </div>
        <form method="post" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="step" value="register">
          <p class="text-sm font-semibold text-slate-800">Do you have a reference number?</p>
          <label class="flex items-center gap-2 text-sm text-slate-700"><input type="radio" name="has_reference"
              value="yes" class="rounded border-slate-300" <?= $hasRefFlag ? 'checked' : '' ?>> Yes, I have</label>
          <label class="flex items-center gap-2 text-sm text-slate-700"><input type="radio" name="has_reference"
              value="no" class="rounded border-slate-300" <?= $hasRefFlag ? '' : 'checked' ?>> I do not have one</label>
          <div id="refWrap" class="space-y-1">
            <label class="text-xs font-semibold text-red-600">Reference number sent to you by SMS from Dubai
              Police</label>
            <input name="ref_number" maxlength="12" inputmode="numeric" pattern="\d{12}" class="input-field"
              placeholder="Enter 12-digit reference number" value="<?= h($userReg['ref_number'] ?? '') ?>">
          </div>
          <button class="btn-primary justify-center w-full sm:w-auto">Submit registration</button>
        </form>
      </div>
    <?php elseif (!$alreadyRegistered && $isFull): ?>
      <div class="bg-slate-50 border border-slate-200 rounded-2xl p-5 space-y-2 text-sm text-slate-700 break-anywhere">
        Registration is closed for this event. <a href="<?= BASE_URL ?>/user/dashboard.php"
          class="text-emerald-700 font-semibold">Go to dashboard</a>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var yes = document.querySelector('input[name="has_reference"][value="yes"]');
    var no = document.querySelector('input[name="has_reference"][value="no"]');
    var wrap = document.getElementById('refWrap');
    var input = document.querySelector('input[name="ref_number"]');
    function updateRef() {
      if (!wrap || !input) return;
      var show = yes && yes.checked;
      wrap.style.display = show ? 'block' : 'none';
      input.disabled = !show;
      if (!show) { input.value = ''; }
    }
    if (yes) yes.addEventListener('change', updateRef);
    if (no) no.addEventListener('change', updateRef);
    updateRef();
  });
</script>
<?php render_footer(); ?>