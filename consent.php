<?php
/**
 * Consent Page - Full-screen blocking consent modal
 * Users must sign the latest Terms & Waiver to access the platform.
 */
require_once __DIR__ . '/includes/init.php';

// User must be logged in
if (empty($_SESSION['uid'])) {
    redirect(BASE_URL . '/auth/login.php');
}

// Get fresh user data (bypass cache)
$user = fetch_user_decrypted('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']]);
if (!$user) {
    session_destroy();
    redirect(BASE_URL . '/auth/login.php');
}

$latestVersion = get_latest_consent_version();

// Super admins are exempt from consent requirement
if ($user['role'] === 'super_admin') {
    redirect(get_role_redirect('after_consent', 'super_admin'));
}

// If no consent version exists or user already signed latest, redirect to dashboard
if (!$latestVersion || !user_needs_consent($user)) {
    redirect(get_role_redirect('after_consent', $user['role']));
}

$error = null;
$currentLang = $_GET['lang'] ?? 'en';
if (!in_array($currentLang, ['en', 'ar', 'ur'], true)) {
    $currentLang = 'en';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'decline') {
            // Logout user
            log_action('consent_declined', 'user', $user['id'], ['version_id' => $latestVersion['id']]);
            session_destroy();
            redirect(BASE_URL . '/auth/login.php?msg=consent_declined');
        } elseif ($action === 'agree') {
            $inputName = trim($_POST['signature_name'] ?? '');
            $signedLang = $_POST['signed_language'] ?? 'en';

            if (!validate_consent_signature($inputName, $user['full_name'])) {
                $error = 'The name you entered does not match your registered name. Please type your exact full name.';
            } else {
                // Log the consent
                log_consent_signature(
                    (int) $user['id'],
                    (int) $latestVersion['id'],
                    getRealIp(),
                    $signedLang,
                    $inputName
                );

                // Update user's consent version
                update_user_consent_version((int) $user['id'], (int) $latestVersion['id']);

                // Log action
                log_action('consent_signed', 'consent_version', $latestVersion['id'], [
                    'user_id' => $user['id'],
                    'language' => $signedLang
                ]);

                flash('success', 'Thank you for agreeing to the Terms & Conditions.');

                // Redirect to dashboard (uses centralized config)
                redirect(get_role_redirect('after_consent', $user['role']));
            }
        }
    }
}

// Get content for current language
$contentField = 'content_' . $currentLang;
$content = $latestVersion[$contentField] ?? $latestVersion['content_en'];
$isRtl = in_array($currentLang, ['ar', 'ur'], true);
?>
<!DOCTYPE html>
<html lang="<?= h($currentLang) ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms & Conditions - <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=20251218">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/custom.css?v=20251218">
    <style>
        body {
            margin: 0;
            padding: 0;
            overflow: hidden;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 50%, #d1fae5 100%);
        }

        .consent-container {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 9999;
        }

        .consent-modal {
            background: white;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 800px;
            max-height: 95vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .consent-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
        }

        .consent-body {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            line-height: 1.8;
        }

        .consent-body.rtl {
            direction: rtl;
            text-align: right;
        }

        .consent-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .lang-tabs {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .lang-tab {
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            border: 2px solid transparent;
        }

        .lang-tab.active {
            background: #059669;
            color: white;
        }

        .lang-tab:not(.active) {
            background: white;
            color: #64748b;
            border-color: #e2e8f0;
        }

        .lang-tab:hover:not(.active) {
            border-color: #059669;
            color: #059669;
        }

        .signature-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1.125rem;
            text-align: center;
            transition: all 0.2s;
        }

        .signature-input:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }

        .signature-input.valid {
            border-color: #10b981;
            background: #f0fdf4;
        }

        .signature-input.invalid {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .btn-agree {
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            font-weight: 700;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
        }

        .btn-agree:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-agree:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -10px rgba(5, 150, 105, 0.5);
        }

        .btn-decline {
            padding: 1rem 2rem;
            background: white;
            color: #64748b;
            font-weight: 600;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1rem;
        }

        .btn-decline:hover {
            border-color: #ef4444;
            color: #ef4444;
        }

        .status-message {
            font-size: 0.875rem;
            margin-top: 0.5rem;
            font-weight: 500;
        }

        .status-message.valid {
            color: #059669;
        }

        .status-message.invalid {
            color: #dc2626;
        }
    </style>
