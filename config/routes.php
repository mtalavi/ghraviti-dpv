<?php
/**
 * Routes Configuration
 * 
 * Define all application routes here.
 * Format: 'METHOD /path' => [ControllerClass, 'method']
 * 
 * Path parameters use {name} syntax.
 * Example: '/user/{id}' captures the id parameter.
 * 
 * These routes are OPTIONAL - existing PHP files still work directly.
 * Use routes for clean URLs and API endpoints.
 */

return [
    // ==========================================
    // API Routes (for mobile app, AJAX)
    // ==========================================

    // Example API routes - uncomment as needed:
    'GET /api/user' => [ApiController::class, 'getUser'],
    'POST /api/login' => [ApiController::class, 'login'],
    'GET /api/events' => [ApiController::class, 'getEvents'],
    'GET /api/events/{id}' => [ApiController::class, 'getEvent'],

    // ==========================================
    // Clean URL Routes (optional - uncomment as needed)
    // ==========================================

    // Uncomment to enable clean URLs:
    // 'GET /login'              => [AuthController::class, 'showLogin'],
    // 'POST /login'             => [AuthController::class, 'login'],
    // 'GET /logout'             => [AuthController::class, 'logout'],
    // 'GET /dashboard'          => [UserController::class, 'dashboard'],
];
