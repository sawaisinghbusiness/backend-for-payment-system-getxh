<?php
// ============================================================
// login.php — User login (page + POST handler)
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

startSession();

// Already logged in → go to wallet
if (getSessionUserId() !== null) {
    header('Location: pay.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password']      ?? '');

    // ── Rate limit: 5 per IP per 60s, 10 per email per 300s ──
    if (!checkRateLimit('login', 5, 60)
     || !checkRateLimit('login', 10, 300, strtolower($email))) {
        $error = 'Too many login attempts. Please wait a moment and try again.';
    } else {
    // Slow down brute force
    usleep(200_000);

    try {
        $user = loginUser($email, $password);
    } catch (Throwable) {
        $user = false;
    }

    if ($user !== false) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];

        header('Location: pay.php');
        exit;
    }

    $error = 'Invalid email or password.';
    } // end rate-limit else
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — UPI Wallet</title>
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
    max-width: 400px;
    box-shadow: 0 8px 40px rgba(0,0,0,.5);
  }

  .logo     { font-size: 1.4rem; font-weight: 700; color: var(--accent); margin-bottom: 4px; }
  .subtitle { color: var(--muted); font-size: .87rem; margin-bottom: 28px; }

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
    margin-bottom: 16px;
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

  .alert-error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: var(--red);
    padding: 11px 14px;
    border-radius: 9px;
    font-size: .88rem;
    margin-bottom: 18px;
  }

  .footer-link {
    text-align: center;
    margin-top: 20px;
    font-size: .85rem;
    color: var(--muted);
  }

  .footer-link a { color: var(--accent); text-decoration: none; }
  .footer-link a:hover { text-decoration: underline; }
</style>
</head>
<body>

<div class="card">
  <div class="logo">⚡ GetXH Wallet</div>
  <div class="subtitle">Sign in to your wallet</div>

  <?php if ($error !== ''): ?>
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="login.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

    <label for="email">Email Address</label>
    <input
      type="email"
      id="email"
      name="email"
      required
      maxlength="191"
      autofocus
      value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
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

  <div class="footer-link">
    Don't have an account? <a href="register.php">Create one</a>
  </div>
</div>

</body>
</html>
