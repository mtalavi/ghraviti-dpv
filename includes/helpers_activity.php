<?php
/**
 * Activity Logging Helper Functions
 * 
 * Contains: Action logging for audit trails.
 * 
 * @package DPVHub
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('BASE_URL')) {
    die('Direct access not allowed');
}

// =====================================================
// ACTIVITY LOGGING
// =====================================================

/**
 * Log an action to the activity_logs table.
 * 
 * @param string $action Action name (e.g., 'login', 'user_create')
 * @param string $entityType Entity type (e.g., 'user', 'event')
 * @param int|null $entityId Entity ID (optional)
 * @param array $meta Additional metadata (stored as JSON)
 */
function log_action(string $action, string $entityType = '', $entityId = null, array $meta = []): void
{
    $actor = current_user();
    $data = json_encode($meta, JSON_UNESCAPED_UNICODE);
    execute_query(
        'INSERT INTO activity_logs (actor_user_id, action, entity_type, entity_id, meta, ip_address, user_agent) VALUES (?,?,?,?,?,?,?)',
        [
            $actor['id'] ?? null,
            $action,
            $entityType ?: null,
            $entityId,
            $data,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]
    );
}
