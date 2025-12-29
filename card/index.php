<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
redirect(BASE_URL . '/user/card.php');
