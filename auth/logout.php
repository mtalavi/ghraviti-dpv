<?php
require_once __DIR__ . '/../includes/init.php';
log_action('logout', 'user', current_user()['id'] ?? null);
session_destroy();
redirect(BASE_URL . '/auth/login.php');
