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

$paymentId = trim($_POST['payment_id'] ?? '');
$action    = trim($_POST['action']     ?? '');

// Validate ObjectId format (24-char hex)
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
    jsonResponse(500, ['status' => 'error', 'message' => 'Action failed: ' . $e->getMessage()]);
}

$label = $action === 'approve' ? 'approved & wallet credited' : 'rejected';

logBharatPe('admin_override', ['payment_id' => $paymentId, 'action' => $action]);

jsonResponse(200, ['status' => 'ok', 'message' => "Payment {$label}."]);
