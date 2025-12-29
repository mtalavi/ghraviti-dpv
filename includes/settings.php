<?php
// Simple key/value settings store in DB (table app_settings).
// NOTE: This file is included FROM init.php, so db.php functions are already available.
// Do NOT require init.php here - it causes circular dependency!

function settings_get(string $key, $default = null)
{
    $row = fetch_one('SELECT value FROM app_settings WHERE `key`=? LIMIT 1', [$key]);
    return $row ? $row['value'] : $default;
}

function settings_set(string $key, string $value): void
{
    $exists = fetch_one('SELECT 1 FROM app_settings WHERE `key`=? LIMIT 1', [$key]);
    if ($exists) {
        execute_query('UPDATE app_settings SET value=? WHERE `key`=?', [$value, $key]);
    } else {
        execute_query('INSERT INTO app_settings (`key`,`value`) VALUES (?,?)', [$key, $value]);
    }
}
