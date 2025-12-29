<?php
/**
 * Encryption module for sensitive user data (AES-256-GCM)
 * 
 * Security: Keys are loaded from environment variables or .keys file (outside web root preferred)
 * If database is leaked, encrypted data is useless without the keys.
 * 
 * Encrypted fields: full_name, full_name_ar, email, mobile, emirates_id
 */

// =====================================================
// KEY MANAGEMENT - Multiple secure options
// =====================================================

/**
 * Get encryption key from secure source (in priority order):
 * 1. Environment variable (best for production)
 * 2. .keys file in parent directory (outside web root)
 * 3. .keys file in includes (fallback, must be protected by .htaccess)
 */
function get_encryption_key(): string
{
    static $key = null;
    if ($key !== null)
        return $key;

    // Option 1: Environment variable (most secure)
    $envKey = getenv('DPV_ENCRYPTION_KEY');
    if ($envKey && strlen($envKey) >= 32) {
        $key = substr($envKey, 0, 32);
        return $key;
    }

    // Option 2: .dpv_keys file in home directory (most secure - completely outside web root)
    // Check 3 levels up first (for /home/dpvhub/.dpv_keys when project is in public_html/subfolder)
    $homeKeyFile = dirname(__DIR__, 3) . '/.dpv_keys';
    if (file_exists($homeKeyFile)) {
        $keys = parse_ini_file($homeKeyFile);
        if (!empty($keys['ENCRYPTION_KEY']) && strlen($keys['ENCRYPTION_KEY']) >= 32) {
            $key = substr($keys['ENCRYPTION_KEY'], 0, 32);
            return $key;
        }
    }

    // Option 2b: .dpv_keys file 2 levels up (for project directly in public_html)
    $parentKeyFile = dirname(__DIR__, 2) . '/.dpv_keys';
    if (file_exists($parentKeyFile)) {
        $keys = parse_ini_file($parentKeyFile);
        if (!empty($keys['ENCRYPTION_KEY']) && strlen($keys['ENCRYPTION_KEY']) >= 32) {
            $key = substr($keys['ENCRYPTION_KEY'], 0, 32);
            return $key;
        }
    }

    // Option 3: .keys file in includes (protected by .htaccess)
    $localKeyFile = __DIR__ . '/.keys';
    if (file_exists($localKeyFile)) {
        $keys = @parse_ini_file($localKeyFile);
        if ($keys !== false && !empty($keys['ENCRYPTION_KEY']) && strlen($keys['ENCRYPTION_KEY']) >= 32) {
            $key = substr($keys['ENCRYPTION_KEY'], 0, 32);
            return $key;
        }
    }

    // No key found - this is a critical error
    throw new RuntimeException(
        'Encryption key not configured. ' .
        'Set DPV_ENCRYPTION_KEY environment variable or create includes/.keys file with ENCRYPTION_KEY.'
    );
}

function get_blind_index_key(): string
{
    static $key = null;
    if ($key !== null)
        return $key;

    // Same priority as encryption key
    $envKey = getenv('DPV_BLIND_INDEX_KEY');
    if ($envKey && strlen($envKey) >= 32) {
        $key = substr($envKey, 0, 32);
        return $key;
    }

    // Check home directory first (3 levels up)
    $homeKeyFile = dirname(__DIR__, 3) . '/.dpv_keys';
    if (file_exists($homeKeyFile)) {
        $keys = parse_ini_file($homeKeyFile);
        if (!empty($keys['BLIND_INDEX_KEY']) && strlen($keys['BLIND_INDEX_KEY']) >= 32) {
            $key = substr($keys['BLIND_INDEX_KEY'], 0, 32);
            return $key;
        }
    }

    // Check 2 levels up
    $parentKeyFile = dirname(__DIR__, 2) . '/.dpv_keys';
    if (file_exists($parentKeyFile)) {
        $keys = parse_ini_file($parentKeyFile);
        if (!empty($keys['BLIND_INDEX_KEY']) && strlen($keys['BLIND_INDEX_KEY']) >= 32) {
            $key = substr($keys['BLIND_INDEX_KEY'], 0, 32);
            return $key;
        }
    }

    $localKeyFile = __DIR__ . '/.keys';
    if (file_exists($localKeyFile)) {
        $keys = @parse_ini_file($localKeyFile);
        if ($keys !== false && !empty($keys['BLIND_INDEX_KEY']) && strlen($keys['BLIND_INDEX_KEY']) >= 32) {
            $key = substr($keys['BLIND_INDEX_KEY'], 0, 32);
            return $key;
        }
    }

    // No key found - this is a critical error
    throw new RuntimeException(
        'Blind index key not configured. ' .
        'Set DPV_BLIND_INDEX_KEY environment variable or create includes/.keys file with BLIND_INDEX_KEY.'
    );
}

