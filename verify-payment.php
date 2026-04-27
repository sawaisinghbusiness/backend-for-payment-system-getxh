<?php
// ============================================================
// verify-payment.php
// POST endpoint: { utr, amount }   ← user_id comes from session
//
// Returns:
//   { status: "success", balance: float }  — verified & credited
//   { status: "pending" }                  — not found yet, poll check-status.php
//   { status: "error",   message: string } — bad input / duplicate / not logged in
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

// ── Require login ────────────────────────────────────────────
$userId = requireUserSession();

// ── CSRF check ────────────────────────────────────────────────
verifyCsrf();

// ── Rate limit: 3 submissions / 60 sec per IP ────────────────
enforceRateLimit('verify_payment', 3, 60);

// ── Suspicious user block ─────────────────────────────────────
if (isUserSuspicious($userId)) {
    jsonResponse(403, [
        'status'  => 'error',
        'message' => 'Account temporarily restricted. Please contact support.',
    ]);
}

// ── Daily cap: max ₹5000 / day per user ──────────────────────
$dailyLimit = (float)(getenv('DAILY_LIMIT_INR') ?: 5000);
if (getUserDailyTotal($userId) >= $dailyLimit) {
    jsonResponse(429, [
        'status'  => 'error',
        'message' => 'Daily top-up limit reached. Try again tomorrow.',
    ]);
}

// ── Parse body ───────────────────────────────────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

// Inject session user_id so validatePaymentInput works unchanged
$input['user_id'] = $userId;

// ── Validate ─────────────────────────────────────────────────
try {
    ['utr' => $utr, 'amount' => $amount, 'user_id' => $userId] = validatePaymentInput($input);
} catch (InvalidArgumentException $e) {
    jsonResponse(400, ['status' => 'error', 'message' => $e->getMessage()]);
}

// ── Rate limit: same UTR max 3 tries across all IPs ─────────
enforceRateLimit('utr_attempt', 3, 300, $utr);

// ── Insert as pending (blocks duplicate UTR at DB level) ─────
try {
    $paymentId = insertPendingPayment($userId, $utr, $amount);
} catch (RuntimeException $e) {
    jsonResponse(409, ['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable) {
    jsonResponse(500, ['status' => 'error', 'message' => 'Database error. Please try again.']);
}

// ── First verification attempt ───────────────────────────────
// null = BharatPe API unreachable → leave pending, frontend will poll
$verified = verifyWithBharatPe($utr, $amount, $userId);

if ($verified === null) {
    jsonResponse(200, [
        'status'  => 'pending',
        'message' => 'Could not reach payment provider. Still verifying automatically.',
        'utr'     => $utr,
    ]);
}

if ($verified === true) {
    try {
        finalisePayment($paymentId, $userId, $amount, true);
    } catch (Throwable) {
        jsonResponse(500, ['status' => 'error', 'message' => 'Failed to credit wallet.']);
    }

    jsonResponse(200, [
        'status'  => 'success',
        'message' => 'Payment verified. Wallet credited.',
        'balance' => getUserBalance($userId),
    ]);
}

// ── Not found on first try → leave as pending, tell frontend to poll ──
jsonResponse(200, [
    'status'  => 'pending',
    'message' => 'Payment not found yet. We will keep checking automatically.',
    'utr'     => $utr,
]);
