<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

define('BHARATPE_MAX_AGE_SECONDS',  24 * 60 * 60); // 24 hours
define('BHARATPE_TXN_SCAN_LIMIT',  20);
define('BHARATPE_MAX_RETRIES',     2);
define('BHARATPE_FAIL_THRESHOLD',  5);
define('BHARATPE_FAIL_WINDOW_MIN', 10);
define('PAYMENT_STALE_SECONDS',    3600);

// ── Structured logger ─────────────────────────────────────────
function logBharatPe(string $event, array $ctx = []): void
{
    error_log('[BharatPe] ' . json_encode(
        array_merge(['event' => $event, 'ts' => time()], $ctx),
        JSON_UNESCAPED_UNICODE
    ));
}

// ── API failure tracker ───────────────────────────────────────
function trackApiFailure(): void
{
    try {
        $db     = getDB();
        $cutoff = new UTCDateTime((time() - BHARATPE_FAIL_WINDOW_MIN * 60) * 1000);

        $db->rate_limits->insertOne([
            'ip'         => 'system',
            'action'     => 'bharatpe_api_fail',
            'identifier' => '',
            'created_at' => new UTCDateTime(),
        ]);

        $count = $db->rate_limits->countDocuments([
            'action'     => 'bharatpe_api_fail',
            'created_at' => ['$gt' => $cutoff],
        ]);

        if ($count >= BHARATPE_FAIL_THRESHOLD) {
            $email = getenv('ADMIN_ALERT_EMAIL') ?: '';
            if ($email !== '') {
                mail(
                    $email,
                    '[Alert] BharatPe API Down — ' . date('d M H:i'),
                    "BharatPe API failed {$count} times in the last " . BHARATPE_FAIL_WINDOW_MIN . " minutes.\n\nTimestamp: " . date('Y-m-d H:i:s')
                );
            }
            logBharatPe('alert_sent', ['failures' => $count]);
        }
    } catch (Throwable) {}
}

// ── Fraud detection ───────────────────────────────────────────
function markUserSuspicious(string $userId): void
{
    try {
        getDB()->users->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$set' => ['is_suspicious' => true]]
        );
        logBharatPe('user_flagged_suspicious', ['user_id' => $userId]);
    } catch (Throwable) {}
}

function isUserSuspicious(string $userId): bool
{
    try {
        $user = getDB()->users->findOne(
            ['_id' => new ObjectId($userId)],
            ['projection' => ['is_suspicious' => 1]]
        );
        return !empty($user['is_suspicious']);
    } catch (Throwable) {
        return false;
    }
}

function getUserDailyTotal(string $userId): float
{
    try {
        $start  = new UTCDateTime(strtotime('today 00:00:00 UTC') * 1000);
        $end    = new UTCDateTime(strtotime('tomorrow 00:00:00 UTC') * 1000);
        $result = getDB()->payments->aggregate([
            ['$match' => [
                'user_id'    => $userId,
                'status'     => 'success',
                'created_at' => ['$gte' => $start, '$lt' => $end],
            ]],
            ['$group' => ['_id' => null, 'total' => ['$sum' => '$amount']]],
        ])->toArray();
        return round((float)($result[0]['total'] ?? 0), 2);
    } catch (Throwable) {
        return 0.0;
    }
}

function trackMismatchAttempt(string $userId, string $utr): void
{
    if ($userId === '') return;
    try {
        $identifier = "user:{$userId}:utr:{$utr}";
        $cutoff     = new UTCDateTime((time() - 30 * 60) * 1000);
        $db         = getDB();

        $db->rate_limits->insertOne([
            'ip'         => 'system',
            'action'     => 'amount_mismatch',
            'identifier' => $identifier,
            'created_at' => new UTCDateTime(),
        ]);

        $count = $db->rate_limits->countDocuments([
            'action'     => 'amount_mismatch',
            'identifier' => $identifier,
            'created_at' => ['$gt' => $cutoff],
        ]);

        if ($count >= 3) {
            markUserSuspicious($userId);
        }
    } catch (Throwable) {}
}

