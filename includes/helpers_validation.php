<?php
/**
 * Validation Helper Functions
 * 
 * Contains: Email, mobile, Emirates ID validation.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// INPUT VALIDATION
// =====================================================

/**
 * Validate email address format.
 * 
 * @param string $email Email address to validate
 * @return bool True if valid email format
 */
function validate_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate UAE mobile number format.
 * Format: 05XXXXXXXX (10 digits starting with 05)
 * 
 * @param string $mobile Mobile number to validate
 * @return bool True if valid UAE mobile format
 */
function validate_mobile_uae(string $mobile): bool
{
    return (bool) preg_match('/^05\\d{8}$/', $mobile);
}

/**
 * Validate Emirates ID format.
 * Format: 784-XXXX-XXXXXXX-X
 * 
 * @param string $eid Emirates ID to validate
 * @return bool True if valid Emirates ID format
 */
function validate_emirates_id(string $eid): bool
{
    return (bool) preg_match('/^784-[0-9]{4}-[0-9]{7}-[0-9]$/', $eid);
}
