<?php
/**
 * Database connection for Coolify deployment
 * All credentials are loaded from environment variables
 */

function db()
{
    static $pdo = null;
    if ($pdo === null) {
        // Get credentials from Coolify environment variables
        $dbHost = getenv('DB_HOST') ?: 'mysql';
        $name = getenv('DB_NAME') ?: 'dpvhub';
        $user = getenv('DB_USER') ?: 'mysql';
        $pass = getenv('DB_PASS') ?: 'yGqQqT4vPCrkWJAmj92cBhcArfzhQ9NPB5PcqDoP733pinueNuwRHVWHdZ6kYYUC';
        $port = 3306;

        // Parse MySQL URL format from Coolify: mysql://user:pass@host:port/database
        if (strpos($dbHost, 'mysql://') === 0) {
            $parsed = parse_url($dbHost);
            $host = $parsed['host'] ?? 'mysql';
            $port = $parsed['port'] ?? 3306;
            // Use URL credentials if not separately provided
            if ($user === 'mysql' && isset($parsed['user'])) {
                $user = $parsed['user'];
            }
            if (isset($parsed['pass'])) {
                $pass = $parsed['pass'];
            }
            // Use URL database name if DB_NAME is default
            if ($name === 'dpvhub' && isset($parsed['path'])) {
                $urlDbName = ltrim($parsed['path'], '/');
                if ($urlDbName && $urlDbName !== 'default') {
                    $name = $urlDbName;
                }
            }
        } else {
            // Simple hostname format
            $host = $dbHost;
        }

        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        // Set MySQL session timezone to Dubai (UTC+4)
        $pdo->exec("SET time_zone = '+04:00'");
    }
    return $pdo;
}

function fetch_one($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetch();
}

function fetch_all($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function execute_query($sql, $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st; // Return PDOStatement for access to lastInsertId(), rowCount(), etc.
}

// =====================================================
// User-specific helpers with auto encryption/decryption
// =====================================================

/**
 * Fetch a single user and auto-decrypt sensitive fields
 */
function fetch_user_decrypted($sql, $params = [])
{
    $user = fetch_one($sql, $params);
    if ($user && function_exists('decrypt_user_data')) {
        return decrypt_user_data($user);
    }
    return $user;
}

/**
 * Fetch multiple users and auto-decrypt sensitive fields
 */
function fetch_users_decrypted($sql, $params = [])
{
    $users = fetch_all($sql, $params);
    if ($users && function_exists('decrypt_users_array')) {
        return decrypt_users_array($users);
    }
    return $users;
}
