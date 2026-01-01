<?php
/**
 * Example Migration
 * 
 * This file demonstrates how to create a migration.
 * 
 * Migration files should be named: YYYY_MM_DD_HHMMSS_description.php
 * Example: 2026_01_02_001500_add_user_status_column.php
 * 
 * The $pdo variable is available from the migration runner.
 */

// Add your SQL statements here
// $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");

// Or use prepared statements for data migrations
// $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE role = ?");
// $stmt->execute(['verified', 'admin']);

echo "    (Example migration - no action taken)\n";