// ── Auto-expire stale pending payments ────────────────────────
function expireStalePendingPayment(string $paymentId, $createdAt): bool
{
    $ts = ($createdAt instanceof UTCDateTime)
        ? $createdAt->toDateTime()->getTimestamp()
        : strtotime((string)$createdAt);

    if ((time() - $ts) < PAYMENT_STALE_SECONDS) return false;

    $result = getDB()->payments->updateOne(
        ['_id' => new ObjectId($paymentId), 'status' => 'pending'],
        ['$set' => ['status' => 'failed']]
    );

    if ($result->getModifiedCount() > 0) {
        logBharatPe('payment_expired', ['payment_id' => $paymentId]);
        return true;
    }
    return false;
}

// ── BharatPe verification ─────────────────────────────────────
function verifyWithBharatPe(string $utr, float $amount, string $userId = '')
{
    $amount = round($amount, 2);

    if (getenv('BHARATPE_TEST_MODE') === 'true') {
        return true;
    }

    $token  = trim((string)(getenv('BHARATPE_TOKEN')  ?: ''));
    $apiUrl = trim((string)(getenv('BHARATPE_API_URL') ?: ''));

    if ($token === '' || $apiUrl === '') {
        error_log('[BharatPe] BHARATPE_TOKEN or BHARATPE_API_URL not set.');
        return null;
    }

    $headers  = ['token: ' . $token, 'accept: application/json'];
    $response = null;

    for ($attempt = 1; $attempt <= BHARATPE_MAX_RETRIES; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($raw !== false && $curlErr === '' && $httpCode === 200) {
            $response = $raw;
            break;
        }

        logBharatPe('api_attempt_failed', ['attempt' => $attempt, 'http' => $httpCode, 'curl_err' => $curlErr]);
        if ($attempt < BHARATPE_MAX_RETRIES) usleep(300_000);
    }

    if ($response === null) {
        logBharatPe('api_unreachable', ['utr' => $utr]);
        trackApiFailure();
        return null;
    }

    $body = json_decode($response, true);

    if (!is_array($body) || ($body['status'] ?? null) !== true) {
        logBharatPe('api_bad_response', ['utr' => $utr]);
        trackApiFailure();
        return null;
    }

    $transactions = $body['data']['transactions'] ?? null;
    if (!is_array($transactions) || empty($transactions)) {
        logBharatPe('no_transactions', ['utr' => $utr]);
        return false;
    }

    usort($transactions, fn($a, $b) =>
        ($b['paymentTimestamp'] ?? 0) <=> ($a['paymentTimestamp'] ?? 0)
    );

    $userUtr = preg_replace('/\s+/', '', strtolower($utr));
    $scanned = 0;

    foreach ($transactions as $txn) {
        if (++$scanned > BHARATPE_TXN_SCAN_LIMIT) break;

        if (($txn['status'] ?? '') !== 'SUCCESS')      continue;
        if (($txn['type']   ?? '') !== 'PAYMENT_RECV') continue;

        $apiUtr = preg_replace('/\s+/', '', strtolower((string)($txn['bankReferenceNo'] ?? '')));
        if ($apiUtr === '' || $apiUtr !== $userUtr)    continue;

        $apiAmount = round((float)($txn['amount'] ?? 0), 2);
        if ($apiAmount <= 0)                           continue;

        if (abs($apiAmount - $amount) >= 0.01) {
            logBharatPe('amount_mismatch', ['utr' => $utr, 'expected' => $amount, 'got' => $apiAmount]);
            trackMismatchAttempt($userId, $utr);
            continue;
        }

        $txnTime = intval($txn['paymentTimestamp'] ?? 0) / 1000;
        if (abs(time() - $txnTime) > BHARATPE_MAX_AGE_SECONDS) {
            logBharatPe('stale_transaction', ['utr' => $utr]);
            continue;
        }

        // Duplicate credit guard
        $existing = getDB()->payments->findOne(['utr' => $utr, 'status' => 'success']);
        if ($existing) {
            logBharatPe('already_credited', ['utr' => $utr]);
            return false;
        }

        logBharatPe('matched', ['utr' => $utr, 'amount' => $amount]);
        return true;
    }

    logBharatPe('not_matched', ['utr' => $utr, 'amount' => $amount, 'scanned' => $scanned]);
    return false;
}

