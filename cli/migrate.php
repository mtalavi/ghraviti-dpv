<?php
/**
 * Database Migration Runner
 * 
 * Usage: php cli/migrate.php
 * 
 * This script runs all pending migrations from the migrations/ directory.
 * Each migration file should be named: YYYY_MM_DD_HHMMSS_description.php
 */

require_once __DIR__ . '/../includes/init.php';

echo "═══════════════════════════════════════════════════════════\n";
echo "  DPV Hub - Database Migration Runner\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// Create migrations table if not exists
$pdo = db();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL UNIQUE,
        batch INT NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Get executed migrations
$executed = $pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN);
echo "Already executed: " . count($executed) . " migrations\n\n";

// Get migration files
$migrationsDir = __DIR__ . '/../migrations';
if (!is_dir($migrationsDir)) {
    mkdir($migrationsDir, 0755, true);
    echo "Created migrations directory.\n";
}

$files = glob($migrationsDir . '/*.php');
sort($files);

// Get next batch number
$batch = (int) $pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations")->fetchColumn();

$pending = [];
foreach ($files as $file) {
    $migration = basename($file, '.php');
    if (!in_array($migration, $executed)) {
        $pending[] = $file;
    }
}

if (empty($pending)) {
    echo "✓ Nothing to migrate. Database is up to date.\n";
    exit(0);
}

echo "Found " . count($pending) . " pending migration(s):\n";

foreach ($pending as $file) {
    $migration = basename($file, '.php');
    echo "  → Running: $migration ... ";

    try {
        $pdo->beginTransaction();

        // Include and run migration
        require $file;

        // Record migration
        $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migration, $batch]);

        $pdo->commit();
        echo "✓ Done\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "✗ FAILED\n";
        echo "    Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  ✓ All migrations completed successfully!\n";
echo "═══════════════════════════════════════════════════════════\n";
