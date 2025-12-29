<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_users');

$errors = [];
$invalid = [];
$notice = null;
$old = $_POST;
$fieldClass = function (string $name) use (&$invalid): string {
  return 'input-field' . (in_array($name, $invalid, true) ? ' field-error' : '');
};

function base64_to_temp_file(?string $dataUri): ?array
{
  if (!$dataUri)
    return null;
  if (!preg_match('#^data:(image/[^;]+);base64,(.+)$#', $dataUri, $m))
    return null;
  $bin = base64_decode($m[2]);
  if ($bin === false)
    return null;
  $tmp = tempnam(sys_get_temp_dir(), 'up_');
  file_put_contents($tmp, $bin);
  $mime = $m[1];
  $name = 'upload.' . (strpos($mime, 'png') !== false ? 'png' : 'jpg');
  return [
    'name' => $name,
    'type' => $mime,
    'tmp_name' => $tmp,
    'error' => UPLOAD_ERR_OK,
    'size' => strlen($bin),
  ];
}

function ini_bytes(string $val): int
{
  $val = trim($val);
  if ($val === '')
    return 0;
  $last = strtolower($val[strlen($val) - 1]);
  $num = (int) $val;
  switch ($last) {
    case 'g':
      $num *= 1024;
    // no break
    case 'm':
      $num *= 1024;
    // no break
    case 'k':
      $num *= 1024;
  }
  return $num;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
  $postMaxRaw = ini_get('post_max_size');
  $postMaxBytes = ini_bytes($postMaxRaw);
  if ($contentLength > 0 && empty($_POST) && empty($_FILES) && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    $errors[] = 'Upload too large for server limits (post_max_size=' . $postMaxRaw . '). Please retake/compress photos or increase post_max_size/upload_max_filesize.';
  } elseif (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Session expired. Please refresh the page.';
  } else {
    $action = $_POST['action'] ?? '';
    $eidCombined = null;

    // SECURITY: Rate limit sensitive admin actions to prevent abuse
    // This protects against compromised accounts running automated scripts
    $sensitiveActions = ['delete', 'update_role', 'reset_password'];
    if (in_array($action, $sensitiveActions, true)) {
      $adminId = current_user()['id'] ?? 0;
      if (throttle_attempt('admin_sensitive_' . $adminId, 20, 60)) { // 20 actions/minute
        $errors[] = 'Too many actions. Please wait a moment before trying again.';
        log_action('rate_limit_hit', 'security', $adminId, ['action' => $action, 'ip' => getRealIp()]);
      }
    }
    if ($action === 'create') {
      $required = ['full_name', 'full_name_ar', 'gender', 'nationality', 'nationality_ar', 'emirate', 'area', 'email', 'mobile', 'emirates_id_expiry', 'date_of_birth', 'profession', 'skills', 'eid_part1', 'eid_part2', 'eid_part3', 'eid_part4'];
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
      if (!$errors) {
        // Check duplicates using blind indexes for encrypted fields
        // SECURITY: Whitelist of allowed field names for duplicate checking
        $allowedDupFields = ['email_hash', 'mobile_hash', 'emirates_id_hash', 'v_number'];
        $dupChecks = [
          ['email_hash', 'Email', blind_index(trim($_POST['email']))],
          ['mobile_hash', 'Mobile', blind_index(trim($_POST['mobile']))],
          ['emirates_id_hash', 'Emirates ID', blind_index($eidCombined)],
          ['v_number', 'V-number', $vRaw !== '' ? 'v' . $vRaw : null]
        ];
        foreach ($dupChecks as [$field, $label, $checkVal]) {
          if ($checkVal === null)
            continue;
          // SECURITY: Validate field name against whitelist before SQL interpolation
          if (!in_array($field, $allowedDupFields, true)) {
            throw new RuntimeException("Invalid field name in duplicate check: $field");
          }
          if (fetch_one("SELECT id FROM users WHERE $field=?", [$checkVal])) {
            $errors[] = "$label already exists.";
            break;
          }
        }
        $manualDp = is_super_admin() ? strtoupper(trim($_POST['dp_code_manual'] ?? '')) : null;
        if ($manualDp && fetch_one('SELECT id FROM users WHERE dp_code=?', [$manualDp])) {
          $errors[] = 'DP Code already exists.';
        }
      }
      if (!$errors) {
        try {
          $pending = [
            'full_name' => trim($_POST['full_name']),
            'full_name_ar' => trim($_POST['full_name_ar']),
            'gender' => $_POST['gender'],
            'nationality' => trim($_POST['nationality']),
            'nationality_ar' => trim($_POST['nationality_ar']),
            'emirate' => trim($_POST['emirate']),
            'area' => trim($_POST['area']),
            'email' => trim($_POST['email']),
            'v_number' => $vRaw !== '' ? 'v' . $vRaw : null,
            'mobile' => trim($_POST['mobile']),
            'emirates_id' => $eidCombined,
            'emirates_id_expiry' => $eidExpiryRaw,
            'date_of_birth' => trim($_POST['date_of_birth']),
            'profession' => trim($_POST['profession']),
            'skills' => trim($_POST['skills']),
            'manual_dp' => is_super_admin() ? $manualDp : null,
            'role' => is_super_admin() ? ($_POST['role'] ?? 'user') : 'user',
          ];
          $dpCode = next_dp_code($pending['manual_dp'] ?? null);
          $password = trim($_POST['password'] ?? '');
          if ($password === '') {
            $password = generate_password(12);
          }
          $photo = null;
          $eidImage = null;
          $role = $pending['role'] ?? 'user';
          $expiry = $pending['emirates_id_expiry'] ?? null;
          // Encrypt sensitive fields before INSERT
          $encryptedData = encrypt_user_data([
            'full_name' => $pending['full_name'],
            'full_name_ar' => $pending['full_name_ar'],
            'email' => $pending['email'],
            'mobile' => $pending['mobile'],
            'emirates_id' => $pending['emirates_id'],
          ]);

          $stmt = execute_query(
            'INSERT INTO users (dp_code, v_number, full_name, full_name_ar, gender, nationality, nationality_ar, emirate, area, email, email_hash, mobile, mobile_hash, emirates_id, emirates_id_hash, emirates_id_expiry, emirates_id_image, date_of_birth, profession, skills, profile_photo, password_hash, role, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
              $dpCode,
              $pending['v_number'],
              $encryptedData['full_name'],
              $encryptedData['full_name_ar'],
              $pending['gender'],
              $pending['nationality'],
              $pending['nationality_ar'],
              $pending['emirate'],
              $pending['area'],
              $encryptedData['email'],
              $encryptedData['email_hash'],
              $encryptedData['mobile'],
              $encryptedData['mobile_hash'],
              $encryptedData['emirates_id'],
              $encryptedData['emirates_id_hash'],
              $expiry ?: null,
              $eidImage,
              $pending['date_of_birth'],
              $pending['profession'],
              $pending['skills'],
              $photo,
              password_hash($password, PASSWORD_DEFAULT),
              $role,
              current_user()['id']
            ]
          );
          $newId = db()->lastInsertId();
          $qrPathAbsolute = qr_path_for_code($dpCode);
          generate_qr_png($dpCode, $qrPathAbsolute);
          $qrRelative = 'assets/uploads/qr/' . basename($qrPathAbsolute);
          execute_query('UPDATE users SET qr_path=? WHERE id=?', [$qrRelative, $newId]);
          log_action('create_user', 'user', $newId, ['dp_code' => $dpCode, 'role' => $role]);
          sendDPVEmail('welcome', trim($_POST['email']), trim($_POST['full_name']), [
            'SITE_URL' => app_base_absolute(),
            'USERNAME' => $dpCode,
            'PASSWORD' => $password
          ]);
          flash('success', "User created. DP: $dpCode Password: $password. Upload photos next.");
          header('Location: ' . BASE_URL . '/admin/user_uploads.php?id=' . $newId);
          exit;
        } catch (Exception $e) {
          $errors[] = $e->getMessage();
        }
      }
    } elseif ($action === 'update_role') {
      $isSuperActor = is_super_admin();
      if (!$isSuperActor) {
        $errors[] = 'Only Super Admin can change roles.';
      } else {
        $uid = (int) $_POST['user_id'];
        $targetRole = fetch_one('SELECT role FROM users WHERE id=?', [$uid]) ?: [];
        if (!$targetRole) {
          $errors[] = 'User not found.';
        } else {
          $role = $_POST['role'] ?? 'user';
          if (!in_array($role, ['user', 'admin', 'super_admin'], true)) {
            $role = 'user';
          }
          execute_query('UPDATE users SET role=? WHERE id=?', [$role, $uid]);
          $notice = 'Role updated.';
          log_action('role_change', 'user', $uid, ['role' => $role]);
        }
      }
    } elseif ($action === 'reset_password') {
      $uid = (int) $_POST['user_id'];
      $target = fetch_one('SELECT email, full_name, role, dp_code FROM users WHERE id=?', [$uid]);
      if (!$target) {
        $errors[] = 'User not found.';
      } elseif (!is_super_admin() && ($target['role'] ?? '') === 'super_admin') {
        $errors[] = 'Only Super Admin can reset this password.';
      } else {
        $newpass = trim($_POST['new_password'] ?? '');
        if ($newpass === '') {
          $newpass = generate_password(12);
        } elseif (strlen($newpass) < 8) {
          $errors[] = 'New password must be at least 8 characters.';
        }
        if (!$errors) {
          execute_query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($newpass, PASSWORD_DEFAULT), $uid]);
          log_action('reset_password', 'user', $uid);
          if (!empty($target['email'])) {
            sendDPVEmail('pass_change', $target['email'], $target['full_name']);
          }
          $notice = 'Password reset. New password: ' . $newpass;
        }
      }
    } elseif ($action === 'delete') {
      $isSuperActor = is_super_admin();
      if (!$isSuperActor) {
        $errors[] = 'Only Super Admin can delete users.';
      } else {
        $uid = (int) $_POST['user_id'];
        if ($uid === (int) (current_user()['id'] ?? 0)) {
          $errors[] = 'You cannot delete your own account.';
        } else {
          $targetUser = fetch_user_decrypted('SELECT * FROM users WHERE id=?', [$uid]);
          if (!$targetUser) {
            $errors[] = 'User not found.';
          } else {
            // Delete user files (avatar, emirates_id, qr) before deleting record
            delete_user_files($targetUser);
            execute_query('DELETE FROM users WHERE id=?', [$uid]);
            $notice = 'User and associated files deleted.';
            log_action('delete_user', 'user', $uid);
          }
        }
      }
    }
  }
}

