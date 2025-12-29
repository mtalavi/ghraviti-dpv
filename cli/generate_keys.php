#!/usr/bin/env php
<?php
/**
 * CLI Script: Generate Encryption Keys
 * 
 * USAGE (from command line only):
 *   php cli/generate_keys.php
 * 
 * This script generates secure encryption keys for the DPV Hub application.
 * Keys are saved to includes/.keys file.
 * 
 * SECURITY: This file should NEVER be accessible via web browser.
 *           The .htaccess in cli/ folder blocks all HTTP access.
 */

// Prevent web access - only allow CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

$keysDir = dirname(__DIR__) . '/includes';
$keysFile = $keysDir . '/.keys';

// Check if keys already exist
if (file_exists($keysFile)) {
    echo "⚠️  WARNING: Keys file already exists at: {$keysFile}\n";
    echo "Overwriting will make ALL existing encrypted data UNRECOVERABLE!\n";
    echo "\nAre you ABSOLUTELY sure you want to generate NEW keys? (type 'YES' to confirm): ";

    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);

    if ($line !== 'YES') {
        echo "\n❌ Aborted. Existing keys preserved.\n";
        exit(1);
    }
    echo "\n";
}

// Generate secure random keys
$encKey = bin2hex(random_bytes(16));   // 32 hex chars = 128 bits
$blindKey = bin2hex(random_bytes(16)); // 32 hex chars = 128 bits

$content = "; DPV Encryption Keys - KEEP THIS FILE SECURE!\n";
$content .= "; Generated: " . date('Y-m-d H:i:s') . "\n";
$content .= "; Best practice: Move this file OUTSIDE the web root\n";
$content .= "; Or set as environment variables: DPV_ENCRYPTION_KEY and DPV_BLIND_INDEX_KEY\n\n";
$content .= "ENCRYPTION_KEY = \"{$encKey}\"\n";
$content .= "BLIND_INDEX_KEY = \"{$blindKey}\"\n";

// Write keys file
if (file_put_contents($keysFile, $content) === false) {
    echo "❌ ERROR: Could not write to {$keysFile}\n";
    exit(1);
}

// Set restrictive permissions (owner read/write only)
@chmod($keysFile, 0600);

echo "✅ Keys generated successfully!\n";
echo "   File: {$keysFile}\n";
echo "\n";
echo "🔐 Encryption Key:   {$encKey}\n";
echo "🔐 Blind Index Key:  {$blindKey}\n";
echo "\n";
echo "⚠️  IMPORTANT:\n";
echo "   1. For production, move .keys file OUTSIDE the web root, or\n";
echo "   2. Set as environment variables: DPV_ENCRYPTION_KEY, DPV_BLIND_INDEX_KEY\n";
echo "   3. NEVER share these keys or commit them to version control!\n";
