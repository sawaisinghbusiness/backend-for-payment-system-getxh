<?php
// ============================================================
// admin/login.php — admin login page + POST handler
//
// Set credentials via environment variables:
//   ADMIN_USER  — admin username  (default: admin)
//   ADMIN_PASS  — bcrypt hash of the password
//
// To generate a hash, run in PHP CLI:
//   php -r "echo password_hash('your_password', PASSWORD_BCRYPT) . PHP_EOL;"
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

// Already logged in → go to dashboard
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF: compare submitted token against session token
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken   = $_SESSION['admin_csrf'] ?? '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $error = 'Invalid form submission. Please try again.';
        goto render; // skip auth logic, go straight to HTML
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $adminUser = getenv('ADMIN_USER') ?: 'admin';
    $adminHash = getenv('ADMIN_PASS') ?: '';

    // Brute-force: slow down on every login attempt
    usleep(200_000); // 200 ms

    $valid = $username === $adminUser
          && $adminHash !== ''
          && password_verify($password, $adminHash);

    if ($valid) {
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid username or password.';
}

render:
// Generate (or reuse) admin CSRF token
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['admin_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — UPI Wallet</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:      #0f1117;
    --surface: #1a1d27;
    --border:  #2a2d3e;
    --text:    #e2e8f0;
    --muted:   #8892a4;
    --accent:  #6366f1;
    --red:     #ef4444;
    --radius:  14px;
  }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 40px 36px;
    width: 100%;
    max-width: 380px;
    box-shadow: 0 8px 40px rgba(0,0,0,.5);
  }

  .logo {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 6px;
  }

  .subtitle {
    color: var(--muted);
    font-size: .87rem;
    margin-bottom: 30px;
  }

  label {
    display: block;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    margin-bottom: 7px;
  }

  input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 11px 14px;
    border-radius: 9px;
    font-size: .95rem;
    outline: none;
    transition: border-color .15s;
    margin-bottom: 18px;
  }

  input:focus { border-color: var(--accent); }

  button {
    width: 100%;
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 13px;
    border-radius: 9px;
    font-size: 1rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .15s;
    margin-top: 4px;
  }

  button:hover { opacity: .87; }

  .error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: var(--red);
    padding: 11px 14px;
    border-radius: 9px;
    font-size: .88rem;
    margin-bottom: 18px;
  }
</style>
</head>
<body>

<div class="card">
  <div class="logo">⚡ UPI Admin</div>
  <div class="subtitle">Sign in to access the admin panel</div>

  <?php if ($error !== ''): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="login.php" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <label for="username">Username</label>
    <input
      type="text"
      id="username"
      name="username"
      required
      autofocus
      maxlength="64"
      value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
    >

    <label for="password">Password</label>
    <input
      type="password"
      id="password"
      name="password"
      required
      maxlength="128"
    >

    <button type="submit">Sign In</button>
  </form>
</div>

</body>
</html>
