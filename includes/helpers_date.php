<?php
/**
 * Date & Time Helper Functions
 * 
 * Contains: Dubai timezone formatting, relative time display.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// DATE/TIME FORMATTING (Dubai Timezone)
// =====================================================

/**
 * Format date/time in Dubai timezone with readable format.
 * 
 * @param string|null $datetime DateTime string (from database or any format)
 * @param string $format Output format: 'full', 'date', 'time', 'short', 'relative', 'datetime-local', 'db'
 * @return string Formatted date/time string
 */
function format_dubai_datetime(?string $datetime, string $format = 'full'): string
{
    if (empty($datetime)) {
        return '-';
    }

    try {
        // Timestamps from database are already in Dubai timezone (+04:00)
        // since we set MySQL session timezone to +04:00 in db.php
        $dt = new DateTime($datetime, new DateTimeZone('Asia/Dubai'));

        switch ($format) {
            case 'full':
                // Example: "17 December 2025, 03:10 AM"
                return $dt->format('d F Y, h:i A');
            case 'date':
                // Example: "17 December 2025"
                return $dt->format('d F Y');
            case 'time':
                // Example: "03:10 AM"
                return $dt->format('h:i A');
            case 'short':
                // Example: "17 Dec 2025"
                return $dt->format('d M Y');
            case 'datetime-local':
                // For HTML datetime-local inputs
                return $dt->format('Y-m-d\TH:i');
            case 'db':
                // For database storage
                return $dt->format('Y-m-d H:i:s');
            case 'relative':
                // Example: "2 hours ago", "5 minutes ago"
                return format_time_ago($datetime);
            default:
                return $dt->format($format);
        }
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Get current Dubai date/time.
 * 
 * @param string $format Date format (default: 'd F Y, h:i A')
 * @return string Formatted current time in Dubai
 */
function dubai_now(string $format = 'd F Y, h:i A'): string
{
    $dt = new DateTime('now', new DateTimeZone('Asia/Dubai'));
    return $dt->format($format);
}

/**
 * Get current Dubai date only in readable format.
 * 
 * @return string Example: "Tuesday, 17 December 2025"
 */
function dubai_date_readable(): string
{
    $dt = new DateTime('now', new DateTimeZone('Asia/Dubai'));
    return $dt->format('l, d F Y');
}

/**
 * Format time as relative (time ago).
 * 
 * @param string $datetime DateTime string
 * @return string Human-readable relative time (e.g., "2 hours ago")
 */
function format_time_ago(string $datetime): string
{
    try {
        $dt = new DateTime($datetime);
        $now = new DateTime();
        $interval = $now->diff($dt);

        if ($interval->y > 0) {
            return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
        }
        if ($interval->m > 0) {
            return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
        }
        if ($interval->d > 0) {
            return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        }
        if ($interval->h > 0) {
            return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        }
        if ($interval->i > 0) {
            return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        }
        return 'just now';
    } catch (Exception $e) {
        return $datetime;
    }
}
