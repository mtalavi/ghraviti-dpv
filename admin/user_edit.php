<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_users');

$userId = (int) ($_GET['id'] ?? 0);
$target = fetch_user_decrypted('SELECT * FROM users WHERE id=?', [$userId]);
if (!$target) {
  http_response_code(404);
  exit('User not found.');
}

// Regular admins can only view users they created
if (!is_super_admin()) {
  if ((int) ($target['created_by'] ?? 0) !== (int) current_user()['id']) {
    http_response_code(403);
    exit('Forbidden: You can only view users you created.');
  }
}

// Block access to super_admin profiles for non-super-admins
if (!is_super_admin() && $target['role'] === 'super_admin') {
  http_response_code(403);
  exit('Forbidden');
}

// Regular admins cannot edit - view only mode
$viewOnlyMode = !is_super_admin();
$disabledAttr = $viewOnlyMode ? 'disabled' : '';

$errors = [];
$invalid = [];
$notice = null;
$old = $_POST ?: $target;
$fieldClass = function (string $name, array $invalid): string {
  return 'input-field' . (in_array($name, $invalid, true) ? ' field-error' : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? 'update';
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Session expired. Please refresh the page.';
  } elseif ($action === 'reset_password') {
    if (!is_super_admin()) {
      $errors[] = 'Only Super Admin can reset passwords.';
    } else {
      $newpass = trim($_POST['new_password'] ?? '');
      if ($newpass === '') {
        $newpass = generate_password(12);
      } elseif (strlen($newpass) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
      }
      if (!$errors) {
        execute_query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($newpass, PASSWORD_DEFAULT), $userId]);
        log_action('reset_password', 'user', $userId);
        if (!empty($target['email'])) {
          sendDPVEmail('pass_change', $target['email'], $target['full_name']);
        }
        $notice = 'Password reset. New password: ' . h($newpass);
      }
    }
    // SECURITY FIX LOG-01: Block non-super_admin from updating user data
    // This is the server-side enforcement - UI restrictions alone are NOT sufficient
  } elseif ($action === 'update' && !is_super_admin()) {
    http_response_code(403);
    exit('Forbidden: Only Super Admin can edit user profiles.');
  } else {
    $required = ['full_name', 'full_name_ar', 'gender', 'nationality', 'emirate', 'area', 'email', 'mobile', 'eid_part1', 'eid_part2', 'eid_part3', 'eid_part4', 'emirates_id_expiry', 'date_of_birth', 'profession', 'skills'];
    foreach ($required as $f) {
      if (trim($_POST[$f] ?? '') === '') {
        $errors[] = 'All fields are required.';
        $invalid[] = $f;
        break;
      }
    }
    if (!in_array($_POST['gender'] ?? '', ['Male', 'Female'], true)) {
      $errors[] = 'Gender must be Male or Female.';
      $invalid[] = 'gender';
    }
    if (!validate_email($_POST['email'] ?? '')) {
      $errors[] = 'Email is not valid.';
      $invalid[] = 'email';
    }
    if (!validate_mobile_uae($_POST['mobile'] ?? '')) {
      $errors[] = 'Mobile must be a UAE number (05xxxxxxxx).';
      $invalid[] = 'mobile';
    }
    $eid1 = trim($_POST['eid_part1'] ?? '');
    $eid2 = trim($_POST['eid_part2'] ?? '');
    $eid3 = trim($_POST['eid_part3'] ?? '');
    $eid4 = trim($_POST['eid_part4'] ?? '');
    $eidCombined = null;
    if ($eid1 === '' || $eid2 === '' || $eid3 === '' || $eid4 === '') {
      $errors[] = 'Emirates ID must be complete.';
      $invalid = array_merge($invalid, ['eid_part1', 'eid_part2', 'eid_part3', 'eid_part4']);
    } else {
      if ($eid1 !== '784') {
        $invalid[] = 'eid_part1';
        $errors[] = 'Emirates ID must start with 784.';
      }
      if (!preg_match('/^[0-9]{4}$/', $eid2)) {
        $invalid[] = 'eid_part2';
      }
      if (!preg_match('/^[0-9]{7}$/', $eid3)) {
        $invalid[] = 'eid_part3';
      }
      if (!preg_match('/^[0-9]{1}$/', $eid4)) {
        $invalid[] = 'eid_part4';
      }
      $eidCombined = $eid1 . '-' . $eid2 . '-' . $eid3 . '-' . $eid4;
      if (!validate_emirates_id($eidCombined)) {
        $errors[] = 'Emirates ID must match 784-XXXX-XXXXXXX-X format.';
        $invalid = array_merge($invalid, ['eid_part1', 'eid_part2', 'eid_part3', 'eid_part4']);
      }
    }
    $eidExpiryRaw = trim($_POST['emirates_id_expiry'] ?? '');
    if ($eidExpiryRaw === '' || !DateTime::createFromFormat('Y-m-d', $eidExpiryRaw)) {
      $errors[] = 'Emirates ID expiry date is required.';
      $invalid[] = 'emirates_id_expiry';
    }
    $vRaw = preg_replace('/\D+/', '', trim($_POST['v_number'] ?? ''));
    if ($vRaw !== '' && strlen($vRaw) !== 6) {
      $errors[] = 'V-number must be exactly 6 digits from volunteers.ae.';
      $invalid[] = 'v_number';
    }
    // DP Code validation (Super Admin only)
    $newDpCode = null;
    if (is_super_admin()) {
      $dpCodeInput = strtoupper(trim($_POST['dp_code'] ?? ''));
      if ($dpCodeInput !== '' && $dpCodeInput !== $target['dp_code']) {
        // Validate format: 2 letters + numbers (e.g., DP1234)
        if (!preg_match('/^[A-Z]{2}[0-9]+$/', $dpCodeInput)) {
          $errors[] = 'DP Code must be 2 letters followed by numbers (e.g., DP1234).';
          $invalid[] = 'dp_code';
        } else {
          // Check for duplicate
          if (fetch_one('SELECT id FROM users WHERE dp_code=? AND id<>?', [$dpCodeInput, $userId])) {
            $errors[] = 'DP Code already exists.';
            $invalid[] = 'dp_code';
          } else {
            $newDpCode = $dpCodeInput;
          }
        }
      }
    }
    if (!$errors) {
      // Use blind index for duplicate checking
      // SECURITY: Whitelist of allowed field names for duplicate checking
      $allowedDupFields = ['email_hash', 'mobile_hash', 'emirates_id_hash', 'v_number'];
      $dupChecks = [
        ['email_hash', 'Email', blind_index(trim($_POST['email']))],
        ['mobile_hash', 'Mobile', blind_index(trim($_POST['mobile']))],
        ['emirates_id_hash', 'Emirates ID', blind_index($eidCombined)],
        ['v_number', 'V-number', $vRaw !== '' ? ('v' . $vRaw) : null]
      ];
      foreach ($dupChecks as [$field, $label, $val]) {
        if ($val === null) {
          continue;
        }
        // SECURITY: Validate field name against whitelist before SQL interpolation
        if (!in_array($field, $allowedDupFields, true)) {
          throw new RuntimeException("Invalid field name in duplicate check: $field");
        }
        if (fetch_one("SELECT id FROM users WHERE $field=? AND id<>?", [$val, $userId])) {
          $errors[] = "$label already exists.";
          break;
        }
      }
    }
    if (!$errors) {
      try {
        $photo = $target['profile_photo'];      // Default: keep existing photo
        $eidImage = $target['emirates_id_image']; // keep existing Emirates ID image

        // Handle profile photo upload (base64 or file)
        $photoData = trim($_POST['profile_photo_data'] ?? '');
        if ($photoData !== '' && preg_match('#^data:(image/[^;]+);base64,(.+)$#', $photoData)) {
          // Base64 photo from camera/cropper
          $tmp = tempnam(sys_get_temp_dir(), 'photo_');
          $bin = base64_decode(preg_replace('#^data:image/[^;]+;base64,#', '', $photoData));
          file_put_contents($tmp, $bin);
          $fakeFile = [
            'name' => 'photo.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bin)
          ];
          $photo = handle_avatar_upload($fakeFile);
          @unlink($tmp);
        } elseif (!empty($_FILES['profile_photo_file']) && ($_FILES['profile_photo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
          // Direct file upload
          $photo = handle_avatar_upload($_FILES['profile_photo_file']);
        }

        $role = $target['role'];
        if (is_super_admin()) {
          $incomingRole = $_POST['role'] ?? $target['role'];
          if (!in_array($incomingRole, ['user', 'admin', 'super_admin'], true)) {
            $incomingRole = $target['role'];
          }
          // Prevent demoting yourself accidentally
          if ($target['id'] === (int) (current_user()['id'] ?? 0) && $incomingRole !== 'super_admin') {
            $errors[] = 'You cannot remove Super Admin from your own account.';
          } else {
            $role = $incomingRole;
          }
        }
        if (!$errors) {
          $roleTitleEn = trim($_POST['role_title_en'] ?? $target['role_title_en'] ?? '');

          // Encrypt sensitive fields before UPDATE
          $encryptedData = encrypt_user_data([
            'full_name' => trim($_POST['full_name']),
            'full_name_ar' => trim($_POST['full_name_ar']),
            'email' => trim($_POST['email']),
            'mobile' => trim($_POST['mobile']),
            'emirates_id' => $eidCombined,
          ]);

          // Build update query - optionally include dp_code if changed by Super Admin
          $updateFields = 'full_name=?, full_name_ar=?, gender=?, nationality=?, emirate=?, area=?, email=?, email_hash=?, mobile=?, mobile_hash=?, emirates_id=?, emirates_id_hash=?, emirates_id_expiry=?, emirates_id_image=?, date_of_birth=?, profession=?, skills=?, profile_photo=?, role=?, role_title_en=?, v_number=?';
          $updateParams = [
            $encryptedData['full_name'],
            $encryptedData['full_name_ar'],
            $_POST['gender'],
            trim($_POST['nationality']),
            trim($_POST['emirate']),
            trim($_POST['area']),
            $encryptedData['email'],
            $encryptedData['email_hash'],
            $encryptedData['mobile'],
            $encryptedData['mobile_hash'],
            $encryptedData['emirates_id'],
            $encryptedData['emirates_id_hash'],
            $eidExpiryRaw ?: null,
            $eidImage,
            trim($_POST['date_of_birth']),
            trim($_POST['profession']),
            trim($_POST['skills']),
            $photo,
            $role,
            $roleTitleEn,
            $vRaw !== '' ? 'v' . $vRaw : null,
          ];

          // Add dp_code to update if changed
          if ($newDpCode !== null) {
            $updateFields .= ', dp_code=?';
            $updateParams[] = $newDpCode;
          }

          $updateParams[] = $userId;
          execute_query("UPDATE users SET $updateFields WHERE id=?", $updateParams);

          // Regenerate QR code if DP Code changed
          if ($newDpCode !== null) {
            $qrPathAbsolute = qr_path_for_code($newDpCode);
            generate_qr_png($newDpCode, $qrPathAbsolute);
            $qrRelative = 'assets/uploads/qr/' . basename($qrPathAbsolute);
            execute_query('UPDATE users SET qr_path=? WHERE id=?', [$qrRelative, $userId]);
            log_action('change_dp_code', 'user', $userId, ['old' => $target['dp_code'], 'new' => $newDpCode]);

            // Send email notification with new DP Code
            $userEmail = trim($_POST['email']);
            $userName = trim($_POST['full_name']);
            $emailSent = false;
            if (!empty($userEmail)) {
              $emailResult = sendDPVEmail('dp_code_change', $userEmail, $userName, [
                'SITE_URL' => app_base_absolute(),
                'OLD_DP_CODE' => $target['dp_code'],
                'NEW_DP_CODE' => $newDpCode
              ]);
              $emailSent = !empty($emailResult['success']);
              log_action('dp_code_email', 'user', $userId, [
                'email' => $userEmail,
                'success' => $emailSent,
                'message' => $emailResult['message'] ?? 'Unknown'
              ]);
            }
          }
          log_action('update_user', 'user', $userId, ['role' => $role]);
          $dpChangeMsg = ($newDpCode !== null) ? " DP Code changed to $newDpCode." : '';
          $emailMsg = (isset($emailSent) && $emailSent) ? ' Email notification sent.' : '';
          $notice = 'User updated successfully.' . $dpChangeMsg . $emailMsg;
          $target = fetch_user_decrypted('SELECT * FROM users WHERE id=?', [$userId]);
          $old = $target;
        }
      } catch (Exception $e) {
        $errors[] = $e->getMessage();
      }
    }
  }
}

render_header('Edit User');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">✏️ Edit User</p>
        <h1 class="dpv-page-header__title"><?= h($target['full_name']) ?></h1>
        <p class="dpv-page-header__subtitle">Full profile edit. DP Code is read-only; manual DP assignment remains on
          create (Super Admin only).</p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= BASE_URL ?>/admin/users.php" class="dpv-page-header__btn">
          👥 All Users
        </a>
        <?php if (is_super_admin()): ?>
          <a href="<?= BASE_URL ?>/admin/admins.php" class="dpv-page-header__btn">
            🛡️ Admins
          </a>
        <?php endif; ?>
        <?php if (is_super_admin()): ?>
          <a href="<?= BASE_URL ?>/admin/user_uploads.php?id=<?= $userId ?>" class="dpv-page-header__btn">
            📷 Upload Photos
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($notice): ?>
    <div class="dpv-alert dpv-alert--success">
      <span>✅</span>
      <span><?= h($notice) ?></span>
    </div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="dpv-alert dpv-alert--error">
      <span>⚠️</span>
      <span><?= implode('<br>', array_map('h', $errors)) ?></span>
    </div>
  <?php endif; ?>

  <div class="bg-white rounded-3xl shadow border border-slate-100 p-6 space-y-4">
    <!-- Profile Photo Section - Above User Info -->
    <div class="flex flex-col items-center justify-center pb-4 border-b border-slate-200">
      <div id="photoPreviewContainer"
        class="w-36 h-44 rounded-2xl border-2 border-dashed border-slate-300 flex items-center justify-center overflow-hidden bg-slate-50 mb-3 shadow-sm cursor-pointer"
        onclick="document.getElementById('photoInput').click()">
        <?php if (!empty($target['profile_photo'])): ?>
          <img id="currentPhoto"
            src="<?= BASE_URL ?>/image.php?type=avatar&file=<?= urlencode(basename($target['profile_photo'])) ?>"
            alt="Profile" class="w-full h-full object-cover">
        <?php else: ?>
          <div id="photoPlaceholder" class="text-center px-3">
            <span class="text-4xl block mb-1">👤</span>
            <span class="text-xs text-slate-400">Click to add</span>
          </div>
        <?php endif; ?>
      </div>
      <input type="file" id="photoInput" accept="image/*" class="hidden">
      <input type="hidden" name="profile_photo_data" id="profilePhotoData" form="editForm">
      <div class="flex gap-2 mb-2">
        <button type="button" id="cameraBtn"
          class="text-sm bg-emerald-500 text-white px-4 py-2 rounded-xl font-semibold shadow-sm hover:bg-emerald-600 transition-colors flex items-center gap-1">
          <span>📷</span> Camera
        </button>
        <button type="button" id="uploadBtn"
          class="text-sm bg-slate-600 text-white px-4 py-2 rounded-xl font-semibold shadow-sm hover:bg-slate-700 transition-colors flex items-center gap-1">
          <span>📁</span> Upload
        </button>
      </div>
      <p class="text-xs text-slate-500 text-center">JPG/PNG • Auto crop & compress</p>
    </div>

    <!-- User Info -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
      <div>
        <p class="text-sm text-slate-500">DP Code</p>
        <?php if (is_super_admin()): ?>
          <input name="dp_code" form="editForm"
            class="<?= $fieldClass('dp_code', $invalid) ?> text-lg font-mono font-semibold text-center w-full max-w-[140px] mx-auto"
            value="<?= h($old['dp_code'] ?? $target['dp_code']) ?>" placeholder="DP1234">
          <p class="text-xs text-slate-500 mt-1">Editable by Super Admin</p>
        <?php else: ?>
          <p class="text-lg font-mono font-semibold text-slate-900"><?= h($target['dp_code']) ?></p>
        <?php endif; ?>
      </div>
      <div>
        <p class="text-sm text-slate-500">V-number</p>
        <p class="text-lg font-mono font-semibold text-slate-900"><?= h($target['v_number']) ?></p>
      </div>
      <div>
        <p class="text-sm text-slate-500">Created</p>
        <p class="text-lg text-slate-900"><?= h($target['created_at']) ?></p>
      </div>
    </div>

    <div class="grid md:grid-cols-5 gap-6">
      <div class="md:col-span-2">
        <div class="sticky top-4 space-y-4">
          <!-- Emirates ID Image Section -->
          <p class="text-sm font-semibold text-slate-700">Emirates ID Image</p>
          <?php if (!empty($target['emirates_id_image'])): ?>
            <a href="<?= BASE_URL ?>/image.php?type=eid&file=<?= urlencode(basename($target['emirates_id_image'])) ?>"
              target="_blank" class="block">
              <img id="eidInline"
                src="<?= BASE_URL ?>/image.php?type=eid&file=<?= urlencode(basename($target['emirates_id_image'])) ?>"
                alt="Emirates ID"
                class="rounded-2xl border border-slate-200 shadow-sm w-full max-h-80 object-contain bg-slate-50">
            </a>
            <button type="button" id="eidFloatBtn" class="btn-ghost w-full justify-center">Pin on screen</button>
          <?php else: ?>
            <div
              class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-slate-500 p-6 text-sm text-center">
              No Emirates ID image uploaded</div>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" id="editForm" enctype="multipart/form-data"
        class="md:col-span-3 grid grid-cols-1 md:grid-cols-3 gap-4">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Full Name</label>
          <input name="full_name" class="<?= $fieldClass('full_name', $invalid) ?> mt-1" required
            value="<?= h($old['full_name'] ?? $target['full_name']) ?>">
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Full Name in Arabic</label>
          <input name="full_name_ar" class="<?= $fieldClass('full_name_ar', $invalid) ?> mt-1" required
            value="<?= h($old['full_name_ar'] ?? $target['full_name_ar']) ?>">
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Gender</label>
          <select name="gender" class="<?= $fieldClass('gender', $invalid) ?> mt-1" required>
            <option value="Male" <?= (($old['gender'] ?? $target['gender']) === 'Male') ? 'selected' : '' ?>>Male</option>
            <option value="Female" <?= (($old['gender'] ?? $target['gender']) === 'Female') ? 'selected' : '' ?>>Female
            </option>
          </select>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Nationality</label>
          <input name="nationality" class="<?= $fieldClass('nationality', $invalid) ?> mt-1" required
            value="<?= h($old['nationality'] ?? $target['nationality']) ?>">
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Emirate of Residence</label>
          <select name="emirate" class="<?= $fieldClass('emirate', $invalid) ?> mt-1" required>
            <?php foreach (['Abu Dhabi (أبو ظبي)', 'Dubai (دبي)', 'Sharjah (الشارقة)', 'Ajman (عجمان)', 'Umm Al Quwain (أم القيوين)', 'Ras Al Khaimah (رأس الخيمة)', 'Fujairah (الفجيرة)'] as $em): ?>
              <?php
              // Match against both old format (English only) and new format (with Arabic)
              $currentEmirate = $old['emirate'] ?? $target['emirate'];
              $emEnglish = preg_replace('/\s*\([^)]+\)$/', '', $em);
              $isSelected = ($currentEmirate === $em || $currentEmirate === $emEnglish);
              ?>
              <option value="<?= $em ?>" <?= $isSelected ? 'selected' : '' ?>>
                <?= $em ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Area / Community</label>
          <input name="area" class="<?= $fieldClass('area', $invalid) ?> mt-1" required
            value="<?= h($old['area'] ?? $target['area']) ?>">
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">V-number</label>
          <div class="flex items-center gap-2 mt-1">
            <span class="px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 font-semibold text-slate-700">V</span>
            <?php $vDigits = ltrim((string) ($old['v_number'] ?? $target['v_number'] ?? ''), 'vV'); ?>
            <input name="v_number" maxlength="6" inputmode="numeric"
              class="<?= $fieldClass('v_number', $invalid) ?> mt-0 flex-1 text-center" placeholder="123456"
              value="<?= h($vDigits) ?>">
          </div>
          <p class="text-xs text-slate-500 mt-1">Optional. volunteers.ae V-number (6 digits), e.g., V123456.</p>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Email Address</label>
          <input name="email" type="email" class="<?= $fieldClass('email', $invalid) ?> mt-1" required
            value="<?= h($old['email'] ?? $target['email']) ?>">
          <p class="text-xs text-slate-500 mt-1">Must be unique, e.g., user@email.com</p>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Mobile Number (UAE)</label>
          <input name="mobile" inputmode="numeric" pattern="05[0-9]{8}" maxlength="10"
            class="<?= $fieldClass('mobile', $invalid) ?> mt-1" required
            value="<?= h($old['mobile'] ?? $target['mobile']) ?>" aria-describedby="mobileHelp">
          <p class="text-xs text-slate-500 mt-1" id="mobileHelp">Format: 05xxxxxxxx (10 digits)</p>
        </div>
        <div class="flex flex-col md:col-span-3">
          <label class="text-sm font-semibold text-slate-700">Emirates ID</label>
          <?php
          $eidParts = explode('-', $old['emirates_id'] ?? $target['emirates_id'] ?? '');
          $p1 = $old['eid_part1'] ?? ($eidParts[0] ?? '784');
          $p2 = $old['eid_part2'] ?? ($eidParts[1] ?? '');
          $p3 = $old['eid_part3'] ?? ($eidParts[2] ?? '');
          $p4 = $old['eid_part4'] ?? ($eidParts[3] ?? '');
          ?>
          <div class="eid-grid items-center" data-eid-group>
            <input name="eid_part1" inputmode="numeric" pattern="[0-9]*" maxlength="3"
              class="<?= $fieldClass('eid_part1', $invalid) ?> text-center bg-slate-100 font-semibold w-full"
              value="784" readonly required>
            <input name="eid_part2" inputmode="numeric" pattern="[0-9]*" maxlength="4"
              class="<?= $fieldClass('eid_part2', $invalid) ?> text-center font-semibold w-full" value="<?= h($p2) ?>"
              required>
            <input name="eid_part3" inputmode="numeric" pattern="[0-9]*" maxlength="7"
              class="<?= $fieldClass('eid_part3', $invalid) ?> text-center font-semibold w-full" value="<?= h($p3) ?>"
              required>
            <input name="eid_part4" inputmode="numeric" pattern="[0-9]*" maxlength="1"
              class="<?= $fieldClass('eid_part4', $invalid) ?> text-center font-semibold w-full" value="<?= h($p4) ?>"
              required>
          </div>
          <p class="text-xs text-slate-500 mt-1">Format: 784-XXXX-XXXXXXX-X</p>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Emirates ID Expiry</label>
          <input name="emirates_id_expiry" type="date" class="<?= $fieldClass('emirates_id_expiry', $invalid) ?> mt-1"
            required value="<?= h($old['emirates_id_expiry'] ?? $target['emirates_id_expiry'] ?? '') ?>">
          <p class="text-xs text-slate-500 mt-1">Enter expiry date.</p>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Date of Birth</label>
          <input name="date_of_birth" type="date" class="<?= $fieldClass('date_of_birth', $invalid) ?> mt-1" required
            value="<?= h($old['date_of_birth'] ?? $target['date_of_birth']) ?>">
          <p class="text-xs text-slate-500 mt-1">YYYY-MM-DD</p>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Profession</label>
          <input name="profession" class="<?= $fieldClass('profession', $invalid) ?> mt-1" required
            value="<?= h($old['profession'] ?? $target['profession']) ?>">
          <p class="text-xs text-slate-500 mt-1">Example: Engineer</p>
        </div>
        <div class="flex flex-col md:col-span-2">
          <label class="text-sm font-semibold text-slate-700">Skills & Expertise</label>
          <textarea name="skills" rows="2" class="<?= $fieldClass('skills', $invalid) ?> mt-1"
            required><?= h($old['skills'] ?? $target['skills']) ?></textarea>
          <p class="text-xs text-slate-500 mt-1">Example: Event management, First aid.</p>
        </div>
        <?php if (is_super_admin()): ?>
          <div class="flex flex-col md:col-span-2">
            <label class="text-sm font-semibold text-slate-700">Role Title (English, shown on card)</label>
            <input name="role_title_en" class="input-field mt-1" placeholder="DP Volunteer ID Card"
              value="<?= h($old['role_title_en'] ?? $target['role_title_en'] ?? '') ?>">
            <p class="text-xs text-slate-500 mt-1">Will display in uppercase on the card.</p>
          </div>
        <?php endif; ?>
        <?php if (is_super_admin()): ?>
          <div class="flex flex-col">
            <label class="text-sm font-semibold text-slate-700">Role</label>
            <select name="role" class="input-field mt-1">
              <option value="user" <?= $target['role'] === 'user' ? 'selected' : '' ?>>User</option>
              <option value="admin" <?= $target['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
              <option value="super_admin" <?= $target['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
            </select>
            <p class="text-xs text-slate-500 mt-1">Only visible to Super Admin.</p>
          </div>
        <?php endif; ?>
        <?php if (!$viewOnlyMode): ?>
          <div class="md:col-span-3">
            <button class="btn-primary justify-center w-full md:w-auto">Save changes</button>
          </div>
        <?php else: ?>
          <input type="hidden" name="action" value="view_only">
        <?php endif; ?>
      </form>
    </div>
    <?php if (is_super_admin()): ?>
      <div class="bg-white rounded-3xl shadow border border-slate-100 p-6 space-y-4">
        <h3 class="text-lg font-semibold text-slate-900">Reset password</h3>
        <p class="text-sm text-slate-600">Leave blank to auto-generate a strong password.</p>
        <form method="post" class="flex flex-col md:flex-row gap-3 items-start md:items-center">
          <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="reset_password">
          <input type="password" name="new_password" class="input-field md:flex-1"
            placeholder="New password (min 8 chars)">
          <button class="btn-primary" type="submit">Reset password</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
<div id="eidOverlay" class="eid-overlay" style="display:none;">
  <div class="eid-overlay__card">
    <button type="button" id="eidClose" class="eid-overlay__close">Close</button>
    <?php if (!empty($target['emirates_id_image'])): ?>
      <img src="<?= BASE_URL ?>/image.php?type=eid&file=<?= urlencode(basename($target['emirates_id_image'])) ?>"
        alt="Emirates ID" class="eid-overlay__img">
    <?php endif; ?>
  </div>
</div>
<!-- Cropper.js for photo cropping -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cropper.min.css">
<script src="<?= BASE_URL ?>/assets/js/cropper.min.js"></script>
<script>
  // View-only mode: disable all form fields
  <?php if ($viewOnlyMode): ?>
      (function () {
        var form = document.getElementById('editForm');
        if (form) {
          var inputs = form.querySelectorAll('input, select, textarea');
          inputs.forEach(function (el) {
            el.disabled = true;
            el.style.opacity = '0.7';
            el.style.cursor = 'not-allowed';
          });
        }
        // Hide photo upload section for admins
        var photoSection = document.querySelector('#photoPreviewContainer');
        if (photoSection) photoSection.style.pointerEvents = 'none';
        var cameraBtn = document.getElementById('cameraBtn');
        var uploadBtn = document.getElementById('uploadBtn');
        if (cameraBtn) cameraBtn.style.display = 'none';
        if (uploadBtn) uploadBtn.style.display = 'none';
      })();
  <?php endif; ?>

    // Form submit loading state
    (function () {
      const form = document.getElementById('editForm');
      if (form) {
        form.addEventListener('submit', function () {
          const btn = form.querySelector('button[type="submit"]');
          if (btn) {
            btn.disabled = true;
            btn.classList.add('btn-loading');
            btn.textContent = 'Saving...';
          }
        });
      }
    })();

  // Main functionality after DOM loaded
  document.addEventListener('DOMContentLoaded', function () {
    // Emirates ID floating overlay
    var floatBtn = document.getElementById('eidFloatBtn');
    var overlay = document.getElementById('eidOverlay');
    var closeBtn = document.getElementById('eidClose');

    if (floatBtn && overlay) {
      floatBtn.onclick = function () { overlay.style.display = 'block'; };
    }
    if (closeBtn && overlay) {
      closeBtn.onclick = function () { overlay.style.display = 'none'; };
    }
    if (overlay) {
      overlay.onclick = function (e) {
        if (e.target === overlay) overlay.style.display = 'none';
      };
    }

    // V-number numeric only
    var vInput = document.querySelector('input[name="v_number"]');
    if (vInput) {
      vInput.oninput = function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
      };
    }

    // Profile photo cropping
    var photoInput = document.getElementById('photoInput');
    var cameraBtn = document.getElementById('cameraBtn');
    var uploadBtn = document.getElementById('uploadBtn');
    var photoDataField = document.getElementById('profilePhotoData');
    var previewContainer = document.getElementById('photoPreviewContainer');
    var currentCropper = null;

    if (cameraBtn && photoInput) {
      cameraBtn.onclick = function () {
        photoInput.setAttribute('capture', 'user');
        photoInput.click();
      };
    }

    if (uploadBtn && photoInput) {
      uploadBtn.onclick = function () {
        photoInput.removeAttribute('capture');
        photoInput.click();
      };
    }

    if (photoInput) {
      photoInput.onchange = function () {
        var file = this.files && this.files[0];
        if (!file) return;

        if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/i)) {
          alert('Please select an image file (JPG, PNG, GIF, WEBP)');
          return;
        }

        var reader = new FileReader();
        reader.onload = function (e) {
          showCropModal(e.target.result);
        };
        reader.readAsDataURL(file);
      };
    }

    function showCropModal(imageSrc) {
      // Block background scroll
      document.body.style.overflow = 'hidden';

      // Create modal elements
      var backdrop = document.createElement('div');
      backdrop.id = 'cropModalBackdrop';
      backdrop.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.85);z-index:999999;display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;';

      var modalBox = document.createElement('div');
      modalBox.style.cssText = 'background:#fff;border-radius:20px;max-width:450px;width:100%;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,0.4);';

      var title = document.createElement('h3');
      title.textContent = '✂️ Crop Profile Photo';
      title.style.cssText = 'font-size:18px;font-weight:700;text-align:center;margin:0 0 16px 0;color:#1e293b;';

      var imgContainer = document.createElement('div');
      imgContainer.style.cssText = 'background:#f1f5f9;border-radius:12px;overflow:hidden;max-height:50vh;';

      var cropImg = document.createElement('img');
      cropImg.style.cssText = 'max-width:100%;display:block;';
      imgContainer.appendChild(cropImg);

      var btnContainer = document.createElement('div');
      btnContainer.style.cssText = 'display:flex;gap:12px;justify-content:center;margin-top:20px;';

      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.textContent = 'Cancel';
      cancelBtn.style.cssText = 'padding:12px 28px;border-radius:12px;background:#e2e8f0;color:#475569;font-weight:600;border:none;cursor:pointer;font-size:14px;';

      var applyBtn = document.createElement('button');
      applyBtn.type = 'button';
      applyBtn.textContent = 'Apply Crop';
      applyBtn.style.cssText = 'padding:12px 28px;border-radius:12px;background:#10b981;color:white;font-weight:700;border:none;cursor:pointer;font-size:14px;box-shadow:0 4px 12px rgba(16,185,129,0.4);';

      btnContainer.appendChild(cancelBtn);
      btnContainer.appendChild(applyBtn);

      modalBox.appendChild(title);
      modalBox.appendChild(imgContainer);
      modalBox.appendChild(btnContainer);
      backdrop.appendChild(modalBox);
      document.body.appendChild(backdrop);

      // Close modal function
      function closeModal() {
        document.body.style.overflow = '';
        if (currentCropper) {
          currentCropper.destroy();
          currentCropper = null;
        }
        if (backdrop.parentNode) {
          backdrop.parentNode.removeChild(backdrop);
        }
        photoInput.value = '';
      }

      // Cancel button
      cancelBtn.onclick = function (e) {
        e.preventDefault();
        closeModal();
      };

      // Backdrop click to close
      backdrop.onclick = function (e) {
        if (e.target === backdrop) {
          closeModal();
        }
      };

      // Prevent modal content clicks from closing
      modalBox.onclick = function (e) {
        e.stopPropagation();
      };

      // Wait for image to load, then init cropper
      cropImg.onload = function () {
        if (typeof Cropper === 'undefined') {
          alert('Cropper library not loaded. Please refresh the page.');
          closeModal();
          return;
        }

        currentCropper = new Cropper(cropImg, {
          aspectRatio: 3 / 4,
          viewMode: 1,
          dragMode: 'move',
          autoCropArea: 0.9,
          restore: false,
          guides: true,
          center: true,
          highlight: true,
          cropBoxMovable: true,
          cropBoxResizable: true,
          toggleDragModeOnDblclick: false,
          responsive: false,  // Disable responsive to prevent reset on scroll
          background: true,
          modal: false,       // Disable modal overlay
          zoomOnWheel: false, // Disable zoom on mouse wheel
          zoomOnTouch: false, // Disable zoom on touch (pinch)
          checkCrossOrigin: false,
          checkOrientation: false
        });
      };

      cropImg.onerror = function () {
        alert('Failed to load image. Please try again.');
        closeModal();
      };

      // Set image source to trigger load
      cropImg.src = imageSrc;

      // Apply crop button
      applyBtn.onclick = function (e) {
        e.preventDefault();

        if (!currentCropper) {
          alert('Cropper not initialized. Please try again.');
          closeModal();
          return;
        }

        applyBtn.disabled = true;
        applyBtn.textContent = 'Processing...';

        try {
          var canvas = currentCropper.getCroppedCanvas({
            width: 600,
            height: 800,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
          });

          if (!canvas) {
            throw new Error('Failed to get cropped canvas');
          }

          // Convert to blob with compression
          canvas.toBlob(function (blob) {
            if (!blob) {
              alert('Failed to process image. Please try again.');
              applyBtn.disabled = false;
              applyBtn.textContent = 'Apply Crop';
              return;
            }

            var blobReader = new FileReader();
            blobReader.onload = function () {
              var dataUrl = blobReader.result;

              // Update preview
              previewContainer.innerHTML = '<img src="' + dataUrl + '" style="width:100%;height:100%;object-fit:cover;border-radius:12px;">';

              // Set hidden field value
              photoDataField.value = dataUrl;

              closeModal();
            };
            blobReader.onerror = function () {
              alert('Failed to read image data.');
              applyBtn.disabled = false;
              applyBtn.textContent = 'Apply Crop';
            };
            blobReader.readAsDataURL(blob);

          }, 'image/jpeg', 0.85);

        } catch (err) {
          console.error('Crop error:', err);
          alert('Error cropping image: ' + err.message);
          applyBtn.disabled = false;
          applyBtn.textContent = 'Apply Crop';
        }
      };
    }
  });
