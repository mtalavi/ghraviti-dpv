<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_users');

$uid = (int) ($_GET['id'] ?? 0);
$user = fetch_user_decrypted('SELECT * FROM users WHERE id=?', [$uid]);
if (!$user) {
  http_response_code(404);
  exit('User not found.');
}
if (!is_super_admin() && $user['role'] === 'super_admin') {
  http_response_code(403);
  exit('Forbidden');
}

// SECURITY FIX: Regular admins can only upload photos for users they created
if (!is_super_admin()) {
  if ((int) ($user['created_by'] ?? 0) !== (int) current_user()['id']) {
    http_response_code(403);
    exit('Forbidden: You can only upload photos for users you created.');
  }
}


$errors = [];
$notice = flash('success');
$autoRedirect = false;
$isAjax = (($_GET['ajax'] ?? '') === '1') || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Session expired. Please refresh the page.';
    if ($isAjax) {
      echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
      exit;
    }
  } else {
    $updates = [];
    $params = [];
    try {
      if (!empty($_FILES['profile_photo']) && ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $photo = handle_avatar_upload($_FILES['profile_photo']);
        $updates[] = 'profile_photo=?';
        $params[] = $photo;
      }
      if (!empty($_FILES['emirates_id_image']) && ($_FILES['emirates_id_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $eid = handle_emirates_id_upload($_FILES['emirates_id_image']);
        $updates[] = 'emirates_id_image=?';
        $params[] = $eid;
      }
    } catch (Exception $e) {
      $errors[] = $e->getMessage();
    }
    if (!$errors && $updates) {
      $params[] = $uid;
      execute_query('UPDATE users SET ' . implode(',', $updates) . ' WHERE id=?', $params);
      log_action('upload_user_docs', 'user', $uid);
      if ($isAjax) {
        echo json_encode([
          'success' => true,
          'message' => 'Photos updated.',
          'redirect' => BASE_URL . '/admin/users.php'
        ]);
        exit;
      }
      flash('success', 'Photos updated.');
      header('Location: ' . BASE_URL . '/admin/user_uploads.php?id=' . $uid . '&done=1');
      exit;
    } elseif (!$errors && !$updates) {
      $errors[] = 'Please select a photo to upload.';
      if ($isAjax) {
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
      }
    } elseif ($errors && $isAjax) {
      echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
      exit;
    }
  }
}

$autoRedirect = isset($_GET['done']) && !$errors && $notice;

render_header('Upload user photos');
?>
<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">📷 Upload Photos</p>
        <h1 class="dpv-page-header__title"><?= h($user['full_name']) ?></h1>
        <p class="dpv-page-header__subtitle">Attach profile photo and Emirates ID image for this user.</p>
      </div>
      <div class="dpv-page-header__actions">
        <a href="<?= BASE_URL ?>/admin/users.php" class="dpv-page-header__btn">
          👥 All Users
        </a>
        <a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $uid ?>" class="dpv-page-header__btn">
          ✏️ Edit Profile
        </a>
      </div>
    </div>
  </div>

  <?php if ($notice && !$autoRedirect): ?>
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
    <div class="flex items-center gap-4">
      <?php if (!empty($user['profile_photo'])): ?>
        <img src="<?= BASE_URL ?>/image.php?type=avatar&file=<?= urlencode(basename($user['profile_photo'])) ?>"
          alt="avatar" class="w-16 h-16 rounded-2xl object-cover border border-slate-200">
      <?php else: ?>
        <div
          class="w-16 h-16 rounded-2xl bg-slate-100 text-slate-700 font-bold flex items-center justify-center border border-slate-200">
          <?= h($user['dp_code']) ?>
        </div>
      <?php endif; ?>
      <div>
        <div class="font-mono text-sm font-semibold text-slate-900"><?= h($user['dp_code']) ?></div>
        <div class="text-base font-semibold text-slate-900"><?= h($user['full_name']) ?></div>
        <div class="text-xs text-slate-600 capitalize"><?= h($user['role']) ?></div>
      </div>
    </div>

    <form id="uploadForm" method="post" enctype="multipart/form-data" class="space-y-4">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="upload">
      <div class="grid md:grid-cols-2 gap-4">
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Profile Photo</label>
          <input type="file" name="profile_photo" id="profileInput" accept="image/*" class="input-field mt-1">
          <div id="profileCropContainer"
            class="border border-dashed border-slate-200 rounded-xl mt-3 bg-slate-50 relative"
            style="min-height: 350px; height: 350px; overflow: hidden;">
            <img id="profilePreview" alt="Profile preview" style="display:none; max-width:100%; max-height:100%;">
            <span class="text-xs text-slate-500 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2"
              id="profilePlaceholder">Pick an image to crop</span>
          </div>
          <p class="text-xs text-slate-500 mt-1">Optional; 1:1 square crop (for card). Max <?= AVATAR_MAX_KB ?>KB after
            compression.
          </p>
          <?php if (!empty($user['profile_photo'])): ?>
            <a href="<?= BASE_URL ?>/image.php?type=avatar&file=<?= urlencode(basename($user['profile_photo'])) ?>"
              target="_blank" class="text-xs text-emerald-700 underline mt-1">View current</a>
          <?php endif; ?>
        </div>
        <div class="flex flex-col">
          <label class="text-sm font-semibold text-slate-700">Emirates ID Photo (camera/upload)</label>
          <input type="file" name="emirates_id_image" id="eidInput" accept="image/*" class="input-field mt-1">
          <div id="eidCropContainer" class="border border-dashed border-slate-200 rounded-xl mt-3 bg-slate-50 relative"
            style="min-height: 300px; height: 300px; overflow: hidden;">
            <img id="eidPreview" alt="EID preview" style="display:none; max-width:100%; max-height:100%;">
            <span class="text-xs text-slate-500 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2"
              id="eidPlaceholder">Pick an image to crop</span>
          </div>
          <p class="text-xs text-slate-500 mt-1">Optional; 3:2 crop. Max <?= ID_DOC_MAX_KB ?>KB after compression.</p>
          <?php if (!empty($user['emirates_id_image'])): ?>
            <a href="<?= BASE_URL ?>/image.php?type=eid&file=<?= urlencode(basename($user['emirates_id_image'])) ?>"
              target="_blank" class="text-xs text-emerald-700 underline mt-1">View current</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="flex items-center gap-3 flex-wrap">
        <button class="btn-primary" type="submit" id="uploadBtn">Crop & Upload</button>
        <span class="text-sm text-slate-500" id="statusText"></span>
      </div>
    </form>
  </div>
</div>
<div id="successOverlay"
  class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center z-50 <?= ($notice && $autoRedirect) ? '' : 'hidden' ?>">
  <div class="bg-white rounded-3xl shadow-2xl p-6 w-full max-w-sm text-center space-y-2">
    <h3 class="text-lg font-semibold text-slate-900">Saved successfully</h3>
    <p class="text-sm text-slate-600">Redirecting to user creation...</p>
  </div>
</div>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cropper.min.css">
<script src="<?= BASE_URL ?>/assets/js/cropper.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('uploadForm');
    var uploadBtn = document.getElementById('uploadBtn');
    var statusText = document.getElementById('statusText');
    var successOverlay = document.getElementById('successOverlay');
    var profileInput = document.getElementById('profileInput');
    var eidInput = document.getElementById('eidInput');
    var profilePreview = document.getElementById('profilePreview');
    var eidPreview = document.getElementById('eidPreview');
    var profilePlaceholder = document.getElementById('profilePlaceholder');
    var eidPlaceholder = document.getElementById('eidPlaceholder');
    var profileContainer = document.getElementById('profileCropContainer');
    var eidContainer = document.getElementById('eidCropContainer');
    var redirectTarget = "<?= BASE_URL ?>/admin/users.php";
    var userId = <?= $uid ?>;

    var profileCropper = null;
    var eidCropper = null;

    // Helper function to setup cropper for each input
    function setupImageInput(inputEl, previewEl, placeholderEl, containerEl, aspectRatio, getCropper, setCropper) {
      if (!inputEl) return;

      inputEl.onchange = function () {
        var file = this.files && this.files[0];
        if (!file) return;

        if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/i)) {
          alert('Please select a valid image file (JPG, PNG, GIF, WEBP)');
          this.value = '';
          return;
        }

        // Destroy existing cropper
        var existingCropper = getCropper();
        if (existingCropper) {
          existingCropper.destroy();
          setCropper(null);
        }

        // Read file and display
        var reader = new FileReader();
        reader.onload = function (e) {
          placeholderEl.style.display = 'none';
          previewEl.style.display = 'block';

          // Wait for image to load before creating cropper
          previewEl.onload = function () {
            if (typeof Cropper === 'undefined') {
              alert('Cropper library not loaded. Please refresh the page.');
              return;
            }

            var cropper = new Cropper(previewEl, {
              aspectRatio: aspectRatio,
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
              checkOrientation: false,
              minContainerHeight: 250,
              minContainerWidth: 250
            });

            setCropper(cropper);
            statusText.textContent = 'Image loaded. Adjust crop area and click Upload.';
          };

          previewEl.onerror = function () {
            alert('Failed to load image. Please try again.');
            placeholderEl.style.display = 'block';
            previewEl.style.display = 'none';
          };

          previewEl.src = e.target.result;
        };

        reader.onerror = function () {
          alert('Failed to read file. Please try again.');
        };

        reader.readAsDataURL(file);
      };
    }

    // Setup profile image input (1:1 square for card display)
    setupImageInput(
      profileInput,
      profilePreview,
      profilePlaceholder,
      profileContainer,
      1 / 1,
      function () { return profileCropper; },
      function (c) { profileCropper = c; }
    );

    // Setup EID image input
    setupImageInput(
      eidInput,
      eidPreview,
      eidPlaceholder,
      eidContainer,
      3 / 2,
      function () { return eidCropper; },
      function (c) { eidCropper = c; }
    );

    // Form submission handler
    if (form) {
      form.onsubmit = function (e) {
        e.preventDefault();

        if (!profileCropper && !eidCropper) {
          statusText.textContent = 'Please select at least one image first.';
          return;
        }

        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Processing...';
        statusText.textContent = 'Preparing images...';

        var formData = new FormData();
        formData.append('csrf', form.querySelector('input[name="csrf"]').value);

        var promises = [];

        // Process profile photo
        if (profileCropper) {
          promises.push(new Promise(function (resolve, reject) {
            try {
              var canvas = profileCropper.getCroppedCanvas({
                width: 600,
                height: 600,  // Square to match card avatar
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high'
              });

              if (!canvas) {
                reject(new Error('Failed to create profile canvas'));
                return;
              }

              canvas.toBlob(function (blob) {
                if (!blob) {
                  reject(new Error('Failed to create profile blob'));
                  return;
                }
                formData.append('profile_photo', blob, 'profile.jpg');
                resolve();
              }, 'image/jpeg', 0.85);
            } catch (err) {
              reject(err);
            }
          }));
        }

        // Process EID photo
        if (eidCropper) {
          promises.push(new Promise(function (resolve, reject) {
            try {
              var canvas = eidCropper.getCroppedCanvas({
                width: 1200,
                height: 800,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high'
              });

              if (!canvas) {
                reject(new Error('Failed to create EID canvas'));
                return;
              }

              canvas.toBlob(function (blob) {
                if (!blob) {
                  reject(new Error('Failed to create EID blob'));
                  return;
                }
                formData.append('emirates_id_image', blob, 'eid.jpg');
                resolve();
              }, 'image/jpeg', 0.85);
            } catch (err) {
              reject(err);
            }
          }));
        }

        // Wait for all blobs to be created, then upload
        Promise.all(promises)
          .then(function () {
            statusText.textContent = 'Uploading...';

            return fetch(window.location.pathname + '?ajax=1&id=' + userId, {
              method: 'POST',
              body: formData,
              credentials: 'same-origin'
            });
          })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            if (data.success) {
              statusText.textContent = 'Saved! Redirecting...';
              successOverlay.classList.remove('hidden');
              setTimeout(function () {
                window.location.href = redirectTarget;
              }, 1500);
            } else {
              throw new Error(data.error || 'Upload failed');
            }
          })
          .catch(function (err) {
            console.error('Upload error:', err);
            statusText.textContent = 'Error: ' + err.message;
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Crop & Upload';
          });
      };
    }

    // Auto redirect on success
    <?php if ($autoRedirect): ?>
      successOverlay.classList.remove('hidden');
      setTimeout(function () { window.location.href = redirectTarget; }, 1500);
    <?php endif; ?>
  });
</script>
<style>
  /* Cropper.js container fix */
  #profileCropContainer,
  #eidCropContainer {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .cropper-container {
    max-height: 340px !important;
  }

  #profilePreview,
  #eidPreview {
    max-width: 100%;
    max-height: 340px;
  }

  /* Ensure cropper controls are visible */
  .cropper-view-box,
  .cropper-face {
    outline: none !important;
  }

  .cropper-crop-box {
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
  }
</style>
<?php render_footer(); ?>