// ── Payment input validation ──────────────────────────────────
function validatePaymentInput(array $input): array
{
    $utr    = trim((string)($input['utr']     ?? ''));
    $amount = $input['amount']  ?? null;
    $userId = $input['user_id'] ?? null;

    if ($utr === '' || !preg_match('/^[A-Za-z0-9]{8,64}$/', $utr)) {
        throw new InvalidArgumentException('Invalid UTR format.');
    }
    if ($amount === null || !is_numeric($amount) || (float)$amount <= 0) {
        throw new InvalidArgumentException('Amount must be a positive number.');
    }
    if (empty($userId) || !is_string($userId)) {
        throw new InvalidArgumentException('Invalid user session.');
    }

    return [
        'utr'     => strtoupper($utr),
        'amount'  => round((float)$amount, 2),
        'user_id' => $userId,
    ];
}

// ── Insert pending payment (unique index blocks duplicate UTR) ─
function insertPendingPayment(string $userId, string $utr, float $amount): string
{
    try {
        $result = getDB()->payments->insertOne([
            'user_id'         => $userId,
            'utr'             => $utr,
            'amount'          => $amount,
            'status'          => 'pending',
            'last_checked_at' => null,
            'created_at'      => new UTCDateTime(),
        ]);
        return (string)$result->getInsertedId();
    } catch (MongoDB\Driver\Exception\BulkWriteException $e) {
        if ($e->getCode() === 11000) {
            throw new RuntimeException('This UTR has already been submitted.');
        }
        throw $e;
    }
}

// ── Finalise payment + credit wallet ─────────────────────────
function finalisePayment(string $paymentId, string $userId, float $amount, bool $success): void
{
    $db     = getDB();
    $status = $success ? 'success' : 'failed';

    $result = $db->payments->updateOne(
        ['_id' => new ObjectId($paymentId), 'status' => ['$ne' => 'success']],
        ['$set' => ['status' => $status]]
    );

    if ($success && $result->getModifiedCount() === 1) {
        $db->users->updateOne(
            ['_id' => new ObjectId($userId)],
            ['$inc' => ['balance' => round($amount, 2)]]
        );
    }
}

// ── Admin dashboard stats ─────────────────────────────────────
function getDashboardStats(): array
{
    try {
        $result = getDB()->payments->aggregate([
            ['$group' => [
                '_id'     => null,
                'total'   => ['$sum' => 1],
                'success' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'success']], 1, 0]]],
                'failed'  => ['$sum' => ['$cond' => [['$eq' => ['$status', 'failed']],  1, 0]]],
                'pending' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'pending']], 1, 0]]],
                'revenue' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'success']], '$amount', 0]]],
            ]],
        ])->toArray();

        if (empty($result)) {
            return ['total' => 0, 'success' => 0, 'failed' => 0, 'pending' => 0, 'revenue' => 0.0];
        }

        $r = $result[0];
        return [
            'total'   => (int)$r['total'],
            'success' => (int)$r['success'],
            'failed'  => (int)$r['failed'],
            'pending' => (int)$r['pending'],
            'revenue' => (float)$r['revenue'],
        ];
    } catch (Throwable) {
        return ['total' => 0, 'success' => 0, 'failed' => 0, 'pending' => 0, 'revenue' => 0.0];
    }
}

