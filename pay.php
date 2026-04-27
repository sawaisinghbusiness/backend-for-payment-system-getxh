<?php
// ============================================================
// pay.php — Wallet top-up page (requires login)
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

startSession();

$userId = getSessionUserId();
if ($userId === null) {
    header('Location: login.php');
    exit;
}

$userName = htmlspecialchars($_SESSION['user_name'] ?? '');
$balance  = getUserBalance($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<title>Add Money — UPI Wallet</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #0f1117;
    --surface:  #1a1d27;
    --border:   #2a2d3e;
    --text:     #e2e8f0;
    --muted:    #8892a4;
    --accent:   #6366f1;
    --green:    #22c55e;
    --yellow:   #eab308;
    --red:      #ef4444;
    --radius:   14px;
  }

  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  /* ── Top bar ── */
  .topbar {
    width: 100%;
    max-width: 420px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
    font-size: .88rem;
    color: var(--muted);
  }

  .topbar .user-name { font-weight: 600; color: var(--text); }

  .topbar a {
    color: var(--muted);
    text-decoration: none;
    font-size: .82rem;
    transition: color .15s;
  }

  .topbar a:hover { color: var(--red); }

  /* ── Balance strip ── */
  .balance-strip {
    width: 100%;
    max-width: 420px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 22px;
    margin-bottom: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .balance-strip .label { font-size: .78rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
  .balance-strip .amount { font-size: 1.6rem; font-weight: 700; color: var(--green); }

  /* ── Main card ── */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 36px 32px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 8px 40px rgba(0,0,0,.5);
  }

  .card-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 4px; }
  .card-sub   { color: var(--muted); font-size: .88rem; margin-bottom: 28px; }

  label {
    display: block;
    font-size: .8rem;
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

  button:disabled { opacity: .55; cursor: not-allowed; }
  button:not(:disabled):hover { opacity: .87; }

  /* ── Status box ── */
  #status-box {
    display: none;
    margin-top: 22px;
    padding: 16px;
    border-radius: 10px;
    font-size: .9rem;
    font-weight: 500;
    text-align: center;
    border: 1px solid transparent;
    line-height: 1.5;
  }

  #status-box.pending {
    background: rgba(234,179,8,.1);
    border-color: rgba(234,179,8,.3);
    color: var(--yellow);
  }

  #status-box.success {
    background: rgba(34,197,94,.1);
    border-color: rgba(34,197,94,.3);
    color: var(--green);
  }

  #status-box.error {
    background: rgba(239,68,68,.1);
    border-color: rgba(239,68,68,.3);
    color: var(--red);
  }

  .dots span {
    display: inline-block;
    animation: blink 1.2s infinite;
    font-size: 1.3rem;
    line-height: 1;
  }

  .dots span:nth-child(2) { animation-delay: .2s; }
  .dots span:nth-child(3) { animation-delay: .4s; }

  @keyframes blink {
    0%, 80%, 100% { opacity: 0; }
    40%            { opacity: 1; }
  }

  #attempt-info {
    display: none;
    text-align: center;
    font-size: .78rem;
    color: var(--muted);
    margin-top: 10px;
  }
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <span>Hello, <span class="user-name"><?= $userName ?></span></span>
  <a href="logout.php">Sign out</a>
</div>

<!-- Live balance -->
<div class="balance-strip">
  <div>
    <div class="label">Wallet Balance</div>
    <div class="amount" id="live-balance">₹<?= number_format($balance, 2) ?></div>
  </div>
  <span style="font-size:1.6rem;">💰</span>
</div>

<!-- Payment form -->
<div class="card">
  <div class="card-title">⚡ Add Money</div>
  <div class="card-sub">Pay via BharatPe QR, then enter your UTR to confirm.</div>

  <form id="pay-form" onsubmit="return false;">

    <label for="utr">UTR / Transaction Reference</label>
    <input
      type="text"
      id="utr"
      placeholder="e.g. 407311819222"
      maxlength="64"
      autocomplete="off"
      spellcheck="false"
    >

    <label for="amount">Amount (₹)</label>
    <input
      type="number"
      id="amount"
      placeholder="e.g. 500"
      min="1"
      step="0.01"
    >

    <button type="button" id="verify-btn" onclick="verifyPayment()">
      Verify Payment
    </button>

  </form>

  <div id="status-box"></div>
  <div id="attempt-info"></div>
</div>

