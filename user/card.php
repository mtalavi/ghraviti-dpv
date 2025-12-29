<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
$u = current_user();
$qrUrl = qr_url_for_code($u['dp_code']);
$roleTitleRaw = trim($u['role_title_en'] ?? '');
if ($roleTitleRaw === '') {
  $roleTitleRaw = trim(settings_get('general_role_title', ''));
}
$roleTitle = strtoupper($roleTitleRaw);
$avatarUrl = $u['profile_photo'] ? BASE_URL . '/image.php?type=avatar&file=' . urlencode(basename($u['profile_photo'])) : '';
render_header('DP Card', false);
?>
<style>
  /* =====================================================
     SMART CARD STYLES (Lightweight - No JS Download)
     ===================================================== */

  /* Local Fonts */
  @font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400;
    src: url('<?= BASE_URL ?>/assets/fonts/Inter-Regular.woff2') format('woff2');
  }

  @font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 600;
    src: url('<?= BASE_URL ?>/assets/fonts/Inter-SemiBold.woff2') format('woff2');
  }

  @font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 700;
    src: url('<?= BASE_URL ?>/assets/fonts/Inter-Bold.woff2') format('woff2');
  }

  @font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 800;
    src: url('<?= BASE_URL ?>/assets/fonts/Inter-ExtraBold.woff2') format('woff2');
  }

  /* Google Fonts fallback (cdn) */
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

  .card-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: 20px;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  }

  /* Scale container - allows card to fit on mobile while maintaining quality */
  .card-scale-container {
    transform-origin: top center;
  }

  /* Main card */
  .card-shell {
    width: 600px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 32px;
    box-shadow:
      0 25px 50px -12px rgba(0, 0, 0, 0.25),
      0 0 0 1px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  }

  .card-header {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    color: #fff;
    padding: 32px 36px;
  }

  .card-header__org {
    font-size: 18px;
    text-transform: uppercase;
    letter-spacing: 0.2em;
    font-weight: 700;
    opacity: 0.95;
  }

  .card-header__title {
    font-size: 34px;
    font-weight: 800;
    margin-top: 8px;
  }

  .card-header__subtitle {
    font-size: 16px;
    opacity: 0.9;
    margin-top: 6px;
  }

  .card-body {
    padding: 36px 28px 48px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 20px;
  }

  /* Avatar with embossed effect */
  .avatar-box {
    width: 240px;
    height: 240px;
    border-radius: 24px;
    overflow: hidden;
    background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
    box-shadow:
      0 20px 25px -5px rgba(0, 0, 0, 0.2),
      0 10px 10px -5px rgba(0, 0, 0, 0.1),
      inset 0 2px 4px rgba(255, 255, 255, 0.4);
    border: 4px solid rgba(255, 255, 255, 0.9);
    transform: translateY(-4px);
  }

  .avatar-bg {
    width: 100%;
    height: 100%;
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
  }

  .name-text {
    font-size: 22px;
    font-weight: 800;
    text-transform: uppercase;
    color: #0f172a;
    text-align: center;
    letter-spacing: 0.02em;
  }

  .dp-code {
    font-size: 60px;
    font-weight: 800;
    color: #0f172a;
    text-align: center;
    font-family: 'Monaco', 'Consolas', monospace;
    background: #f1f5f9;
    padding: 16px 32px;
    border-radius: 12px;
    letter-spacing: 0.05em;
  }

  .qr-box {
    padding: 8px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
  }

  .qr-box img {
    width: 240px;
    height: 240px;
    display: block;
  }

  .role-area {
    min-height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    width: 100%;
    padding: 12px 20px;
    color: #059669;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 36px;
    line-height: 1.2;
  }

  /* Responsive scaling for mobile */
  @media (max-width: 640px) {
    .card-scale-container {
      transform: scale(0.55);
      margin-bottom: -280px;
    }
  }

  @media (max-width: 480px) {
    .card-scale-container {
      transform: scale(0.48);
      margin-bottom: -320px;
    }
  }
</style>

<div
  class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-slate-50 via-white to-emerald-50 p-6">

  <!-- Screenshot Instructions - Green Glowing Box -->
  <style>
    @keyframes glowPulse {

      0%,
      100% {
        box-shadow: 0 0 15px rgba(16, 185, 129, 0.4), 0 0 30px rgba(16, 185, 129, 0.2);
      }

      50% {
        box-shadow: 0 0 25px rgba(16, 185, 129, 0.6), 0 0 50px rgba(16, 185, 129, 0.3);
      }
    }

    .glow-box {
      animation: glowPulse 2s ease-in-out infinite;
    }
  </style>
  <div class="max-w-[420px] mx-auto text-center space-y-3" style="margin-bottom: 80px;">
    <div
      class="inline-flex items-center justify-center w-14 h-14 bg-emerald-500 text-white rounded-full mb-3 shadow-lg">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
      </svg>
    </div>

    <h3 class="text-slate-900 font-extrabold text-2xl">Save Your Digital ID</h3>

    <div
      class="glow-box bg-gradient-to-br from-emerald-50 to-emerald-100 border-2 border-emerald-400 rounded-2xl p-5 text-base text-emerald-800">
      <p class="font-semibold">
        📸 Please <strong>take a screenshot</strong> of this card to save it on your phone.
      </p>
      <p class="mt-2 text-sm text-emerald-600">
        Show this image at the event entrance.
      </p>
    </div>
  </div>

  <!-- Card Container -->
  <div class="card-scale-container">
    <div class="card-shell" id="volunteer-card" style="padding: 40px 20px;">
      <div style="display: flex; flex-direction: column; align-items: center; gap: 30px;">
        <p class="dp-code" style="font-size: 72px; margin: 0;"><?= h($u['dp_code']) ?></p>
        <div class="qr-box" style="padding: 12px;">
          <img src="<?= $qrUrl ?>" alt="QR Code" style="width: 300px; height: 300px;">
        </div>
      </div>
    </div>
  </div>

</div>

<?php render_footer(); ?>