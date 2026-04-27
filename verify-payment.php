<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

// ── Parse input ───────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($input)) $input = $_POST;

$utr    = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $input['utr'] ?? '')));
$amount = round((float)($input['amount'] ?? 0), 2);

if (strlen($utr) < 8 || strlen($utr) > 64) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid UTR.']);
}

if ($amount <= 0) {
    jsonResponse(400, ['status' => 'error', 'message' => 'Invalid amount.']);
}

// ── Duplicate check ───────────────────────────────────────────
$existing = getDB()->payments->findOne(['utr' => $utr, 'status' => 'success']);
if ($existing) {
    jsonResponse(409, ['status' => 'error', 'message' => 'This UTR is already verified.']);
}

// ── Verify with BharatPe ──────────────────────────────────────
$result = verifyWithBharatPe($utr, $amount);

// ── Save to MongoDB (for dashboard later) ─────────────────────
try {
    getDB()->payments->updateOne(
        ['utr' => $utr],
        ['$set' => [
            'utr'        => $utr,
            'amount'     => $amount,
            'status'     => $result === true ? 'success' : 'failed',
            'updated_at' => new MongoDB\BSON\UTCDateTime(),
        ],
        '$setOnInsert' => [
            'created_at' => new MongoDB\BSON\UTCDateTime(),
        ]],
        ['upsert' => true]
    );
} catch (Throwable $e) {
    error_log('[verify] DB save error: ' . $e->getMessage());
}

// ── Respond ───────────────────────────────────────────────────
if ($result === true) {
    jsonResponse(200, ['status' => 'success', 'message' => 'Payment verified.']);
}

jsonResponse(200, ['status' => 'failed', 'message' => 'Payment not found.']);
