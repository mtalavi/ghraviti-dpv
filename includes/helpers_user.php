<?php
/**
 * User Management Helpers
 * 
 * Functions for user lifecycle management including file cleanup.
 * 
 * @package DPVHub
 * @since 2.1.0
 */

/**
 * Delete all files associated with a user.
 * Call this BEFORE deleting the user record from the database.
 * 
 * @param array $user User data array with profile_photo, emirates_id_image, dp_code
 * @return array List of deleted file paths
 */
function delete_user_files(array $user): array
{
    $deleted = [];

    // Delete profile photo
    if (!empty($user['profile_photo'])) {
        $avatarPath = AVATAR_DIR . basename($user['profile_photo']);
        if (file_exists($avatarPath) && @unlink($avatarPath)) {
            $deleted[] = $avatarPath;
        }
    }

    // Delete Emirates ID image
    if (!empty($user['emirates_id_image'])) {
        $eidPath = ID_DOC_DIR . basename($user['emirates_id_image']);
        if (file_exists($eidPath) && @unlink($eidPath)) {
            $deleted[] = $eidPath;
        }
    }

    // Delete QR code
    if (!empty($user['dp_code'])) {
        $qrPath = qr_path_for_code($user['dp_code']);
        if ($qrPath && file_exists($qrPath) && @unlink($qrPath)) {
            $deleted[] = $qrPath;
        }
    }

    // Delete generated card image if exists
    if (!empty($user['card_path'])) {
        $cardPath = CARD_DIR . basename($user['card_path']);
        if (file_exists($cardPath) && @unlink($cardPath)) {
            $deleted[] = $cardPath;
        }
    }

    // CASCADE: Delete all event registrations for this user (prevents orphan records)
    if (!empty($user['id'])) {
        delete_user_registrations((int) $user['id']);
    }

    return $deleted;
}

/**
 * Delete all event registrations for a user.
 * Call this BEFORE deleting the user record to prevent orphan records.
 * 
 * @param int $userId User ID
 * @return int Number of deleted registrations
 */
function delete_user_registrations(int $userId): int
{
    $stmt = execute_query(
        'DELETE FROM event_registrations WHERE user_id = ?',
        [$userId]
    );
    $count = $stmt->rowCount();

    if ($count > 0) {
        log_action('cascade_delete_registrations', 'user', $userId, [
            'deleted_count' => $count,
            'reason' => 'user_deletion'
        ]);
    }

    return $count;
}

/**
 * Delete all registrations for an event.
 * Call this BEFORE deleting the event record to prevent orphan records.
 * 
 * @param int $eventId Event ID
 * @return int Number of deleted registrations
 */
function delete_event_registrations(int $eventId): int
{
    $stmt = execute_query(
        'DELETE FROM event_registrations WHERE event_id = ?',
        [$eventId]
    );
    $count = $stmt->rowCount();

    if ($count > 0) {
        log_action('cascade_delete_registrations', 'event', $eventId, [
            'deleted_count' => $count,
            'reason' => 'event_deletion'
        ]);
    }

    return $count;
}

/**
 * Get the admin who created a user.
 * 
 * @param int $createdById The created_by user ID
 * @return string|null Admin name or null if not found
 */
function get_creator_name(int $createdById): ?string
{
    static $cache = [];

    if ($createdById <= 0) {
        return null;
    }

    // Return from cache if already fetched
    if (array_key_exists($createdById, $cache)) {
        return $cache[$createdById];
    }

    $creator = fetch_user_decrypted('SELECT full_name FROM users WHERE id = ?', [$createdById]);
    $cache[$createdById] = $creator ? $creator['full_name'] : null;

    return $cache[$createdById];
}

/**
 * Get user statistics for admin dashboard.
 * Filters by created_by for regular admins, shows all for super_admin.
 * 
 * @param int|null $adminId If provided, filter by this admin's created users
 * @return array Statistics array
 */
function get_admin_user_stats(?int $adminId = null): array
{
    $where = '';
    $params = [];

    if ($adminId !== null) {
        $where = 'WHERE created_by = ?';
        $params[] = $adminId;
    }

    $total = fetch_one("SELECT COUNT(*) as cnt FROM users $where", $params);

    return [
        'total_users' => (int) ($total['cnt'] ?? 0),
    ];
}
