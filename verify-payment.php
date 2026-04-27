<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$userId = requireUserSession();

verifyCsrf();

enforceRateLimit('verify_payment', 3, 60);

if (isUserSuspicious($userId)) {
    jsonResponse(403, ['status' => 'error', 'message' => 'Account temporarily restricted. Please contact support.']);
}

$dailyLimit = (float)(getenv('DAILY_LIMIT_INR') ?: 5000);
if (getUserDailyTotal($userId) >= $dailyLimit) {
    jsonResponse(429, ['status' => 'error', 'message' => 'Daily top-up limit reached. Try again tomorrow.']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$input['user_id'] = $userId;

try {
    ['utr' => $utr, 'amount' => $amount, 'user_id' => $userId] = validatePaymentInput($input);
} catch (InvalidArgumentException $e) {
    jsonResponse(400, ['status' => 'error', 'message' => $e->getMessage()]);
}

enforceRateLimit('utr_attempt', 3, 300, $utr);

try {
    $paymentId = insertPendingPayment($userId, $utr, $amount);
} catch (RuntimeException $e) {
    jsonResponse(409, ['status' => 'error', 'message' => $e->getMessage()]);
} catch (Throwable) {
    jsonResponse(500, ['status' => 'error', 'message' => 'Database error. Please try again.']);
}

$verified = verifyWithBharatPe($utr, $amount, $userId);

if ($verified === null) {
    jsonResponse(200, ['status' => 'pending', 'message' => 'Verifying automatically.', 'utr' => $utr]);
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

jsonResponse(200, ['status' => 'pending', 'message' => 'Payment not found yet. Keep checking.', 'utr' => $utr]);
