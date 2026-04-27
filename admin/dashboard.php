<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../functions.php';
$stats = getDashboardStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — UPI Wallet</title>
<style>
  /* ── Reset & base ── */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:         #0f1117;
    --surface:    #1a1d27;
    --border:     #2a2d3e;
    --text:       #e2e8f0;
    --muted:      #8892a4;
    --accent:     #6366f1;
    --green:      #22c55e;
    --red:        #ef4444;
    --yellow:     #eab308;
    --radius:     12px;
    --shadow:     0 4px 24px rgba(0,0,0,.4);
  }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
  }

  /* ── Sidebar ── */
  .sidebar {
    width: 220px;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 28px 0;
    flex-shrink: 0;
  }

  .sidebar .logo {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--accent);
    padding: 0 24px 32px;
    letter-spacing: .5px;
  }

  .sidebar a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 24px;
    color: var(--muted);
    text-decoration: none;
    font-size: .93rem;
    transition: color .15s, background .15s;
    border-left: 3px solid transparent;
  }

  .sidebar a:hover,
  .sidebar a.active {
    color: var(--text);
    background: rgba(99,102,241,.08);
    border-left-color: var(--accent);
  }

  .sidebar a .icon { font-size: 1.1rem; }

  .sidebar .logout {
    margin-top: auto;
    padding: 0 16px 4px;
  }

  .sidebar .logout a {
    color: #ef4444;
    border-left-color: transparent;
  }

  .sidebar .logout a:hover {
    background: rgba(239,68,68,.08);
    border-left-color: #ef4444;
    color: #ef4444;
  }

  /* ── Main ── */
  .main {
    flex: 1;
    padding: 36px 40px;
    overflow-y: auto;
  }

  .page-title {
    font-size: 1.55rem;
    font-weight: 700;
    margin-bottom: 6px;
  }

  .page-sub {
    color: var(--muted);
    font-size: .9rem;
    margin-bottom: 36px;
  }

  /* ── Stat cards ── */
  .cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 40px;
  }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px 22px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
  }

  .card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: var(--card-accent, var(--accent));
    border-radius: var(--radius) var(--radius) 0 0;
  }

  .card .label {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--muted);
    margin-bottom: 10px;
  }

  .card .value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
  }

  .card .sub {
    font-size: .8rem;
    color: var(--muted);
    margin-top: 6px;
  }

  .card-total   { --card-accent: var(--accent); }
  .card-success { --card-accent: var(--green);  }
  .card-failed  { --card-accent: var(--red);    }
  .card-pending { --card-accent: var(--yellow); }
  .card-revenue { --card-accent: #06b6d4;       }

  /* ── Quick link ── */
  .quick-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--accent);
    color: #fff;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: .9rem;
    font-weight: 500;
    transition: opacity .15s;
  }

  .quick-link:hover { opacity: .85; }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="logo">⚡ UPI Admin</div>
  <a href="dashboard.php" class="active"><span class="icon">▦</span> Dashboard</a>
  <a href="payments.php"><span class="icon">💳</span> Payments</a>
  <div class="logout">
    <a href="logout.php"><span class="icon">⎋</span> Logout</a>
  </div>
</aside>

<main class="main">
  <div class="page-title">Dashboard</div>
  <div class="page-sub">Overview of all payment activity</div>

  <div class="cards">

    <div class="card card-total">
      <div class="label">Total Payments</div>
      <div class="value"><?= htmlspecialchars((string)$stats['total']) ?></div>
      <div class="sub">All time</div>
    </div>

    <div class="card card-success">
      <div class="label">Successful</div>
      <div class="value"><?= htmlspecialchars((string)$stats['success']) ?></div>
      <div class="sub">Verified & credited</div>
    </div>

    <div class="card card-failed">
      <div class="label">Failed</div>
      <div class="value"><?= htmlspecialchars((string)$stats['failed']) ?></div>
      <div class="sub">Not matched</div>
    </div>

    <div class="card card-pending">
      <div class="label">Pending</div>
      <div class="value"><?= htmlspecialchars((string)$stats['pending']) ?></div>
      <div class="sub">Awaiting verification</div>
    </div>

    <div class="card card-revenue">
      <div class="label">Total Revenue</div>
      <div class="value">₹<?= number_format($stats['revenue'], 2) ?></div>
      <div class="sub">Successful payments</div>
    </div>

  </div>

  <a href="payments.php" class="quick-link">💳 View All Payments →</a>
</main>

</body>
</html>