</script>
<style>
  .eid-overlay {
    position: fixed;
    top: 8px;
    left: 8px;
    right: 8px;
    z-index: 9999;
    display: none;
    pointer-events: none;
  }

  .eid-overlay__card {
    position: relative;
    background: #fff;
    border-radius: 20px;
    padding: 12px;
    max-width: 480px;
    width: 100%;
    margin: 0 auto;
    box-shadow: 0 14px 35px rgba(0, 0, 0, 0.16);
    pointer-events: auto;
  }

  .eid-overlay__close {
    position: absolute;
    top: -10px;
    right: -10px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 9999px;
    padding: 6px 12px;
    font-weight: 700;
    color: #1f2937;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
  }

  .eid-overlay__img {
    width: 100%;
    max-height: 60vh;
    object-fit: contain;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
  }

  .eid-grid {
    display: grid;
    grid-template-columns: 0.9fr 1.0fr 1.6fr 0.6fr;
    gap: 8px;
  }

  @media (min-width: 768px) {
    .eid-grid {
      grid-template-columns: 1.1fr 1.2fr 1.8fr 0.8fr;
      gap: 10px;
    }

    .eid-grid input {
      font-size: 1rem;
      padding-top: 0.7rem;
      padding-bottom: 0.7rem;
    }
  }
</style>
<?php render_footer(); ?>