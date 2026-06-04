<?php
// ============================================================
// admin/auth.php — session guard, include at top of every admin page
// ============================================================

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_secure'   => true,
        'cookie_samesite' => 'Strict',
        'use_strict_mode' => true,
        'gc_maxlifetime'  => 1800,
    ]);
}

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Expire admin session after 30 minutes of inactivity
if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > 1800) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}
$_SESSION['admin_last_activity'] = time();
