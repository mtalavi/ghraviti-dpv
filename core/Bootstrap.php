<?php
/**
 * Framework Bootstrap
 * 
 * Initializes the Mini-Framework: loads config, helpers, and optionally runs the router.
 * 
 * Usage:
 *   require_once 'core/Bootstrap.php';
 *   
 *   // For API endpoints or clean URLs:
 *   Bootstrap::run();
 *   
 *   // Or just load framework without routing:
 *   Bootstrap::init();
 */

class Bootstrap
{
    private static bool $initialized = false;

    /**
     * Initialize the framework (load dependencies)
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Load main functions (includes config, db, helpers)
        require_once __DIR__ . '/../functions.php';

        // Load core classes
        require_once __DIR__ . '/Request.php';
        require_once __DIR__ . '/Response.php';
        require_once __DIR__ . '/Controller.php';
        require_once __DIR__ . '/Router.php';

        self::$initialized = true;
    }

    /**
     * Run the router (for API/clean URL endpoints)
     */
    public static function run(): void
    {
        self::init();

        // Set base path from config
        if (defined('BASE_URL')) {
            $basePath = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
            Router::setBasePath($basePath);
        }

        // Load routes
        $routesFile = __DIR__ . '/../config/routes.php';
        Router::loadRoutes($routesFile);

        // Try to dispatch
        $matched = Router::dispatch();

        // If no route matched, fall through to normal PHP processing
        // This allows backward compatibility with existing files
        if (!$matched) {
            return;
        }
    }

    /**
     * Check if framework is initialized
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }
}
