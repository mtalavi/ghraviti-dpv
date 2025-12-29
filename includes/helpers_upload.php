<?php
/**
 * File Upload Helper Functions
 * 
 * Contains: Avatar upload, Emirates ID upload, Event banner upload.
 * All uploads include multi-layer security validation.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// AVATAR UPLOAD
// =====================================================

/**
 * Handle avatar image upload with security validation.
 * Resizes to max 500x500px, converts to JPEG.
 * 
 * @param array $file $_FILES array element
 * @return string|null Relative path to uploaded file or null if no file
 * @throws Exception On validation/upload failure
 */
function handle_avatar_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload failed. Please retry.');
    }

    // SECURITY: Multi-layer validation
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    // Layer 1: MIME detection using finfo (most reliable method)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime === false || !isset($allowed[$mime])) {
        throw new Exception('Only JPG and PNG allowed');
    }

    // Layer 2: Validate actual image content with getimagesize()
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('Invalid image content');
    }

    // Layer 3: Check file size
    if ($file['size'] > AVATAR_MAX_KB * 1024) {
        throw new Exception('Photo exceeds ' . AVATAR_MAX_KB . 'KB');
    }

    ensure_dir(AVATAR_DIR);
    // Always use fixed extension (.jpg) regardless of source
    $dest = AVATAR_DIR . uniqid('av_', true) . '.jpg';

    $ext = $allowed[$mime];
    $src = ($ext === 'jpg') ? imagecreatefromjpeg($file['tmp_name']) : imagecreatefrompng($file['tmp_name']);
    if (!$src) {
        throw new Exception('Invalid image');
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $max = 500;
    $ratio = min($max / $w, $max / $h, 1);
    $nw = (int) ($w * $ratio);
    $nh = (int) ($h * $ratio);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($dst, $dest, 78);
    imagedestroy($src);
    imagedestroy($dst);

    return 'assets/uploads/avatars/' . basename($dest);
}

// =====================================================
// EMIRATES ID UPLOAD
// =====================================================

/**
 * Handle Emirates ID image upload with security validation.
 * Resizes to max 1400px width, keeps readable quality.
 * 
 * @param array $file $_FILES array element
 * @return string|null Relative path to uploaded file or null if no file
 * @throws Exception On validation/upload failure
 */
function handle_emirates_id_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Emirates ID upload failed. Please retry.');
    }

    // SECURITY: Multi-layer validation
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    // Layer 1: MIME detection using finfo (most reliable method)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime === false || !isset($allowed[$mime])) {
        throw new Exception('Only JPG and PNG allowed for Emirates ID');
    }

    // Layer 2: Validate actual image content with getimagesize()
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('Invalid Emirates ID image content');
    }

    // Layer 3: Check file size
    if ($file['size'] > ID_DOC_MAX_KB * 1024) {
        throw new Exception('Emirates ID image exceeds ' . ID_DOC_MAX_KB . 'KB');
    }

    ensure_dir(ID_DOC_DIR);
    // Always use fixed extension (.jpg) regardless of source
    $dest = ID_DOC_DIR . uniqid('eid_', true) . '.jpg';

    $ext = $allowed[$mime];
    $src = ($ext === 'jpg') ? imagecreatefromjpeg($file['tmp_name']) : imagecreatefrompng($file['tmp_name']);
    if (!$src) {
        throw new Exception('Invalid Emirates ID image');
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $max = 1400;
    $ratio = min($max / $w, $max / $h, 1);
    $nw = (int) ($w * $ratio);
    $nh = (int) ($h * $ratio);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagejpeg($dst, $dest, 80);
    imagedestroy($src);
    imagedestroy($dst);

    return 'assets/uploads/ids/' . basename($dest);
}

// =====================================================
// EVENT BANNER UPLOAD
// =====================================================

/**
 * Handle event banner upload with auto-crop/resize to 3:1 ratio (1200x400).
 * 
 * @param array $file $_FILES array element
 * @return string|null Relative path to uploaded file or null if no file
 * @throws Exception On validation/upload failure
 */
function handle_event_banner_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Banner upload failed. Please retry.');
    }

    // SECURITY: Multi-layer validation (consistent with avatar/emirates_id uploads)
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    // Layer 1: MIME detection using finfo (most reliable method)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if ($mime === false || !isset($allowed[$mime])) {
        throw new Exception('Only JPG, PNG and WEBP allowed for banner');
    }

    // Layer 2: Validate actual image content with getimagesize()
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new Exception('Invalid banner image content');
    }

    // Layer 3: Check file size
    if ($file['size'] > EVENT_BANNER_MAX_KB * 1024) {
        throw new Exception('Banner image exceeds ' . EVENT_BANNER_MAX_KB . 'KB');
    }

    ensure_dir(EVENT_BANNER_DIR);
    $ext = $allowed[$mime];
    $dest = EVENT_BANNER_DIR . uniqid('ev_') . '.jpg';

    // Load source image
    if ($ext === 'jpg') {
        $src = imagecreatefromjpeg($file['tmp_name']);
    } elseif ($ext === 'png') {
        $src = imagecreatefrompng($file['tmp_name']);
    } else {
        $src = imagecreatefromwebp($file['tmp_name']);
    }
    if (!$src) {
        throw new Exception('Invalid banner image');
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Target dimensions (3:1 ratio)
    $targetW = 1200;
    $targetH = 400;
    $targetRatio = $targetW / $targetH; // 3.0

    // Calculate crop area (center crop to 3:1 ratio)
    $srcRatio = $srcW / $srcH;
    if ($srcRatio > $targetRatio) {
        // Source is wider - crop horizontally
        $cropH = $srcH;
        $cropW = (int) ($srcH * $targetRatio);
        $cropX = (int) (($srcW - $cropW) / 2);
        $cropY = 0;
    } else {
        // Source is taller - crop vertically
        $cropW = $srcW;
        $cropH = (int) ($srcW / $targetRatio);
        $cropX = 0;
        $cropY = (int) (($srcH - $cropH) / 2);
    }

    // Create destination image
    $dst = imagecreatetruecolor($targetW, $targetH);

    // Resample from cropped area
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetW, $targetH, $cropW, $cropH);

    // Save as JPEG with good quality
    imagejpeg($dst, $dest, 85);
    imagedestroy($src);
    imagedestroy($dst);

    return 'assets/uploads/events/' . basename($dest);
}
