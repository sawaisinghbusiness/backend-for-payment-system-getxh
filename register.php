<?php
// ============================================================
// register.php — User registration (page + POST handler)
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

startSession();

// Already logged in → go to wallet
if (getSessionUserId() !== null) {
    header('Location: pay.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name     = trim((string)($_POST['name']     ?? ''));
    $email    = trim((string)($_POST['email']    ?? ''));
    $password = (string)($_POST['password']      ?? '');
    $confirm  = (string)($_POST['confirm']       ?? '');

    // ── Rate limit: 3 registrations per IP per 60s ───────────
    if (!checkRateLimit('register', 3, 60)) {
        $error = 'Too many attempts. Please wait a moment and try again.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $userId = registerUser($name, $email, $password);

            // Auto-login after register
            session_regenerate_id(true);
            $_SESSION['user_id']    = $userId;
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = strtolower($email);

            header('Location: pay.php');
            exit;

        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable) {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — UPI Wallet</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:      #0f1117;
    --surface: #1a1d27;
    --border:  #2a2d3e;
    --text:    #e2e8f0;
    --muted:   #8892a4;
    --accent:  #6366f1;
    --green:   #22c55e;
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

  .alert {
    padding: 11px 14px;
    border-radius: 9px;
    font-size: .88rem;
    margin-bottom: 18px;
  }

  .alert-error   { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.3);  color: var(--red);   }
  .alert-success { background: rgba(34,197,94,.1);  border: 1px solid rgba(34,197,94,.3);  color: var(--green); }

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
  <div class="subtitle">Create your account</div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="register.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

    <label for="name">Full Name</label>
    <input
      type="text"
      id="name"
      name="name"
      required
      maxlength="100"
      autofocus
      value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
    >

    <label for="email">Email Address</label>
    <input
      type="email"
      id="email"
      name="email"
      required
      maxlength="191"
      value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
    >

    <label for="password">Password <span style="font-size:.75rem;text-transform:none;letter-spacing:0;">(min. 8 characters)</span></label>
    <input
      type="password"
      id="password"
      name="password"
      required
      minlength="8"
      maxlength="128"
    >

    <label for="confirm">Confirm Password</label>
    <input
      type="password"
      id="confirm"
      name="confirm"
      required
      minlength="8"
      maxlength="128"
    >

    <button type="submit">Create Account</button>
  </form>

  <div class="footer-link">
    Already have an account? <a href="login.php">Sign in</a>
  </div>
</div>

</body>
</html>