$q = trim($_GET['q'] ?? '');
$params = [];
$conditions = [];
if (!is_super_admin()) {
  $conditions[] = "role <> 'super_admin'";
  // Admin only sees users they created
  $conditions[] = 'created_by = ' . (int) current_user()['id'];
}
if ($q !== '') {
  // For encrypted fields (email, mobile), we can only do exact match via blind index
  // For dp_code and v_number, we can do LIKE since they're not encrypted
  $emailHash = function_exists('blind_index') ? blind_index($q) : null;
  $mobileHash = function_exists('blind_index') ? blind_index($q) : null;

  // Search: dp_code LIKE, v_number LIKE, OR exact match on email_hash/mobile_hash
  $searchParts = ["dp_code LIKE ?", "v_number LIKE ?"];
  $like = "%$q%";
  $params[] = $like;
  $params[] = $like;

  // Add blind index search for exact email/mobile match
  if ($emailHash) {
    $searchParts[] = "email_hash = ?";
    $params[] = $emailHash;
  }
  if ($mobileHash) {
    $searchParts[] = "mobile_hash = ?";
    $params[] = $mobileHash;
  }

  $conditions[] = "(" . implode(" OR ", $searchParts) . ")";
}
$whereClause = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';
$sql = "SELECT id, dp_code, full_name, email, mobile, emirates_id, emirate, role, created_at, profile_photo, created_by FROM users" . $whereClause;
$sql .= " ORDER BY created_at DESC LIMIT 200";
$users = fetch_users_decrypted($sql, $params);

