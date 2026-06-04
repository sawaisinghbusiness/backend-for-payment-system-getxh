<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../functions.php';

use MongoDB\BSON\ObjectId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

// ── Admin CSRF verification ───────────────────────────────────
$submittedCsrf = $_SERVER['HTTP_X_ADMIN_CSRF'] ?? ($_POST['csrf_token'] ?? '');
$sessionCsrf   = $_SESSION['admin_csrf'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    logSecurityEvent('admin_csrf_failure', ['action' => 'payment_override']);
    jsonResponse(403, ['status' => 'error', 'message' => 'Invalid request token.']);
}

// ── Rate limit admin actions ──────────────────────────────────
enforceRateLimit('admin_action', 30, 60);

$paymentId = trim($_POST['payment_id'] ?? '');
$action    = trim($_POST['action']     ?? '');

if (!preg_match('/^[a-f0-9]{24}$/', $paymentId) || !in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid parameters.']);
}

$payment = getDB()->payments->findOne(['_id' => new ObjectId($paymentId)]);

if (!$payment) {
    jsonResponse(404, ['status' => 'error', 'message' => 'Payment not found.']);
}

if ($payment['status'] !== 'pending') {
    jsonResponse(409, ['status' => 'error', 'message' => "Payment is already '{$payment['status']}' — cannot override."]);
}

try {
    finalisePayment(
        $paymentId,
        (string)$payment['user_id'],
        (float)$payment['amount'],
        $action === 'approve'
    );
} catch (Throwable $e) {
    error_log('[admin_action] ' . $e->getMessage());
    jsonResponse(500, ['status' => 'error', 'message' => 'Action failed. Please try again.']);
}

$label = $action === 'approve' ? 'approved & wallet credited' : 'rejected';
logBharatPe('admin_override', ['payment_id' => $paymentId, 'action' => $action]);
logSecurityEvent('admin_payment_action', ['payment_id' => $paymentId, 'action' => $action]);

jsonResponse(200, ['status' => 'ok', 'message' => "Payment {$label}."]);
