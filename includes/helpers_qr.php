<?php
/**
 * QR Code Helper Functions
 * 
 * Contains: QR code generation, path helpers.
 * Uses phpqrcode library.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// QR CODE GENERATION
// =====================================================

/**
 * Ensure phpqrcode cache directories exist.
 * Prevents runtime warnings on shared hosting.
 * 
 * @return string Cache directory path
 */
function ensure_qr_cache_dirs(): string
{
    $cacheDir = dirname(__DIR__) . '/lib/cache/';
    ensure_dir($cacheDir);
    for ($i = 0; $i <= 7; $i++) {
        ensure_dir($cacheDir . 'mask_' . $i . '/');
    }
    return $cacheDir;
}

/**
 * Generate QR code PNG file.
 * 
 * @param string $text Text to encode in QR
 * @param string $path File path to save PNG
 */
function generate_qr_png(string $text, string $path): void
{
    ensure_dir(dirname($path));
    ensure_qr_cache_dirs();
    QRcode::png($text, $path, QR_ECLEVEL_L, 6, 2);
}

/**
 * Get filesystem path for a DP code's QR image.
 * 
 * @param string $dpCode DP code
 * @return string Full file path
 */
function qr_path_for_code(string $dpCode): string
{
    return QR_DIR . 'qr_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $dpCode) . '.png';
}

/**
 * Get URL for a DP code's QR image.
 * 
 * @param string $dpCode DP code
 * @return string URL to QR image
 */
function qr_url_for_code(string $dpCode): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $dpCode);
    return BASE_URL . '/assets/uploads/qr/qr_' . rawurlencode($safe) . '.png';
}

// =====================================================
// DP CODE POOL MANAGEMENT
// =====================================================

/**
 * Seed the DP code pool if empty.
 * Creates DP1000 through DP9999.
 */
function seed_dp_pool_if_needed(): void
{
    $count = fetch_one('SELECT COUNT(*) AS c FROM dp_pool');
    if (($count['c'] ?? 0) == 0) {
        $pdo = db();
        $ins = $pdo->prepare('INSERT INTO dp_pool (dp_code, is_used) VALUES (?, 0)');
        for ($n = 1000; $n <= 9999; $n++) {
            $ins->execute(['DP' . str_pad((string) $n, 4, '0', STR_PAD_LEFT)]);
        }
    }
    // Mark existing users to keep pool accurate
    $existing = fetch_all('SELECT dp_code FROM users WHERE dp_code LIKE "DP%"');
    if ($existing) {
        $upd = db()->prepare('UPDATE dp_pool SET is_used=1 WHERE dp_code=?');
        foreach ($existing as $row) {
            $upd->execute([$row['dp_code']]);
        }
    }
}

/**
 * Get next available DP code from pool or validate manual code.
 * 
 * @param string|null $manual Optional manual DP code
 * @return string Allocated DP code
 * @throws Exception On validation failure or pool exhaustion
 */
function next_dp_code(?string $manual = null): string
{
    $manual = $manual !== null ? trim($manual) : null;
    if ($manual !== null && $manual !== '') {
        $manual = strtoupper($manual);
        if (strlen($manual) < 3 || strlen($manual) > 20) {
            throw new Exception('DP Code must be 3-20 characters.');
        }
        if (fetch_one('SELECT id FROM users WHERE dp_code=?', [$manual])) {
            throw new Exception('DP Code already exists.');
        }
        if (stripos($manual, 'DP') === 0) {
            execute_query('UPDATE dp_pool SET is_used=1 WHERE dp_code=?', [$manual]);
        }
        return $manual;
    }

    $pdo = db();
    $pdo->beginTransaction();
    seed_dp_pool_if_needed();
    $row = fetch_one('SELECT dp_code FROM dp_pool WHERE is_used=0 ORDER BY dp_code ASC LIMIT 1 FOR UPDATE');
    if (!$row) {
        $pdo->rollBack();
        throw new Exception('DP code pool exhausted');
    }
    execute_query('UPDATE dp_pool SET is_used=1 WHERE dp_code=?', [$row['dp_code']]);
    $pdo->commit();
    return $row['dp_code'];
}
