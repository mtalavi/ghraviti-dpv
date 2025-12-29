<?php
/**
 * Consent Management Helper Functions
 * 
 * Contains: Consent version management, user consent checks,
 * consent signature validation.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// HTML SANITIZATION
// =====================================================

/**
 * Sanitize HTML content for consent documents.
 * Whitelist approach: only allow safe tags, strip everything else.
 * 
 * @param string $html Raw HTML input
 * @return string Sanitized HTML
 */
function sanitize_consent_html(string $html): string
{
    // First, decode HTML entities that might be used to bypass filters
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Remove all script-related content (including event handlers)
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
    $html = preg_replace('/\bon\w+\s*=\s*[^\s>]+/i', '', $html);

    // Remove dangerous tags entirely
    $dangerousTags = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'style', 'link', 'meta', 'base'];
    foreach ($dangerousTags as $tag) {
        $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html);
        $html = preg_replace('/<' . $tag . '\b[^>]*\/?>/i', '', $html);
    }

    // Remove javascript: and data: URLs
    $html = preg_replace('/\b(href|src)\s*=\s*["\']?\s*(javascript|data|vbscript):[^"\'>\\s]*/i', '', $html);

    // Use strip_tags with allowed tags as a final filter
    $allowedTags = '<b><strong><p><ul><ol><li><br><h1><h2><h3><h4><h5><h6><em><u><i><a><span><div><table><tr><td><th><thead><tbody>';
    $html = strip_tags($html, $allowedTags);

    // Clean up attributes on remaining tags (only allow safe attributes)
    $html = preg_replace_callback('/<(\w+)([^>]*)>/i', function ($matches) {
        $tag = $matches[1];
        $attrs = $matches[2];
        $safeAttrs = [];

        // Allow class attribute
        if (preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/', $attrs, $m)) {
            $safeAttrs[] = 'class="' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '"';
        }

        // Allow dir attribute (for RTL)
        if (preg_match('/\bdir\s*=\s*["\']?(rtl|ltr)["\']?/i', $attrs, $m)) {
            $safeAttrs[] = 'dir="' . strtolower($m[1]) . '"';
        }

        // Allow href for anchor tags (but not javascript:)
        if (strtolower($tag) === 'a' && preg_match('/\bhref\s*=\s*["\']([^"\']*)["\']/', $attrs, $m)) {
            $href = $m[1];
            if (!preg_match('/^\s*(javascript|data|vbscript):/i', $href)) {
                $safeAttrs[] = 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener"';
            }
        }

        $attrString = $safeAttrs ? ' ' . implode(' ', $safeAttrs) : '';
        return '<' . $tag . $attrString . '>';
    }, $html);

    return trim($html);
}

// =====================================================
// CONSENT VERSION MANAGEMENT
// =====================================================

/**
 * Get the latest (current) consent version.
 * @return array|null Version data or null if none exists
 */
function get_latest_consent_version(): ?array
{
    try {
        $row = fetch_one('SELECT * FROM consent_versions ORDER BY id DESC LIMIT 1');
        return $row ?: null;
    } catch (Exception $e) {
        error_log('Consent table query failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get a specific consent version by ID.
 * @param int $id Version ID
 * @return array|null Version data or null
 */
function get_consent_version(int $id): ?array
{
    return fetch_one('SELECT * FROM consent_versions WHERE id = ?', [$id]);
}

/**
 * Get all consent versions for history view.
 * @param int $limit Maximum number to return
 * @return array List of versions with publisher name
 */
function get_consent_versions_history(int $limit = 50): array
{
    return fetch_all('SELECT cv.*, u.full_name as published_by_name FROM consent_versions cv LEFT JOIN users u ON cv.published_by = u.id ORDER BY cv.id DESC LIMIT ?', [$limit]);
}

/**
 * Publish a new consent version.
 * 
 * @param string $contentEn English content
 * @param string $contentAr Arabic content
 * @param string $contentUr Urdu content
 * @param int $publishedBy Publisher user ID
 * @return int New version ID
 * @throws Exception If any language content is empty
 */
function publish_consent_version(string $contentEn, string $contentAr, string $contentUr, int $publishedBy): int
{
    $contentEn = sanitize_consent_html($contentEn);
    $contentAr = sanitize_consent_html($contentAr);
    $contentUr = sanitize_consent_html($contentUr);

    if (empty(trim(strip_tags($contentEn))) || empty(trim(strip_tags($contentAr))) || empty(trim(strip_tags($contentUr)))) {
        throw new Exception('All three language versions (English, Arabic, Urdu) must have content.');
    }

    $stmt = execute_query(
        'INSERT INTO consent_versions (content_en, content_ar, content_ur, published_by) VALUES (?, ?, ?, ?)',
        [$contentEn, $contentAr, $contentUr, $publishedBy]
    );

    return (int) db()->lastInsertId();
}

// =====================================================
// USER CONSENT CHECKS
// =====================================================

/**
 * Check if user needs to sign the latest consent.
 * 
 * @param array|null $user User data
 * @return bool True if consent required
 */
function user_needs_consent(?array $user): bool
{
    if (!$user) {
        return false;
    }

    $latestVersion = get_latest_consent_version();
    if (!$latestVersion) {
        return false;
    }

    $userVersionId = $user['last_consent_version_id'] ?? null;
    return $userVersionId === null || (int) $userVersionId !== (int) $latestVersion['id'];
}

/**
 * Log a consent signature to the audit trail.
 */
function log_consent_signature(int $userId, int $versionId, string $ipAddress, string $language, string $inputSnapshot): void
{
    $validLanguages = ['en', 'ar', 'ur'];
    if (!in_array($language, $validLanguages, true)) {
        $language = 'en';
    }

    execute_query(
        'INSERT INTO consent_logs (user_id, consent_version_id, ip_address, signed_language, input_snapshot) VALUES (?, ?, ?, ?, ?)',
        [$userId, $versionId, $ipAddress, $language, substr($inputSnapshot, 0, 255)]
    );
}

/**
 * Update user's last signed consent version.
 */
function update_user_consent_version(int $userId, int $versionId): void
{
    execute_query('UPDATE users SET last_consent_version_id = ? WHERE id = ?', [$versionId, $userId]);

    if (isset($_SESSION['uid']) && (int) $_SESSION['uid'] === $userId) {
        $_SESSION['consent_version_updated'] = time();
    }
}

/**
 * Validate user's typed name against their stored full name.
 */
function validate_consent_signature(string $inputName, string $storedName): bool
{
    $inputName = trim($inputName);
    $storedName = trim($storedName);

    if (empty($inputName) || empty($storedName)) {
        return false;
    }

    return strtolower($inputName) === strtolower($storedName);
}

/**
 * Get consent log history for a specific user.
 */
function get_user_consent_history(int $userId): array
{
    return fetch_all(
        'SELECT cl.*, cv.id as version_id FROM consent_logs cl JOIN consent_versions cv ON cl.consent_version_id = cv.id WHERE cl.user_id = ? ORDER BY cl.signed_at DESC',
        [$userId]
    );
}
