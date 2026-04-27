<?php
// ============================================================
// check-status.php
// GET endpoint: ?utr=XXXXX
//
// Returns:
//   { status: "success", balance: float }  — verified & credited
//   { status: "pending" }                  — still not found
//   { status: "failed"  }                  — permanently failed
//   { status: "error",  message: string }  — bad input / not found / not logged in
//
// Polling cooldown: BharatPe API is only called once per 10 seconds
// per UTR — repeated polls within that window return the cached DB status.
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/functions.php';

define('POLL_COOLDOWN_SECONDS', 10);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

// ── Require login ────────────────────────────────────────────
$userId = requireUserSession();

// ── Rate limit: 10 polls / 60 sec per IP ─────────────────────
enforceRateLimit('check_status', 10, 60);

// ── Suspicious user block ─────────────────────────────────────
if (isUserSuspicious($userId)) {
    jsonResponse(403, [
        'status'  => 'error',
        'message' => 'Account temporarily restricted. Please contact support.',
    ]);
}

// ── Sanitise UTR ─────────────────────────────────────────────
$utr = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $_GET['utr'] ?? '')));

if ($utr === '' || strlen($utr) < 8 || strlen($utr) > 64) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid or missing UTR.']);
}

// ── Fetch payment row ─────────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare(
    'SELECT id, user_id, amount, status, last_checked_at, created_at
     FROM payments WHERE utr = ? LIMIT 1'
);
$stmt->execute([$utr]);
$payment = $stmt->fetch();

if (!$payment) {
    jsonResponse(404, ['status' => 'error', 'message' => 'Payment record not found.']);
}

// ── Ownership check ───────────────────────────────────────────
if ((int)$payment['user_id'] !== $userId) {
    jsonResponse(403, ['status' => 'error', 'message' => 'Access denied.']);
}

// ── Already resolved — return immediately, no API call needed ─
if ($payment['status'] === 'success') {
    jsonResponse(200, [
        'status'  => 'success',
        'balance' => getUserBalance($userId),
    ]);
}

if ($payment['status'] === 'failed') {
    jsonResponse(200, ['status' => 'failed']);
}

// ── Pending timeout: auto-expire payments older than 60 min ──
if (expireStalePendingPayment((int)$payment['id'], $payment['created_at'])) {
    jsonResponse(200, ['status' => 'failed']);
}

// ── Polling cooldown (API cache) ──────────────────────────────
// If checked within the last 10 seconds, return DB status immediately.
// last_checked_at is stamped BEFORE the BharatPe call so this stays
// active even if the API hangs — prevents polling every 3 sec from
// hitting BharatPe directly.
if ($payment['last_checked_at'] !== null) {
    $sinceLastCheck = time() - strtotime($payment['last_checked_at']);
    if ($sinceLastCheck < POLL_COOLDOWN_SECONDS) {
        jsonResponse(200, ['status' => 'pending']);
    }
}

// ── Stamp NOW before hitting BharatPe ────────────────────────
$db->prepare(
    "UPDATE payments SET last_checked_at = NOW() WHERE id = ?"
)->execute([(int)$payment['id']]);

// ── Re-verify with BharatPe ──────────────────────────────────
// null = API unreachable → stay pending, frontend keeps polling
$verified = verifyWithBharatPe($utr, (float)$payment['amount'], $userId);

if ($verified === null) {
    jsonResponse(200, ['status' => 'pending']);
}

if ($verified === true) {
    try {
        finalisePayment(
            (int)$payment['id'],
            $userId,
            (float)$payment['amount'],
            true
        );
    } catch (Throwable) {
        jsonResponse(500, ['status' => 'error', 'message' => 'Wallet credit failed.']);
    }

    jsonResponse(200, [
        'status'  => 'success',
        'balance' => getUserBalance($userId),
    ]);
}

// Still not confirmed
jsonResponse(200, ['status' => 'pending']);
