<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// ── CORS — restrict to configured frontend origin ─────────────
$allowedOrigin = rtrim((string)(getenv('ALLOWED_ORIGIN') ?: ''), '/');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($allowedOrigin !== '' && $requestOrigin === $allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
} elseif ($allowedOrigin === '') {
    header('Access-Control-Allow-Origin: *'); // fallback if env not set
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
header('Vary: Origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

// ── Parse input ───────────────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($input)) $input = $_POST;

// ── Authenticate User ─────────────────────────────────────────
$userId = getSessionUserId();
if ($userId === null) {
    // Public frontend client sends the user uid in the JSON body
    $userId = !empty($input['uid']) ? trim((string)$input['uid']) : null;
    if ($userId === null) {
        jsonResponse(401, ['status' => 'error', 'message' => 'Not authenticated. Please log in.']);
    }
} else {
    // Only enforce CSRF for session-based admin portal actions
    verifyCsrf();
}

// ── Rate limit: 3 attempts per IP per minute ─────────────────
enforceRateLimit('verify_payment', 3, 60, $userId, 3);

if (isUserSuspicious($userId)) {
    logSecurityEvent('blocked_suspicious_user', ['user_id' => $userId]);
    jsonResponse(403, ['status' => 'error', 'message' => 'Account temporarily restricted.']);
}

$utr    = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $input['utr'] ?? '')));
$amount = round((float)($input['amount'] ?? 0), 2);

if (strlen($utr) < 8 || strlen($utr) > 64) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid UTR.']);
}
if ($amount <= 0 || $amount > 100000) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid amount.']);
}

// ── Daily limit enforcement ───────────────────────────────────
$dailyLimit = (float)(getenv('DAILY_LIMIT_INR') ?: 20000);
$dailyTotal = getUserDailyTotal($userId);
if (($dailyTotal + $amount) > $dailyLimit) {
    logSecurityEvent('daily_limit_exceeded', ['user_id' => $userId, 'attempted' => $amount, 'daily_total' => $dailyTotal]);
    jsonResponse(429, ['status' => 'error', 'message' => 'Daily top-up limit reached.']);
}

// ── Duplicate UTR check ───────────────────────────────────────
$existing = getDB()->payments->findOne(['utr' => $utr, 'status' => 'success']);
if ($existing) {
    // IDOR: block if this success belongs to a different user
    if ((string)($existing['user_id'] ?? '') !== $userId) {
        logSecurityEvent('utr_reuse_attempt', ['user_id' => $userId, 'utr' => $utr]);
        jsonResponse(409, ['status' => 'error', 'message' => 'This UTR is already verified.']);
    }
    jsonResponse(409, ['status' => 'error', 'message' => 'This UTR is already verified.']);
}

// ── Verify with BharatPe ──────────────────────────────────────
$result = verifyWithBharatPe($utr, $amount, $userId);
error_log('[DEBUG] verifyWithBharatPe result = ' . var_export($result, true));

// ── Persist with user ownership ──────────────────────────────
try {
    getDB()->payments->updateOne(
        ['utr' => $utr],
        [
            '$set' => [
                'utr'        => $utr,
                'user_id'    => $userId,
                'amount'     => $amount,
                'status'     => $result === true ? 'success' : 'failed',
                'updated_at' => new MongoDB\BSON\UTCDateTime(),
            ],
            '$setOnInsert' => [
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ],
        ],
        ['upsert' => true]
    );
} catch (Throwable $e) {
    error_log('[verify] DB save error: ' . $e->getMessage());
}

if ($result === true) {
    logSecurityEvent('payment_verified', ['user_id' => $userId, 'utr' => $utr, 'amount' => $amount]);
    jsonResponse(200, ['status' => 'success', 'message' => 'Payment verified.']);
}

jsonResponse(200, ['status' => 'failed', 'message' => 'Payment not found.']);