// Client-side filter for name search (since full_name is encrypted, we filter after decryption)
if ($q !== '' && $users) {
  $qLower = mb_strtolower($q);
  $users = array_filter($users, function ($u) use ($qLower, $q) {
    // Already matched by SQL? Keep it
    if (stripos($u['dp_code'] ?? '', $q) !== false)
      return true;
    if (stripos($u['v_number'] ?? '', $q) !== false)
      return true;
    // Match on decrypted name
    if (stripos($u['full_name'] ?? '', $q) !== false)
      return true;
    if (stripos($u['full_name_ar'] ?? '', $q) !== false)
      return true;
    // Match on decrypted email/mobile (for partial matches)
    if (stripos($u['email'] ?? '', $q) !== false)
      return true;
    if (stripos($u['mobile'] ?? '', $q) !== false)
      return true;
    return false;
  });
  $users = array_values($users);
}

// Persist filled values on validation errors
$old = $_POST;
$invalidFields = array_unique($invalid);
$fieldClass = function (string $name) use ($invalidFields): string {
  return 'input-field' . (in_array($name, $invalidFields, true) ? ' field-error' : '');
};

// PERF-01 FIX: Pre-fetch all creator names to avoid N+1 query problem
// Instead of calling get_creator_name() inside the loop, we fetch all at once
$creatorNames = [];
if (is_super_admin() && $users) {
  $creatorIds = array_unique(array_filter(array_column($users, 'created_by')));
  if ($creatorIds) {
    $placeholders = implode(',', array_fill(0, count($creatorIds), '?'));
    $creators = fetch_users_decrypted(
      "SELECT id, full_name FROM users WHERE id IN ($placeholders)",
      array_values($creatorIds)
    );
    foreach ($creators as $c) {
      $creatorNames[(int) $c['id']] = $c['full_name'];
    }
  }
}