// =====================================================
// ENCRYPTION FUNCTIONS
// =====================================================

/**
 * Encrypt a string using AES-256-GCM
 * Returns base64 encoded: nonce + ciphertext + tag
 */
function encrypt_field(?string $plaintext): ?string
{
    if ($plaintext === null || $plaintext === '')
        return $plaintext;

    $key = get_encryption_key();
    $nonce = random_bytes(12); // 96-bit nonce for GCM

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '',
        16 // 128-bit tag
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Encryption failed');
    }

    // Format: base64(nonce + ciphertext + tag)
    return base64_encode($nonce . $ciphertext . $tag);
}

/**
 * Decrypt a string encrypted with encrypt_field
 */
function decrypt_field(?string $ciphertext): ?string
{
    if ($ciphertext === null || $ciphertext === '')
        return $ciphertext;

    // Check if already decrypted (plain text)
    $decoded = base64_decode($ciphertext, true);
    if ($decoded === false || strlen($decoded) < 28) { // min: 12 nonce + 0 data + 16 tag
        return $ciphertext; // Return as-is if not encrypted format
    }

    $key = get_encryption_key();
    $nonce = substr($decoded, 0, 12);
    $tag = substr($decoded, -16);
    $encrypted = substr($decoded, 12, -16);

    $plaintext = openssl_decrypt(
        $encrypted,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($plaintext === false) {
        // May not be encrypted data (legacy), return as-is
        return $ciphertext;
    }

    return $plaintext;
}

/**
 * Create a blind index for searchable encrypted fields
 * Uses HMAC-SHA256 with Unicode normalization for consistent matching
 * 
 * SECURITY: NFKC normalization ensures similar characters (e.g., Arabic ي vs Persian ی)
 * produce the same hash, preventing duplicate records with visually identical data.
 */
function blind_index(?string $value): ?string
{
    if ($value === null || $value === '')
        return null;

    $key = get_blind_index_key();

    // Normalize: Unicode NFKC + lowercase + trim
    // NFKC handles: Arabic/Persian character variants, full-width chars, ligatures
    $normalized = trim($value);
    if (function_exists('normalizer_normalize')) {
        $normalized = normalizer_normalize($normalized, Normalizer::FORM_KC) ?: $normalized;
    }
    $normalized = mb_strtolower($normalized, 'UTF-8');

    return substr(hash_hmac('sha256', $normalized, $key), 0, 64);
}

// =====================================================
// USER DATA HELPERS
// =====================================================

// Fields that need encryption
define('ENCRYPTED_USER_FIELDS', ['full_name', 'full_name_ar', 'email', 'mobile', 'emirates_id']);

// Fields that need blind index for searching
define('BLIND_INDEX_FIELDS', ['email', 'mobile', 'emirates_id']);

/**
 * Encrypt user fields before INSERT/UPDATE
 * Also generates blind indexes for searchable fields
 */
function encrypt_user_data(array $data): array
{
    foreach (ENCRYPTED_USER_FIELDS as $field) {
        if (isset($data[$field]) && $data[$field] !== null && $data[$field] !== '') {
            $plainValue = $data[$field];
            $data[$field] = encrypt_field($plainValue);

            // Generate blind index if applicable
            if (in_array($field, BLIND_INDEX_FIELDS)) {
                $data[$field . '_hash'] = blind_index($plainValue);
            }
        }
    }
    return $data;
}

/**
 * Decrypt user fields after SELECT
 */
function decrypt_user_data(?array $user): ?array
{
    if ($user === null)
        return null;

    foreach (ENCRYPTED_USER_FIELDS as $field) {
        if (isset($user[$field])) {
            $user[$field] = decrypt_field($user[$field]);
        }
    }
    return $user;
}

/**
 * Decrypt array of users
 */
function decrypt_users_array(array $users): array
{
    return array_map('decrypt_user_data', $users);
}

// =====================================================
// KEY GENERATION
// =====================================================
// 
// SECURITY: Key generation has been moved to a CLI-only script.
// To generate new keys, run from command line:
//   php cli/generate_keys.php
// 
// This prevents accidental key regeneration via web requests.