// ── Paginated payments list ───────────────────────────────────
function getPaymentsPage(
    string $statusFilter = '',
    string $utrSearch    = '',
    int    $limit        = 25,
    int    $offset       = 0
): array {
    $db     = getDB();
    $filter = [];

    if (in_array($statusFilter, ['pending', 'success', 'failed'], true)) {
        $filter['status'] = $statusFilter;
    }
    if ($utrSearch !== '') {
        $filter['utr'] = ['$regex' => $utrSearch, '$options' => 'i'];
    }

    $total  = $db->payments->countDocuments($filter);
    $cursor = $db->payments->find($filter, [
        'sort'  => ['created_at' => -1],
        'skip'  => $offset,
        'limit' => $limit,
    ]);

    $rows = [];
    foreach ($cursor as $doc) {
        $userName = '—';
        try {
            $user = $db->users->findOne(
                ['_id' => new ObjectId($doc['user_id'])],
                ['projection' => ['name' => 1]]
            );
            if ($user) $userName = (string)$user['name'];
        } catch (Throwable) {}

        $createdAt = ($doc['created_at'] instanceof UTCDateTime)
            ? $doc['created_at']->toDateTime()->format('Y-m-d H:i:s')
            : (string)($doc['created_at'] ?? '');

        $rows[] = [
            'id'         => (string)$doc['_id'],
            'user_id'    => (string)($doc['user_id'] ?? ''),
            'user_name'  => $userName,
            'utr'        => (string)$doc['utr'],
            'amount'     => (float)$doc['amount'],
            'status'     => (string)$doc['status'],
            'created_at' => $createdAt,
        ];
    }

    return ['rows' => $rows, 'total' => (int)$total];
}

// ── JSON response helper ──────────────────────────────────────
function jsonResponse(int $httpCode, array $body): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Rate limiting ─────────────────────────────────────────────
function checkRateLimit(
    string $action,
    int    $limit      = 5,
    int    $seconds    = 60,
    string $identifier = '',
    int    $idLimit    = 0
): bool {
    $db  = getDB();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($idLimit === 0) $idLimit = $limit;

    $cutoff = new UTCDateTime((time() - $seconds) * 1000);

    $count = $db->rate_limits->countDocuments([
        'ip' => $ip, 'action' => $action, 'created_at' => ['$gt' => $cutoff],
    ]);
    if ($count >= $limit) return false;

    if ($identifier !== '') {
        $count = $db->rate_limits->countDocuments([
            'action' => $action, 'identifier' => $identifier, 'created_at' => ['$gt' => $cutoff],
        ]);
        if ($count >= $idLimit) return false;
    }

    $db->rate_limits->insertOne([
        'ip'         => $ip,
        'action'     => $action,
        'identifier' => $identifier,
        'created_at' => new UTCDateTime(),
    ]);

    return true;
}

function enforceRateLimit(
    string $action,
    int    $limit      = 5,
    int    $seconds    = 60,
    string $identifier = '',
    int    $idLimit    = 0
): void {
    if (!checkRateLimit($action, $limit, $seconds, $identifier, $idLimit)) {
        jsonResponse(429, ['status' => 'error', 'message' => 'Too many attempts. Please wait.']);
    }
}

// ── Session helpers ───────────────────────────────────────────
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

        ini_set('session.save_path', '/tmp');

        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => $isHttps,
            'cookie_samesite' => 'Lax',
            'use_strict_mode' => true,
        ]);
    }
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
        } else {
            echo 'Invalid form submission. Please go back and try again.';
        }
        exit;
    }
}

function getSessionUserId(): ?string
{
    startSession();
    $id = $_SESSION['user_id'] ?? null;
    return (is_string($id) && strlen($id) === 24) ? $id : null;
}

function requireUserSession(): string
{
    $userId = getSessionUserId();
    if ($userId === null) {
        jsonResponse(401, ['status' => 'error', 'message' => 'Not authenticated. Please log in.']);
    }
    return $userId;
}

function getUserBalance(string $userId): float
{
    try {
        $user = getDB()->users->findOne(
            ['_id' => new ObjectId($userId)],
            ['projection' => ['balance' => 1]]
        );
        return round((float)($user['balance'] ?? 0), 2);
    } catch (Throwable) {
        return 0.0;
    }
}