</head>

<body>
    <div class="consent-container">
        <div class="consent-modal">
            <!-- Header -->
            <div class="consent-header">
                <div class="text-center">
                    <p class="text-sm uppercase text-emerald-600 font-bold tracking-wide"><?= h(APP_NAME) ?></p>
                    <h1 class="text-2xl font-black text-slate-900 mt-1">Terms & Conditions</h1>
                    <p class="text-sm text-slate-600 mt-1">Please read carefully and agree to continue</p>
                </div>

                <!-- Language Tabs -->
                <div class="lang-tabs mt-4">
                    <a href="?lang=en" class="lang-tab <?= $currentLang === 'en' ? 'active' : '' ?>">🇬🇧 English</a>
                    <a href="?lang=ar" class="lang-tab <?= $currentLang === 'ar' ? 'active' : '' ?>">🇦🇪 العربية</a>
                    <a href="?lang=ur" class="lang-tab <?= $currentLang === 'ur' ? 'active' : '' ?>">🇵🇰 اردو</a>
                </div>
            </div>

            <!-- Content -->
            <div class="consent-body <?= $isRtl ? 'rtl' : '' ?>">
                <?php if ($error): ?>
                    <div class="bg-red-50 text-red-700 px-4 py-3 rounded-xl mb-4 border border-red-100">
                        <strong>Error:</strong> <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <div class="prose max-w-none">
                    <?= $content ?>
                </div>
            </div>

            <!-- Footer with Signature -->
            <div class="consent-footer">
                <form method="post" id="consentForm">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="signed_language" value="<?= h($currentLang) ?>">

                    <div class="mb-4 text-center">
                        <p class="text-sm text-slate-600 mb-2">
                            Your registered name: <strong class="text-slate-900"><?= h($user['full_name']) ?></strong>
                        </p>
                        <input type="text" name="signature_name" id="signatureInput" class="signature-input"
                            placeholder="Type your full name to agree" autocomplete="off" required>
                        <div id="signatureStatus" class="status-message"></div>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                        <button type="submit" name="action" value="decline" class="btn-decline"
                            onclick="return confirm('Are you sure you want to decline and logout?');">
                            Decline & Logout
                        </button>
                        <button type="submit" name="action" value="agree" id="agreeBtn" class="btn-agree" disabled>
                            ✓ I Agree
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const signatureInput = document.getElementById('signatureInput');
            const signatureStatus = document.getElementById('signatureStatus');
            const agreeBtn = document.getElementById('agreeBtn');
            const storedName = <?= json_encode(trim($user['full_name'])) ?>;

            function normalize(str) {
                return (str || '').trim().toLowerCase();
            }

            function checkSignature() {
                const inputValue = signatureInput.value;
                const normalizedInput = normalize(inputValue);
                const normalizedStored = normalize(storedName);

                if (inputValue.trim() === '') {
                    signatureInput.classList.remove('valid', 'invalid');
                    signatureStatus.textContent = '';
                    signatureStatus.classList.remove('valid', 'invalid');
                    agreeBtn.disabled = true;
                    return;
                }

                if (normalizedInput === normalizedStored) {
                    signatureInput.classList.remove('invalid');
                    signatureInput.classList.add('valid');
                    signatureStatus.textContent = '✓ Names match! You can proceed.';
                    signatureStatus.classList.remove('invalid');
                    signatureStatus.classList.add('valid');
                    agreeBtn.disabled = false;
                } else {
                    signatureInput.classList.remove('valid');
                    signatureInput.classList.add('invalid');
                    signatureStatus.textContent = '✗ Names do not match. Please check your spelling.';
                    signatureStatus.classList.remove('valid');
                    signatureStatus.classList.add('invalid');
                    agreeBtn.disabled = true;
                }
            }

            signatureInput.addEventListener('input', checkSignature);
            signatureInput.addEventListener('paste', function () {
                setTimeout(checkSignature, 10);
            });

            // Prevent form submission without valid signature
            document.getElementById('consentForm').addEventListener('submit', function (e) {
                const action = document.activeElement.value;
                if (action === 'agree' && agreeBtn.disabled) {
                    e.preventDefault();
                    alert('Please type your exact full name to agree.');
                }
            });
        });
    </script>
</body>

</html>