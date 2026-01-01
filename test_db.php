<?php
/**
 * Database Connection Test
 * Access: /test_db.php
 * Delete this file after testing!
 */

// Load config
require_once __DIR__ . '/config.php';

echo "<h2>🔧 Database Connection Test</h2>";
echo "<pre>";

// Show config (mask password)
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "DB_PASS: " . substr(DB_PASS, 0, 5) . "..." . substr(DB_PASS, -5) . " (" . strlen(DB_PASS) . " chars)\n\n";

// Test connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "✅ اتصال موفق!\n\n";

    // Show MySQL version
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "MySQL Version: $version\n";

    // List tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "\nTables (" . count($tables) . "):\n";
    foreach ($tables as $t) {
        echo "  - $t\n";
    }

} catch (PDOException $e) {
    echo "❌ خطا در اتصال!\n";
    echo "Error: " . $e->getMessage() . "\n";
}

echo "</pre>";

echo "<p style='color:red;font-weight:bold;'>⚠️ این فایل را بعد از تست حذف کنید!</p>";