<script>
const MAX_ATTEMPTS  = 10;
const POLL_INTERVAL = 3000;

let pollTimer = null;
let attempts  = 0;

function setStatus(type, html) {
  const box = document.getElementById('status-box');
  box.className     = type;
  box.innerHTML     = html;
  box.style.display = 'block';
}

function setAttemptInfo(text) {
  const el = document.getElementById('attempt-info');
  el.style.display = text ? 'block' : 'none';
  el.textContent   = text;
}

function setButtonBusy(busy) {
  const btn = document.getElementById('verify-btn');
  btn.disabled    = busy;
  btn.textContent = busy ? 'Verifying…' : 'Verify Payment';
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  setAttemptInfo('');
}

function updateBalance(newBalance) {
  document.getElementById('live-balance').textContent =
    '₹' + parseFloat(newBalance).toFixed(2);
}

function verifyPayment() {
  stopPolling();
  attempts = 0;

  const utr    = document.getElementById('utr').value.trim();
  const amount = parseFloat(document.getElementById('amount').value);

  if (!utr || utr.length < 8) {
    setStatus('error', '⚠️ Please enter a valid UTR (minimum 8 characters).');
    return;
  }

  if (!amount || amount <= 0) {
    setStatus('error', '⚠️ Please enter a valid amount.');
    return;
  }

  setButtonBusy(true);
  setStatus('pending', 'Checking with BharatPe <span class="dots"><span>•</span><span>•</span><span>•</span></span>');

  fetch('verify-payment.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ utr, amount }),
  })
    .then(r => r.json())
    .then(data => {
      setButtonBusy(false);

      if (data.status === 'success') {
        handleSuccess(data.balance);
      } else if (data.status === 'pending') {
        setStatus('pending',
          '⏳ Payment not found yet.<br>Auto-checking every 3 seconds <span class="dots"><span>•</span><span>•</span><span>•</span></span>'
        );
        startPolling(utr);
      } else {
        setStatus('error', '❌ ' + (data.message || 'Something went wrong.'));
      }
    })
    .catch(() => {
      setButtonBusy(false);
      setStatus('error', '❌ Network error. Please try again.');
    });
}

function pollingMessage(attempt) {
  if (attempt <= 3) {
    return '⏳ Verifying your payment <span class="dots"><span>•</span><span>•</span><span>•</span></span>';
  } else if (attempt <= 6) {
    return '🔄 Still checking with BharatPe <span class="dots"><span>•</span><span>•</span><span>•</span></span><br><small style="opacity:.6">This is normal, please wait</small>';
  } else {
    return '⌛ Taking longer than usual <span class="dots"><span>•</span><span>•</span><span>•</span></span><br><small style="opacity:.6">Your payment is being verified, don\'t close this page</small>';
  }
}

function startPolling(utr) {
  attempts = 0;

  pollTimer = setInterval(() => {
    attempts++;
    setStatus('pending', pollingMessage(attempts));
    setAttemptInfo(`Check ${attempts} of ${MAX_ATTEMPTS}`);

    fetch('check-status.php?utr=' + encodeURIComponent(utr))
      .then(r => r.json())
      .then(data => {
        if (data.status === 'success') {
          stopPolling();
          handleSuccess(data.balance);
          return;
        }

        if (data.status === 'failed') {
          stopPolling();
          setStatus('error',
            '❌ Payment could not be verified.<br>' +
            '<small style="opacity:.7">If money was deducted, contact support with your UTR.</small>'
          );
          return;
        }

        if (attempts >= MAX_ATTEMPTS) {
          stopPolling();
          setStatus('pending',
            '⏳ Verification is taking longer than expected.<br>' +
            'Your wallet will be credited automatically once confirmed.<br>' +
            '<small style="opacity:.7">You can safely close this page and check back later.</small>'
          );
        }
      })
      .catch(() => { /* silent on network blip, next poll will retry */ });

  }, POLL_INTERVAL);
}

function handleSuccess(balance) {
  const balStr = typeof balance === 'number' ? '₹' + balance.toFixed(2) : '';

  setStatus('success',
    '✅ Payment Verified!<br>' +
    (balStr ? '<span style="font-size:1.1rem;font-weight:700;">' + balStr + '</span> new wallet balance' : '')
  );

  if (typeof balance === 'number') {
    updateBalance(balance);
  }

  document.getElementById('utr').value    = '';
  document.getElementById('amount').value = '';
}
</script>

</body>
</html>
