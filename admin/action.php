<?php
// ============================================================
// admin/action.php
// POST endpoint: { payment_id, action: 'approve'|'reject' }
//
// Allows admins to manually approve or reject pending payments.
// Used when: API failed, UTR is correct, user complains.
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$paymentId = (int)($_POST['payment_id'] ?? 0);
$action    = trim($_POST['action'] ?? '');

if ($paymentId < 1 || !in_array($action, ['approve', 'reject'], true)) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid parameters.']);
}

// ── Fetch the payment ─────────────────────────────────────────
$db   = getDB();
$stmt = $db->prepare(
    'SELECT id, user_id, amount, status FROM payments WHERE id = ? LIMIT 1'
);
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    jsonResponse(404, ['status' => 'error', 'message' => 'Payment not found.']);
}

if ($payment['status'] !== 'pending') {
    jsonResponse(409, [
        'status'  => 'error',
        'message' => "Payment is already '{$payment['status']}' — cannot override.",
    ]);
}

// ── Apply action ──────────────────────────────────────────────
try {
    finalisePayment(
        (int)$payment['id'],
        (int)$payment['user_id'],
        (float)$payment['amount'],
        $action === 'approve'
    );
} catch (Throwable $e) {
    jsonResponse(500, ['status' => 'error', 'message' => 'Action failed: ' . $e->getMessage()]);
}

$label = $action === 'approve' ? 'approved & wallet credited' : 'rejected';

logBharatPe('admin_override', [
    'payment_id' => $paymentId,
    'action'     => $action,
    'admin'      => $_SESSION['admin_logged_in'] ? 'admin' : 'unknown',
]);

jsonResponse(200, [
    'status'  => 'ok',
    'message' => "Payment #{$paymentId} {$label}.",
]);
