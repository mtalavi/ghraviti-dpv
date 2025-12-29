<?php
require_once __DIR__ . '/../includes/init.php';
require_login();

$u = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/user/dashboard.php');
}
if (!csrf_check($_POST['csrf'] ?? '')) {
    flash('error', 'Security check failed.');
    redirect(BASE_URL . '/user/dashboard.php');
}

$current = $_POST['current'] ?? '';
$newpass = $_POST['newpass'] ?? '';
if (!password_verify($current, $u['password_hash'])) {
    flash('error', 'Current password is incorrect.');
    redirect(BASE_URL . '/user/dashboard.php');
}

if (strlen($newpass) < 8) {
    flash('error', 'New password must be at least 8 characters.');
    redirect(BASE_URL . '/user/dashboard.php');
}

execute_query('UPDATE users SET password_hash=? WHERE id=?', [password_hash($newpass, PASSWORD_DEFAULT), $u['id']]);
log_action('change_password', 'user', $u['id']);
flash('success', 'Password updated.');
redirect(BASE_URL . '/user/dashboard.php');
