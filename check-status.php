<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/functions.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

define('POLL_COOLDOWN_SECONDS', 10);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$userId = requireUserSession();

enforceRateLimit('check_status', 10, 60);

if (isUserSuspicious($userId)) {
    jsonResponse(403, ['status' => 'error', 'message' => 'Account temporarily restricted.']);
}

$utr = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $_GET['utr'] ?? '')));
if ($utr === '' || strlen($utr) < 8 || strlen($utr) > 64) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid or missing UTR.']);
}

$db      = getDB();
$payment = $db->payments->findOne(['utr' => $utr]);

if (!$payment) {
    jsonResponse(404, ['status' => 'error', 'message' => 'Payment record not found.']);
}

$paymentId = (string)$payment['_id'];

if ((string)$payment['user_id'] !== $userId) {
    jsonResponse(403, ['status' => 'error', 'message' => 'Access denied.']);
}

if ($payment['status'] === 'success') {
    jsonResponse(200, ['status' => 'success', 'balance' => getUserBalance($userId)]);
}

if ($payment['status'] === 'failed') {
    jsonResponse(200, ['status' => 'failed']);
}

if (expireStalePendingPayment($paymentId, $payment['created_at'])) {
    jsonResponse(200, ['status' => 'failed']);
}

// Polling cooldown
if ($payment['last_checked_at'] instanceof UTCDateTime) {
    $sinceLastCheck = time() - $payment['last_checked_at']->toDateTime()->getTimestamp();
    if ($sinceLastCheck < POLL_COOLDOWN_SECONDS) {
        jsonResponse(200, ['status' => 'pending']);
    }
}

// Stamp before API call
$db->payments->updateOne(
    ['_id' => new ObjectId($paymentId)],
    ['$set' => ['last_checked_at' => new UTCDateTime()]]
);

$verified = verifyWithBharatPe($utr, (float)$payment['amount'], $userId);

if ($verified === null) {
    jsonResponse(200, ['status' => 'pending']);
}

if ($verified === true) {
    try {
        finalisePayment($paymentId, $userId, (float)$payment['amount'], true);
    } catch (Throwable) {
        jsonResponse(500, ['status' => 'error', 'message' => 'Wallet credit failed.']);
    }
    jsonResponse(200, ['status' => 'success', 'balance' => getUserBalance($userId)]);
}

jsonResponse(200, ['status' => 'pending']);
