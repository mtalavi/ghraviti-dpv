<?php
/**
 * Centralized Application Configuration
 * 
 * All redirect rules, navigation settings, and role-based configurations
 * are defined here. Change once, apply everywhere.
 * 
 * Usage:
 *   $dest = get_role_redirect('after_login', $user['role']);
 *   $home = get_nav_home($user['role']);
 */

return [
    // ==========================================
    // REDIRECT DESTINATIONS
    // ==========================================
    'redirects' => [
        // After successful login
        'after_login' => [
            'super_admin' => '/admin/dashboard.php',
            'admin' => '/user/dashboard.php',
            'user' => '/user/dashboard.php',
        ],

        // After consent signed
        'after_consent' => [
            'super_admin' => '/admin/dashboard.php',
            'admin' => '/user/dashboard.php',
            'user' => '/user/dashboard.php',
        ],

        // Default fallback
        'default' => '/user/dashboard.php',
    ],

    // ==========================================
    // NAVIGATION HOME LINKS
    // ==========================================
    'nav_home' => [
        'super_admin' => '/admin/dashboard.php',
        'admin' => '/user/dashboard.php',
        'user' => '/user/dashboard.php',
    ],

    // ==========================================
    // PERMISSION DEFINITIONS
    // ==========================================
    'permissions' => [
        'manage_users' => ['description' => 'Manage volunteer profiles'],
        'manage_events' => ['description' => 'Create and edit events'],
        'manage_exports' => ['description' => 'Export CSV data'],
        'manage_attendees' => ['description' => 'View event attendees'],
        'view_logs' => ['description' => 'View activity logs'],
    ],

    // Default permissions for new admins
    'default_admin_permissions' => ['manage_events'],
];
