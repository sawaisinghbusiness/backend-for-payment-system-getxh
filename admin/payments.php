<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../functions.php';

// ── Pagination / filter params ──────────────────────────────
$statusFilter = in_array($_GET['status'] ?? '', ['', 'pending', 'success', 'failed'], true)
    ? ($_GET['status'] ?? '')
    : '';

$utrSearch = trim(preg_replace('/[^A-Za-z0-9]/', '', $_GET['search'] ?? ''));

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$result = getPaymentsPage($statusFilter, $utrSearch, $limit, $offset);
$rows   = $result['rows'];
$total  = $result['total'];
$pages  = (int)ceil($total / $limit);

// ── Helper: build pagination URL ────────────────────────────
function pageUrl(int $p, string $status, string $search): string
{
    $q = http_build_query(array_filter([
        'page'   => $p,
        'status' => $status,
        'search' => $search,
    ]));
    return 'payments.php?' . $q;
}

// ── Status badge helper ──────────────────────────────────────
function statusBadge(string $status): string
{
    $map = [
        'success' => ['color' => '#22c55e', 'bg' => 'rgba(34,197,94,.12)',  'label' => '✓ Success'],
        'failed'  => ['color' => '#ef4444', 'bg' => 'rgba(239,68,68,.12)',   'label' => '✗ Failed'],
        'pending' => ['color' => '#eab308', 'bg' => 'rgba(234,179,8,.12)',   'label' => '⏳ Pending'],
    ];
    $s = $map[$status] ?? ['color' => '#8892a4', 'bg' => 'rgba(136,146,164,.12)', 'label' => ucfirst($status)];
    return sprintf(
        '<span style="color:%s;background:%s;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600;">%s</span>',
        $s['color'], $s['bg'], htmlspecialchars($s['label'])
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payments — UPI Wallet Admin</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:      #0f1117;
    --surface: #1a1d27;
    --border:  #2a2d3e;
    --text:    #e2e8f0;
    --muted:   #8892a4;
    --accent:  #6366f1;
    --radius:  12px;
    --shadow:  0 4px 24px rgba(0,0,0,.4);
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

  .page-title { font-size: 1.55rem; font-weight: 700; margin-bottom: 6px; }
  .page-sub   { color: var(--muted); font-size: .9rem; margin-bottom: 28px; }

  /* ── Toolbar ── */
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
    align-items: center;
  }

  .toolbar input[type=text] {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 9px 14px;
    border-radius: 8px;
    font-size: .9rem;
    width: 240px;
    outline: none;
    transition: border-color .15s;
  }

  .toolbar input[type=text]:focus { border-color: var(--accent); }

  .toolbar select {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 9px 14px;
    border-radius: 8px;
    font-size: .9rem;
    cursor: pointer;
    outline: none;
  }

  .toolbar button {
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: .9rem;
    cursor: pointer;
    font-weight: 500;
    transition: opacity .15s;
  }

  .toolbar button:hover { opacity: .85; }

  .toolbar .reset-link {
    color: var(--muted);
    font-size: .85rem;
    text-decoration: none;
    margin-left: 4px;
  }

  .toolbar .reset-link:hover { color: var(--text); }

  /* ── Stats strip ── */
  .strip {
    color: var(--muted);
    font-size: .85rem;
    margin-bottom: 16px;
  }

  /* ── Table ── */
  .table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow-x: auto;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    font-size: .88rem;
  }

  thead th {
    padding: 14px 18px;
    text-align: left;
    font-size: .74rem;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }

  tbody tr { transition: background .1s; }

  tbody tr:hover { background: rgba(255,255,255,.03); }

  tbody td {
    padding: 13px 18px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    color: var(--text);
  }

  tbody tr:last-child td { border-bottom: none; }

  .utr-cell {
    font-family: 'Courier New', monospace;
    font-size: .84rem;
    color: #a5b4fc;
    letter-spacing: .02em;
  }

  .amount-cell { font-weight: 600; }

  .id-cell { color: var(--muted); }

  /* ── Empty state ── */
  .empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
  }

  .empty .icon { font-size: 2.5rem; margin-bottom: 12px; }

  /* ── Pagination ── */
  .pagination {
    display: flex;
    justify-content: center;
    gap: 6px;
    padding: 24px 0 0;
  }

  .pagination a, .pagination span {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 7px;
    font-size: .85rem;
    text-decoration: none;
    border: 1px solid var(--border);
    color: var(--muted);
    transition: background .1s, color .1s;
  }

  .pagination a:hover   { background: rgba(99,102,241,.1); color: var(--text); }
  .pagination .current  { background: var(--accent); color: #fff; border-color: var(--accent); }
  .pagination .disabled { opacity: .35; cursor: default; pointer-events: none; }

  /* ── Action buttons ── */
  .action-btn {
    border: none;
    border-radius: 6px;
    padding: 5px 11px;
    font-size: .75rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .15s;
    margin-right: 4px;
  }

  .action-btn:disabled { opacity: .45; cursor: not-allowed; }

  .approve-btn { background: rgba(34,197,94,.15);  color: #22c55e; }
  .approve-btn:not(:disabled):hover { background: rgba(34,197,94,.25); }

  .reject-btn  { background: rgba(239,68,68,.15);  color: #ef4444; }
  .reject-btn:not(:disabled):hover  { background: rgba(239,68,68,.25); }
</style>
</head>
<body>

<aside class="sidebar">
  <div class="logo">⚡ UPI Admin</div>
  <a href="dashboard.php"><span>▦</span> Dashboard</a>
  <a href="payments.php" class="active"><span>💳</span> Payments</a>
  <div class="logout">
    <a href="logout.php"><span>⎋</span> Logout</a>
  </div>
</aside>

<main class="main">
  <div class="page-title">Payments</div>
  <div class="page-sub">Browse, filter, and search all payment transactions</div>

  <!-- Toolbar -->
  <form method="GET" action="payments.php">
    <div class="toolbar">
      <input
        type="text"
        name="search"
        placeholder="Search UTR…"
        value="<?= htmlspecialchars($utrSearch) ?>"
        maxlength="64"
      >
      <select name="status">
        <option value=""       <?= $statusFilter === ''        ? 'selected' : '' ?>>All Statuses</option>
        <option value="success"<?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option>
        <option value="failed" <?= $statusFilter === 'failed'  ? 'selected' : '' ?>>Failed</option>
        <option value="pending"<?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
      </select>
      <button type="submit">Filter</button>
      <a href="payments.php" class="reset-link">Reset</a>
    </div>
  </form>

  <div class="strip">
    Showing <?= count($rows) ?> of <strong><?= number_format($total) ?></strong> results
    <?php if ($statusFilter !== '' || $utrSearch !== ''): ?>
      (filtered)
    <?php endif; ?>
  </div>

  <!-- Table -->
  <div class="table-wrap">
    <?php if (empty($rows)): ?>
      <div class="empty">
        <div class="icon">📭</div>
        No payments found matching your criteria.
      </div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>User</th>
          <th>UTR</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Date &amp; Time</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr id="row-<?= (int)$row['id'] ?>">
          <td class="id-cell"><?= (int)$row['id'] ?></td>
          <td>
            <div style="font-weight:500;"><?= htmlspecialchars($row['user_name'] ?? '—') ?></div>
            <div style="font-size:.78rem;color:var(--muted);">ID <?= (int)$row['user_id'] ?></div>
          </td>
          <td class="utr-cell"><?= htmlspecialchars($row['utr']) ?></td>
          <td class="amount-cell">₹<?= number_format((float)$row['amount'], 2) ?></td>
          <td id="status-<?= (int)$row['id'] ?>"><?= statusBadge($row['status']) ?></td>
          <td style="color:var(--muted);white-space:nowrap;">
            <?= htmlspecialchars(date('d M Y, H:i', strtotime($row['created_at']))) ?>
          </td>
          <td>
            <?php if ($row['status'] === 'pending'): ?>
              <button
                class="action-btn approve-btn"
                onclick="adminAction(<?= (int)$row['id'] ?>, 'approve')"
              >✓ Approve</button>
              <button
                class="action-btn reject-btn"
                onclick="adminAction(<?= (int)$row['id'] ?>, 'reject')"
              >✗ Reject</button>
            <?php else: ?>
              <span style="color:var(--muted);font-size:.78rem;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="pagination">

    <?php if ($page > 1): ?>
      <a href="<?= pageUrl($page - 1, $statusFilter, $utrSearch) ?>">‹ Prev</a>
    <?php else: ?>
      <span class="disabled">‹ Prev</span>
    <?php endif; ?>

    <?php
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1): ?><span>…</span><?php endif;
    for ($i = $start; $i <= $end; $i++): ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= pageUrl($i, $statusFilter, $utrSearch) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor;
    if ($end < $pages): ?><span>…</span><?php endif; ?>

    <?php if ($page < $pages): ?>
      <a href="<?= pageUrl($page + 1, $statusFilter, $utrSearch) ?>">Next ›</a>
    <?php else: ?>
      <span class="disabled">Next ›</span>
    <?php endif; ?>

  </div>
  <?php endif; ?>

</main>

<script>
function adminAction(paymentId, action) {
  const label = action === 'approve' ? 'Approve' : 'Reject';
  if (!confirm(`${label} payment #${paymentId}?`)) return;

  const row = document.getElementById('row-' + paymentId);
  const btns = row ? row.querySelectorAll('.action-btn') : [];
  btns.forEach(b => { b.disabled = true; b.textContent = '…'; });

  const body = new FormData();
  body.append('payment_id', paymentId);
  body.append('action', action);

  fetch('action.php', { method: 'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'ok') {
        // Replace action cell buttons with final status badge
        const statusCell = document.getElementById('status-' + paymentId);
        if (statusCell) {
          const color  = action === 'approve' ? '#22c55e' : '#ef4444';
          const bg     = action === 'approve' ? 'rgba(34,197,94,.12)' : 'rgba(239,68,68,.12)';
          const symbol = action === 'approve' ? '✓ Success' : '✗ Failed';
          statusCell.innerHTML = `<span style="color:${color};background:${bg};padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600;">${symbol}</span>`;
        }
        btns.forEach(b => b.remove());
        const actionCell = btns[0]?.closest('td');
        if (actionCell) actionCell.innerHTML = '<span style="color:var(--muted);font-size:.78rem;">Done</span>';
      } else {
        alert('Error: ' + (data.message || 'Unknown error'));
        btns.forEach((b, i) => {
          b.disabled = false;
          b.textContent = i === 0 ? '✓ Approve' : '✗ Reject';
        });
      }
    })
    .catch(() => {
      alert('Network error. Please try again.');
      btns.forEach((b, i) => {
        b.disabled = false;
        b.textContent = i === 0 ? '✓ Approve' : '✗ Reject';
      });
    });
}
</script>

</body>
</html>