render_header('User Management');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">👥 User Management</p>
        <h1 class="dpv-page-header__title">Users & Roles</h1>
        <p class="dpv-page-header__subtitle">Create users with full profile and manage their roles</p>
      </div>
      <div class="dpv-page-header__actions">
        <?php if (is_super_admin()): ?>
          <a href="<?= BASE_URL ?>/admin/admins.php" class="dpv-page-header__btn">
            🛡️ Manage Admins
          </a>
        <?php endif; ?>
        <a href="<?= dashboard_url() ?>" class="dpv-page-header__btn">
          🏠 Dashboard
        </a>
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
    <div>
      <h2 class="text-lg font-semibold text-slate-900">Create user</h2>
    </div>
    <form id="createUserForm" method="post" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="create">
      <?php if (is_super_admin()): ?>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Manual DP Code (optional)</label>
          <input name="dp_code_manual" class="<?= $fieldClass('dp_code_manual') ?> mt-1">
          <p class="text-xs text-slate-500 mt-1">Example: DP5000 (leave blank for auto).</p>
        </div>
      <?php endif; ?>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">V-number</label>
        <div class="flex items-center gap-2 mt-1">
          <span class="px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 font-semibold text-slate-700">V</span>
          <input name="v_number" maxlength="6" inputmode="numeric"
            class="<?= $fieldClass('v_number') ?> mt-0 flex-1 text-center" placeholder="123456">
        </div>
        <p class="text-xs text-slate-500 mt-1">Must be registered on volunteers.ae (6 digits). Example: V123456</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Full name as appears on the Emirates ID</label>
        <input name="full_name" class="<?= $fieldClass('full_name') ?> mt-1" required
          value="<?= h($old['full_name'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Example: Ahmed Al Mansoori</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Full name in Arabic as appears on the Emirates ID</label>
        <input name="full_name_ar" class="<?= $fieldClass('full_name_ar') ?> mt-1" required dir="rtl"
          value="<?= h($old['full_name_ar'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Example: أحمد المنصوري</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Gender</label>
        <div class="choice-wrap mt-1" role="group" aria-label="Gender">
          <?php foreach (['Male', 'Female'] as $g): ?>
            <?php $active = (($old['gender'] ?? 'Male') === $g); ?>
            <button type="button" class="choice-btn <?= $active ? 'is-active' : '' ?>" data-choice="gender"
              data-value="<?= $g ?>">
              <span class="choice-dot"></span><span><?= $g ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="gender" id="genderChoice" value="<?= h($old['gender'] ?? 'Male') ?>">
        <p class="text-xs text-slate-500 mt-1">Tap to select gender.</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Nationality</label>
        <input name="nationality" class="<?= $fieldClass('nationality') ?> mt-1" required
          value="<?= h($old['nationality'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Example: UAE</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Nationality (Arabic)</label>
        <input name="nationality_ar" class="<?= $fieldClass('nationality_ar') ?> mt-1" required dir="rtl"
          value="<?= h($old['nationality_ar'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">مثال: الإمارات العربية المتحدة</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Emirate of Residence</label>
        <div class="choice-wrap grid grid-cols-2 sm:grid-cols-3 gap-2 mt-1" role="group" aria-label="Emirate">
          <?php foreach (['Abu Dhabi (أبو ظبي)', 'Dubai (دبي)', 'Sharjah (الشارقة)', 'Ajman (عجمان)', 'Umm Al Quwain (أم القيوين)', 'Ras Al Khaimah (رأس الخيمة)', 'Fujairah (الفجيرة)'] as $em): ?>
            <?php $active = (($old['emirate'] ?? 'Abu Dhabi (أبو ظبي)') === $em); ?>
            <button type="button" class="choice-btn <?= $active ? 'is-active' : '' ?>" data-choice="emirate"
              data-value="<?= $em ?>">
              <span class="choice-dot"></span><span><?= $em ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="emirate" id="emirateChoice"
          value="<?= h($old['emirate'] ?? 'Abu Dhabi (أبو ظبي)') ?>">
        <p class="text-xs text-slate-500 mt-1">Tap to pick your emirate.</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Area / Community</label>
        <input name="area" class="<?= $fieldClass('area') ?> mt-1" required value="<?= h($old['area'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Example: Al Barsha</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Email Address</label>
        <input name="email" type="email" class="<?= $fieldClass('email') ?> mt-1" required
          value="<?= h($old['email'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Must be unique, e.g., user@email.com</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Mobile Number (UAE)</label>
        <input name="mobile" inputmode="numeric" pattern="05[0-9]{8}" maxlength="10"
          class="<?= $fieldClass('mobile') ?> mt-1" required value="<?= h($old['mobile'] ?? '') ?>"
          aria-describedby="mobileHelp">
        <p class="text-xs text-slate-500 mt-1" id="mobileHelp">Format: 05xxxxxxxx (10 digits, e.g., 0502984113)</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Emirates ID</label>
        <div class="eid-grid mt-1 items-center" data-eid-group>
          <input name="eid_part1" inputmode="numeric" pattern="[0-9]*" maxlength="3"
            class="<?= $fieldClass('eid_part1') ?> text-center bg-slate-100 font-semibold w-full" value="784" readonly
            required>
          <input name="eid_part2" inputmode="numeric" pattern="[0-9]*" maxlength="4"
            class="<?= $fieldClass('eid_part2') ?> text-center font-semibold w-full"
            value="<?= h($old['eid_part2'] ?? '') ?>" required>
          <input name="eid_part3" inputmode="numeric" pattern="[0-9]*" maxlength="7"
            class="<?= $fieldClass('eid_part3') ?> text-center font-semibold w-full"
            value="<?= h($old['eid_part3'] ?? '') ?>" required>
          <input name="eid_part4" inputmode="numeric" pattern="[0-9]*" maxlength="1"
            class="<?= $fieldClass('eid_part4') ?> text-center font-semibold w-full"
            value="<?= h($old['eid_part4'] ?? '') ?>" required>
        </div>
        <p class="text-xs text-slate-500 mt-1">Format: 784-XXXX-XXXXXXX-X (enter numbers only).</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Emirates ID Expiry</label>
        <input name="emirates_id_expiry" type="date" class="<?= $fieldClass('emirates_id_expiry') ?> mt-1" required
          value="<?= h($old['emirates_id_expiry'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Enter expiry date.</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Date of Birth</label>
        <input name="date_of_birth" type="date" class="<?= $fieldClass('date_of_birth') ?> mt-1" required
          value="<?= h($old['date_of_birth'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">YYYY-MM-DD</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Profession</label>
        <input name="profession" class="<?= $fieldClass('profession') ?> mt-1" required
          value="<?= h($old['profession'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Example: Engineer</p>
      </div>
      <div class="flex flex-col md:col-span-2">
        <label class="text-sm font-semibold text-slate-700">Skills & Expertise</label>
        <textarea name="skills" rows="2" class="<?= $fieldClass('skills') ?> mt-1"
          required><?= h($old['skills'] ?? '') ?></textarea>
        <p class="text-xs text-slate-500 mt-1">Example: Event management, First aid.</p>
      </div>
      <div class="flex flex-col">
        <label class="text-sm font-semibold text-slate-700">Password (leave blank to auto-generate)</label>
        <input type="text" name="password" class="<?= $fieldClass('password') ?> mt-1"
          value="<?= h($old['password'] ?? '') ?>">
        <p class="text-xs text-slate-500 mt-1">Leave empty to auto-generate a strong password.</p>
      </div>
      <?php if (is_super_admin()): ?>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Role</label>
          <select name="role" class="<?= $fieldClass('role') ?> mt-1">
            <option value="user" <?= (($old['role'] ?? '') === 'user') ? 'selected' : '' ?>>User</option>
            <option value="admin" <?= (($old['role'] ?? '') === 'admin') ? 'selected' : '' ?>>Admin</option>
            <option value="super_admin" <?= (($old['role'] ?? '') === 'super_admin') ? 'selected' : '' ?>>Super Admin
            </option>
          </select>
          <p class="text-xs text-slate-500 mt-1">Only visible to Super Admin.</p>
        </div>
      <?php endif; ?>
      <div class="md:col-span-3">
        <button class="btn-primary justify-center w-full md:w-auto">Create User</button>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-3xl shadow border border-slate-100 p-6 space-y-4">
    <div class="flex items-center justify-between gap-3">
      <h2 class="text-lg font-semibold text-slate-900">Users</h2>
      <form method="get" class="flex items-center gap-2">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search DP, name, email, mobile"
          class="input-field">
        <button class="btn-ghost" type="submit">Search</button>
      </form>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php if (!$users): ?>
        <div class="p-4 rounded-2xl border border-slate-200 bg-slate-50 text-slate-500 col-span-full">No users found.
        </div>
      <?php endif; ?>
      <?php foreach ($users as $row): ?>
        <div class="p-4 rounded-2xl border border-slate-200 bg-white shadow-sm flex flex-col items-center gap-3">
          <?php if (!empty($row['profile_photo'])): ?>
            <img src="<?= BASE_URL ?>/image.php?type=avatar&file=<?= urlencode(basename($row['profile_photo'])) ?>"
              alt="avatar" class="w-20 h-20 rounded-2xl object-cover border border-slate-200">
          <?php else: ?>
            <div
              class="w-20 h-20 rounded-2xl bg-slate-100 text-slate-700 font-bold flex items-center justify-center border border-slate-200">
              <?= h($row['dp_code']) ?>
            </div>
          <?php endif; ?>
          <div class="text-center space-y-1">
            <div class="font-mono font-semibold text-slate-900 text-sm"><?= h($row['dp_code']) ?></div>
            <div class="text-base font-semibold text-slate-900 leading-tight"><?= h($row['full_name']) ?></div>
            <?php if (is_super_admin() && !empty($row['created_by'])): ?>
              <?php $creatorName = $creatorNames[(int) $row['created_by']] ?? null; ?>
              <?php if ($creatorName): ?>
                <div class="text-xs text-slate-500">👤 Added by: <?= h($creatorName) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <div class="w-full flex flex-col gap-2">
            <a class="btn-ghost w-full justify-center"
              href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $row['id'] ?>">View</a>
            <?php if (is_super_admin()): ?>
              <form method="post" onsubmit="return confirm('Delete this user?');" class="w-full">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                <button class="w-full py-2 rounded-xl border border-red-200 text-red-700 font-semibold bg-red-50"
                  type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
  (function () {
    const form = document.getElementById('createUserForm');
    if (form) {
      const submitBtn = form.querySelector('button[type="submit"]');
      form.addEventListener('submit', function () {
        if (submitBtn) {
          submitBtn.classList.add('btn-loading');
          submitBtn.dataset.originalText = submitBtn.textContent;
          submitBtn.textContent = 'Processing...';
        }
        const overlayId = 'loadingOverlay';
        if (!document.getElementById(overlayId)) {
          const div = document.createElement('div');
          div.id = overlayId;
          div.style.position = 'fixed';
          div.style.inset = '0';
          div.style.background = 'rgba(255,255,255,0.65)';
          div.style.backdropFilter = 'blur(2px)';
          div.style.display = 'flex';
          div.style.alignItems = 'center';
          div.style.justifyContent = 'center';
          div.style.zIndex = '9999';
          div.innerHTML = '<div class="spinner-lite"><div></div><div></div><div></div><div></div></div><div style="margin-top:12px;font-weight:700;color:#0f172a;">Saving...</div>';
          document.body.appendChild(div);
        }
      });
    }
    <?php if ($errors): ?>
      const firstErrorField = document.querySelector('.field-error');
      if (firstErrorField && typeof firstErrorField.scrollIntoView === 'function') {
        firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    <?php endif; ?>

    async function compressImage(file, maxW, maxH, quality, targetKB) {
      return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
          let { width, height } = img;
          const ratio = Math.min(maxW / width, maxH / height, 1);
          width = Math.round(width * ratio);
          height = Math.round(height * ratio);
          const canvas = document.createElement('canvas');
          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, width, height);
          let q = quality;
          function attempt() {
            canvas.toBlob(blob => {
              if (!blob) return reject(new Error('Blob failed'));
              const kb = blob.size / 1024;
              if (kb > targetKB && q > 0.4) {
                q -= 0.1;
                return attempt();
              }
              resolve(blob);
            }, 'image/jpeg', q);
          }
          attempt();
        };
        img.onerror = reject;
        img.src = URL.createObjectURL(file);
      });
    }

    function wireCompress(inputId, maxW, maxH, quality, targetKB) {
      const input = document.querySelector(`input[name="${inputId}"]`);
      if (!input) return;
      input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) return;
        try {
          const blob = await compressImage(file, maxW, maxH, quality, targetKB);
          const dt = new DataTransfer();
          dt.items.add(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
          input.files = dt.files;
        } catch (e) {
          console.warn('Compression skipped', e);
        }
      });
    }

    function toDataUri(file, cb) {
      const reader = new FileReader();
      reader.onload = () => cb(reader.result);
      reader.readAsDataURL(file);
    }
  })();

  // Toggle buttons for gender and emirate
  document.addEventListener('DOMContentLoaded', () => {
    function wireChoice(group, hiddenId) {
      const buttons = document.querySelectorAll(`button[data-choice="${group}"]`);
      const hidden = document.getElementById(hiddenId);
      buttons.forEach(btn => {
        btn.addEventListener('click', () => {
          buttons.forEach(b => b.classList.remove('is-active'));
          btn.classList.add('is-active');
          if (hidden) hidden.value = btn.getAttribute('data-value') || '';
        });
      });
    }
    wireChoice('gender', 'genderChoice');
    wireChoice('emirate', 'emirateChoice');

    // Enforce numeric-only and auto-advance for V-number (6 digits)
    const vInput = document.querySelector('input[name="v_number"]');
    if (vInput) {
      vInput.addEventListener('input', () => {
        const digits = (vInput.value || '').replace(/\D+/g, '').slice(0, 6);
        vInput.value = digits;
        if (digits.length >= 6) {
          const focusables = Array.from(document.querySelectorAll('input,select,textarea,button')).filter(el => !el.disabled && el.type !== 'hidden');
          const idx = focusables.indexOf(vInput);
          if (idx >= 0 && focusables[idx + 1]) {
            focusables[idx + 1].focus();
          }
        }
      });
    }

    // Auto-advance for Emirates ID parts
    const eidGroup = document.querySelector('[data-eid-group]');
    if (eidGroup) {
      const fields = Array.from(eidGroup.querySelectorAll('input'));
      fields.forEach((field, idx) => {
        field.addEventListener('input', () => {
          const max = parseInt(field.getAttribute('maxlength') || '0', 10);
          if (max && field.value.length >= max) {
            const next = fields[idx + 1];
            if (next) next.focus();
          }
        });
      });
    }

    // Auto-advance for mobile to Emirates ID (10 digits)
    const mobileInput = document.querySelector('input[name="mobile"]');
    if (mobileInput && eidGroup) {
      const eidParts = Array.from(eidGroup.querySelectorAll('input'));
      mobileInput.addEventListener('input', () => {
        if (mobileInput.value.length >= 10) {
          const firstEditable = eidParts.find(el => !el.readOnly);
          if (firstEditable) firstEditable.focus();
        }
      });
    }
  });
</script>
<style>
  .choice-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

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
    width: 100%;
    justify-content: flex-start;
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

  @media (min-width: 768px) {
    .choice-btn {
      width: auto;
    }
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