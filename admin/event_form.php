<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
require_role(['admin']);
require_permission('manage_events');

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$event = $id ? fetch_one('SELECT * FROM events WHERE id=?', [$id]) : null;
$errors = [];
$absoluteBase = app_base_absolute();
$generatedPw = null;
$generatedConsoleLink = null;

$makeSlug = function (int $length = 16): string {
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
};
$uniqueSlug = function (string $column, int $length = 16) use ($makeSlug): string {
    // SECURITY: Whitelist allowed column names to prevent SQL injection
    $allowedColumns = ['public_slug', 'console_slug'];
    if (!in_array($column, $allowedColumns, true)) {
        throw new RuntimeException("Invalid column name for slug: $column");
    }
    do {
        $slug = $makeSlug($length);
        $exists = fetch_one("SELECT id FROM events WHERE {$column}=?", [$slug]);
    } while ($exists);
    return $slug;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Security check failed.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $capacityRaw = trim($_POST['capacity'] ?? '');
        $capacity = null;
        $start = $_POST['start_datetime'] ?? '';
        $endInput = $_POST['end_datetime'] ?? '';
        $end = $endInput === '' ? null : $endInput;
        $loc = trim($_POST['location'] ?? '');
        if ($name === '' || $start === '' || $loc === '') {
            $errors[] = 'Name, start date, and location are required.';
        }
        if ($capacityRaw !== '') {
            if (!ctype_digit($capacityRaw)) {
                $errors[] = 'Capacity must be a number (leave blank for unlimited).';
            } else {
                $capacity = (int) $capacityRaw;
            }
        }

        // Handle banner image upload (cropped base64 from JavaScript)
        $bannerPath = $event['banner_image'] ?? null;
        $bannerData = trim($_POST['banner_cropped_data'] ?? '');

        try {
            if ($bannerData !== '' && preg_match('#^data:(image/[^;]+);base64,(.+)$#', $bannerData, $m)) {
                // Base64 cropped image from Cropper.js
                $bin = base64_decode($m[2]);
                if ($bin === false) {
                    throw new Exception('Invalid banner image data');
                }
                $tmp = tempnam(sys_get_temp_dir(), 'banner_');
                file_put_contents($tmp, $bin);
                $mime = $m[1];
                $ext = (strpos($mime, 'png') !== false) ? 'png' : ((strpos($mime, 'webp') !== false) ? 'webp' : 'jpg');
                $fakeFile = [
                    'name' => 'banner.' . $ext,
                    'type' => $mime,
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => strlen($bin)
                ];
                $bannerPath = handle_event_banner_upload($fakeFile);
                @unlink($tmp);
            } elseif (!empty($_FILES['banner']) && ($_FILES['banner']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                // Fallback: direct file upload (without cropping)
                $bannerPath = handle_event_banner_upload($_FILES['banner']);
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }

        if (!$errors) {
            try {
                $publicSlug = $event['public_slug'] ?? $uniqueSlug('public_slug', 16);
                if (!$event && !$publicSlug) {
                    $publicSlug = $uniqueSlug('public_slug', 16);
                }
                $consoleSlug = $event['console_slug'] ?? $uniqueSlug('console_slug', 16);
                if (!$event && !$consoleSlug) {
                    $consoleSlug = $uniqueSlug('console_slug', 16);
                }
                if ($id) {
                    execute_query(
                        'UPDATE events SET name=?, description=?, start_datetime=?, end_datetime=?, location=?, capacity=?, banner_image=? WHERE id=?',
                        [$name, $desc, $start, $end, $loc, $capacity, $bannerPath, $id]
                    );
                    log_action('event_update', 'event', $id);
                } else {
                    $pw = generate_numeric_code(6);
                    $generatedPw = $pw;
                    $hash = password_hash($pw, PASSWORD_DEFAULT);
                    $stmt = execute_query(
                        'INSERT INTO events (name, description, start_datetime, end_datetime, location, capacity, public_slug, console_slug, console_password_hash, banner_image, created_by)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                        [$name, $desc, $start, $end, $loc, $capacity, $publicSlug, $consoleSlug, $hash, $bannerPath, current_user()['id']]
                    );
                    $id = db()->lastInsertId();
                    if ($generatedPw) {
                        $generatedConsoleLink = $absoluteBase . '/event/console.php?slug=' . urlencode($consoleSlug);
                        $_SESSION['event_pw_notice'] = ['slug' => $consoleSlug, 'password' => $generatedPw, 'link' => $generatedConsoleLink];
                    }
                    log_action('event_create', 'event', $id);
                }
                redirect(BASE_URL . '/admin/events.php?saved=1');
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

render_header($id ? 'Edit Event' : 'New Event');
?>
<!-- Cropper.js - Local -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/cropper.min.css">
<script src="<?= BASE_URL ?>/assets/js/cropper.min.js"></script>

<style>
    .banner-upload-zone {
        position: relative;
        border: 2px dashed #cbd5e1;
        border-radius: 1rem;
        padding: 2rem;
        text-align: center;
        transition: all 0.2s;
        cursor: pointer;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }

    .banner-upload-zone:hover {
        border-color: #10b981;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
    }

    .banner-upload-zone.dragover {
        border-color: #10b981;
        background: #ecfdf5;
        transform: scale(1.01);
    }

    /* Cropper Container */
    .cropper-container-wrapper {
        position: relative;
        width: 100%;
        max-height: 400px;
        background: #1e293b;
        border-radius: 1rem;
        overflow: hidden;
    }

    .cropper-container-wrapper img {
        display: block;
        max-width: 100%;
        max-height: 400px;
    }

    /* Final Preview */
    .banner-final-preview {
        position: relative;
        border-radius: 1rem;
        overflow: hidden;
        aspect-ratio: 3 / 1;
        background: #f1f5f9;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .banner-final-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .banner-final-preview .remove-btn {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        background: rgba(0, 0, 0, 0.7);
        color: white;
        border: none;
        border-radius: 50%;
        width: 2.5rem;
        height: 2.5rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }

    .banner-final-preview .remove-btn:hover {
        background: #ef4444;
        transform: scale(1.1);
    }

    /* Cropper Controls */
    .cropper-controls {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
        flex-wrap: wrap;
    }

    .cropper-controls button {
        padding: 0.625rem 1.25rem;
        border-radius: 0.75rem;
        font-weight: 600;
        font-size: 0.875rem;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
    }

    .btn-crop-confirm {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
    }

    .btn-crop-confirm:hover {
        background: linear-gradient(135deg, #059669, #047857);
        transform: translateY(-1px);
    }

    .btn-crop-cancel {
        background: #f1f5f9;
        color: #475569;
    }

    .btn-crop-cancel:hover {
        background: #e2e8f0;
    }

    /* Info badge */
    .crop-info {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: linear-gradient(135deg, #dbeafe, #eff6ff);
        color: #1e40af;
        padding: 0.5rem 1rem;
        border-radius: 0.75rem;
        font-size: 0.75rem;
        font-weight: 500;
        margin-bottom: 0.75rem;
    }
</style>

<div class="dpv-container dpv-space-y-6">
    <!-- Page Header -->
    <div class="dpv-page-header">
        <div class="dpv-page-header__layout">
            <div>
                <p class="dpv-page-header__eyebrow"><?= $id ? '✏️ Edit Event' : '➕ New Event' ?></p>
                <h1 class="dpv-page-header__title"><?= $id ? h($event['name'] ?? 'Edit Event') : 'Create Event' ?></h1>
                <p class="dpv-page-header__subtitle">Each event generates a public registration link and a
                    password-protected Event Admin console.</p>
            </div>
            <div class="dpv-page-header__actions">
                <a href="<?= BASE_URL ?>/admin/events.php" class="dpv-page-header__btn">
                    ← Back to Events
                </a>
            </div>
        </div>
    </div>

    <div class="dpv-section-card dpv-space-y-4">
        <?php if ($errors): ?>
            <div class="dpv-alert dpv-alert--error">
                <span>⚠️</span>
                <span><?= implode('<br>', $errors) ?></span>
            </div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" id="eventForm" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="banner_cropped_data" id="bannerCroppedData" value="">

            <!-- Event Banner Upload with Cropper -->
            <div class="md:col-span-2">
                <label class="text-sm font-semibold text-slate-700 mb-2 block">Event Banner</label>

                <!-- Step 1: Upload Zone -->
                <div id="bannerUploadZone"
                    class="banner-upload-zone <?= !empty($event['banner_image']) ? 'hidden' : '' ?>">
                    <input type="file" name="banner" id="bannerInput" accept="image/jpeg,image/png,image/webp"
                        class="hidden">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-16 h-16 bg-emerald-50 rounded-2xl flex items-center justify-center">
                            <svg class="w-8 h-8 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span class="text-base font-semibold text-slate-700">Click or drag image here</span>
                        <span class="text-sm text-slate-500">JPG, PNG or WEBP • Max 5MB</span>
                        <span class="text-xs text-slate-400">You'll be able to crop and position your image</span>
                    </div>
                </div>

                <!-- Step 2: Cropper -->
                <div id="bannerCropperSection" class="hidden">
                    <div class="crop-info">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Drag to position • Scroll to zoom • Fixed 3:1 aspect ratio
                    </div>
                    <div class="cropper-container-wrapper">
                        <img id="cropperImage" src="" alt="Crop preview">
                    </div>
                    <div class="cropper-controls">
                        <button type="button" class="btn-crop-confirm" onclick="confirmCrop()">
                            <span class="flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 13l4 4L19 7" />
                                </svg>
                                Apply Crop
                            </span>
                        </button>
                        <button type="button" class="btn-crop-cancel" onclick="cancelCrop()">Cancel</button>
                    </div>
                </div>

                <!-- Step 3: Final Preview -->
                <div id="bannerFinalPreview"
                    class="banner-final-preview <?= empty($event['banner_image']) ? 'hidden' : '' ?>">
                    <?php if (!empty($event['banner_image'])): ?>
                        <img id="bannerFinalImg"
                            src="<?= BASE_URL ?>/image.php?type=event&file=<?= urlencode(basename($event['banner_image'])) ?>"
                            alt="Event Banner">
                    <?php else: ?>
                        <img id="bannerFinalImg" src="" alt="Event Banner">
                    <?php endif; ?>
                    <button type="button" class="remove-btn" onclick="removeBanner()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="md:col-span-2">
                <label class="text-sm font-semibold text-slate-700">Name</label>
                <input name="name" required value="<?= h($event['name'] ?? '') ?>" class="input-field mt-1">
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-semibold text-slate-700">Description</label>
                <textarea name="description" rows="3"
                    class="input-field mt-1"><?= h($event['description'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">Start</label>
                <input type="datetime-local" name="start_datetime" required
                    value="<?= isset($event['start_datetime']) ? date('Y-m-d\TH:i', strtotime($event['start_datetime'])) : '' ?>"
                    class="input-field mt-1">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">End (optional)</label>
                <input type="datetime-local" name="end_datetime"
                    value="<?= isset($event['end_datetime']) ? date('Y-m-d\TH:i', strtotime($event['end_datetime'])) : '' ?>"
                    class="input-field mt-1">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">Location</label>
                <input name="location" required value="<?= h($event['location'] ?? '') ?>" class="input-field mt-1">
            </div>
            <div>
                <label class="text-sm font-semibold text-slate-700">Capacity (optional)</label>
                <input type="number" name="capacity" min="0" step="1" value="<?= h($event['capacity'] ?? '') ?>"
                    class="input-field mt-1" placeholder="Leave blank for unlimited">
                <p class="text-xs text-slate-500 mt-1">Numbers only. Leave blank to allow unlimited registrations.</p>
            </div>
            <div class="md:col-span-2 flex items-center gap-3">
                <button class="btn-primary" type="submit"><?= $id ? 'Update event' : 'Create event' ?></button>
                <a href="<?= BASE_URL ?>/admin/events.php" class="text-slate-600">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const zone = document.getElementById('bannerUploadZone');
        const input = document.getElementById('bannerInput');
        const cropperSection = document.getElementById('bannerCropperSection');
        const cropperImage = document.getElementById('cropperImage');
        const finalPreview = document.getElementById('bannerFinalPreview');
        const finalImg = document.getElementById('bannerFinalImg');
        const croppedDataInput = document.getElementById('bannerCroppedData');

        let cropper = null;

        // Click to upload
        zone.addEventListener('click', () => input.click());

        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            zone.addEventListener(eventName, e => {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            zone.addEventListener(eventName, () => zone.classList.add('dragover'));
        });

        ['dragleave', 'drop'].forEach(eventName => {
            zone.addEventListener(eventName, () => zone.classList.remove('dragover'));
        });

        zone.addEventListener('drop', e => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                input.files = files;
                handleFile(files[0]);
            }
        });

        // File input change
        input.addEventListener('change', () => {
            if (input.files.length > 0) {
                handleFile(input.files[0]);
            }
        });

        function handleFile(file) {
            if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
                alert('Only JPG, PNG, or WEBP images allowed');
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                alert('Image must be smaller than 5MB');
                return;
            }

            const reader = new FileReader();
            reader.onload = e => {
                // Show cropper
                cropperImage.src = e.target.result;
                zone.classList.add('hidden');
                cropperSection.classList.remove('hidden');
                finalPreview.classList.add('hidden');

                // Initialize Cropper.js
                if (cropper) {
                    cropper.destroy();
                }
                cropper = new Cropper(cropperImage, {
                    aspectRatio: 3 / 1,
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 1,
                    cropBoxResizable: false,
                    cropBoxMovable: false,
                    guides: true,
                    center: true,
                    highlight: true,
                    background: true,
                    responsive: false,  // Disable responsive to prevent reset on scroll
                    restore: false,
                    modal: false,       // Disable modal overlay
                    zoomOnWheel: false, // Disable zoom on mouse wheel
                    zoomOnTouch: false, // Disable zoom on touch (pinch)
                    checkCrossOrigin: false,
                    checkOrientation: false,
                    minContainerWidth: 300,
                    minContainerHeight: 100
                });
            };
            reader.readAsDataURL(file);
        }

        // Confirm crop
        window.confirmCrop = function () {
            if (!cropper) return;

            // Get cropped canvas at target size (1200x400)
            const canvas = cropper.getCroppedCanvas({
                width: 1200,
                height: 400,
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high'
            });

            // Convert to base64 (JPEG with good quality for smaller size)
            const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.85);

            // Set hidden input value
            croppedDataInput.value = croppedDataUrl;

            // Show final preview
            finalImg.src = croppedDataUrl;
            cropperSection.classList.add('hidden');
            finalPreview.classList.remove('hidden');

            // Clean up cropper
            cropper.destroy();
            cropper = null;
        };

        // Cancel crop
        window.cancelCrop = function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            cropperSection.classList.add('hidden');
            zone.classList.remove('hidden');
            input.value = '';
        };

        // Remove banner
        window.removeBanner = function () {
            if (cropper) {
                cropper.destroy();
                cropper = null;
            }
            input.value = '';
            croppedDataInput.value = '';
            finalImg.src = '';
            cropperSection.classList.add('hidden');
            finalPreview.classList.add('hidden');
            zone.classList.remove('hidden');
        };
    })();
</script>

<?php render_footer(); ?>