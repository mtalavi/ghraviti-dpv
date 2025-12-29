<?php
require_once __DIR__ . '/../includes/init.php';

$error = null;
$redirect = sanitize_redirect_path($_POST['redirect'] ?? $_GET['redirect'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Session expired. Please refresh the page.';
  } elseif (throttle_attempt('login_' . getRealIp(), 5, 300)) {
    $error = 'Too many attempts. Please wait a few minutes.';
  } else {
    $dp = strtoupper(trim($_POST['dp_code'] ?? ''));
    $pass = $_POST['password'] ?? '';
    $user = fetch_user_decrypted('SELECT * FROM users WHERE dp_code=?', [$dp]);
    if ($user && $user['password_hash'] && password_verify($pass, $user['password_hash'])) {
      session_regenerate_id(true);
      $_SESSION['uid'] = $user['id'];
      log_action('login', 'user', $user['id']);
      unset($_SESSION['throttle']['login']);

      // Redirect based on role (uses centralized config)
      $dest = $redirect ?: get_role_redirect('after_login', $user['role']);
      redirect($dest);
    } elseif ($user && empty($user['password_hash'])) {
      $error = 'Please contact DPV hub admin to receive your login credentials.';
    } else {
      // SECURITY: Log failed login attempt for brute-force detection
      // Store hashed DP code reference (not plaintext) for privacy
      log_action('failed_login', 'security', null, [
        'dp_code_hint' => substr($dp, 0, 3) . '***', // First 3 chars only
        'ip' => getRealIp(),
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
        'user_exists' => $user ? true : false
      ]);
      $error = 'Invalid DP code or password.';
    }
  }
}

render_header('Login', false);
?>
<style>
  .login-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #059669 100%);
    padding: 1.5rem;
  }

  .login-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    box-shadow: 0 25px 80px rgba(0, 0, 0, 0.25);
    border-radius: var(--dpv-radius-2xl);
    border: 1px solid rgba(255, 255, 255, 0.5);
    width: 100%;
    max-width: 440px;
    padding: 2.5rem;
  }

  .login-logo {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    color: white;
    margin: 0 auto 1.5rem;
    box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);
  }

  .login-title {
    text-align: center;
    margin-bottom: 0.5rem;
  }

  .login-title h1 {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--dpv-slate-900);
    margin: 0;
  }

  .login-title p {
    font-size: 0.875rem;
    color: var(--dpv-slate-500);
    margin-top: 0.5rem;
  }

  .login-badge {
    display: inline-flex;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 0.375rem 0.875rem;
    border-radius: 999px;
    margin-bottom: 1rem;
  }
</style>

<div class="login-container">
  <div class="login-card">
    <div class="login-logo">🛡️</div>
    <div class="login-title">
      <span class="login-badge">DPV Hub</span>
      <h1>Volunteer Login</h1>
      <p>Access is private. Your DP Code + password are issued by admins.</p>
    </div>

    <?php if ($error): ?>
      <div class="dpv-alert dpv-alert--error" style="margin-top: 1.5rem;">
        <span>⚠️</span>
        <span><?= $error ?></span>
      </div>
    <?php endif; ?>

    <form method="post" class="dpv-space-y-4" style="margin-top: 1.5rem;">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <?php if ($redirect): ?><input type="hidden" name="redirect" value="<?= h($redirect) ?>"><?php endif; ?>

      <div class="dpv-form-group">
        <label class="dpv-label">DP Code</label>
        <input name="dp_code" required class="dpv-input" placeholder="DP1000"
          style="font-family: var(--dpv-font-mono); font-weight: 600;">
      </div>

      <div class="dpv-form-group">
        <label class="dpv-label">Password</label>
        <input type="password" name="password" required class="dpv-input" placeholder="••••••••">
      </div>

      <button class="dpv-btn dpv-btn--primary dpv-btn--block"
        style="padding: 1rem; font-size: 1rem; margin-top: 0.5rem;">
        🔓 Login
      </button>
    </form>

    <p style="text-align: center; font-size: 0.875rem; color: var(--dpv-slate-500); margin-top: 1.5rem;">
      No public sign-up. For access, contact your DPV hub admin.
    </p>
  </div>
</div>
<?php render_footer(); ?>