<?php
// Legacy path kept for compatibility. Redirects to new /event/console.php.
$slug = $_GET['slug'] ?? '';
header('Location: ../event/console.php?slug=' . urlencode($slug));
exit;
