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
    ]);
}

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
