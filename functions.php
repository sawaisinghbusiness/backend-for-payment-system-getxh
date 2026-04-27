<?php
// ============================================================
// Core business logic
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/db.php';

define('BHARATPE_MAX_AGE_SECONDS',  30 * 60); // 30 min freshness window
define('BHARATPE_TXN_SCAN_LIMIT',  20);       // max transactions to inspect
define('BHARATPE_MAX_RETRIES',     2);        // cURL retry attempts
define('BHARATPE_FAIL_THRESHOLD',  5);        // failures before alert email
define('BHARATPE_FAIL_WINDOW_MIN', 10);       // failure count window (minutes)
define('PAYMENT_STALE_SECONDS',    3600);     // 60 min → auto-expire pending

// ── Structured logger ────────────────────────────────────────────────────────
function logBharatPe(string $event, array $ctx = []): void
{
    error_log('[BharatPe] ' . json_encode(
        array_merge(['event' => $event, 'ts' => time()], $ctx),
        JSON_UNESCAPED_UNICODE
    ));
}

// ── API failure tracker + email alert ───────────────────────────────────────
function trackApiFailure(): void
{
    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO rate_limits (ip, action, identifier) VALUES ('system', 'bharatpe_api_fail', '')"
        )->execute();

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM rate_limits
             WHERE action = 'bharatpe_api_fail'
             AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([BHARATPE_FAIL_WINDOW_MIN]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= BHARATPE_FAIL_THRESHOLD) {
            $email = getenv('ADMIN_ALERT_EMAIL') ?: '';
            if ($email !== '') {
                mail(
                    $email,
                    '[Alert] BharatPe API Down — ' . date('d M H:i'),
                    "BharatPe API failed {$count} times in the last " . BHARATPE_FAIL_WINDOW_MIN . " minutes.\n\nTimestamp: " . date('Y-m-d H:i:s')
                );
            }
            logBharatPe('alert_sent', ['failures' => $count, 'window_min' => BHARATPE_FAIL_WINDOW_MIN]);
        }
    } catch (Throwable) {
        // Never let alert tracking crash the main flow
    }
}

// ── Fraud detection ──────────────────────────────────────────────────────────

function markUserSuspicious(int $userId): void
{
    try {
        getDB()->prepare(
            'UPDATE users SET is_suspicious = 1 WHERE id = ?'
        )->execute([$userId]);
        logBharatPe('user_flagged_suspicious', ['user_id' => $userId]);
    } catch (Throwable) {}
}

function isUserSuspicious(int $userId): bool
{
    $stmt = getDB()->prepare(
        'SELECT is_suspicious FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Returns the total amount of successful payments by a user today (UTC).
 */
function getUserDailyTotal(int $userId): float
{
    $stmt = getDB()->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM payments
         WHERE user_id = ? AND status = 'success' AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([$userId]);
    return round((float)$stmt->fetchColumn(), 2);
}

/**
 * Track amount-mismatch fraud attempts per user+UTR.
 * After 3 mismatches the user is flagged suspicious.
 */
function trackMismatchAttempt(int $userId, string $utr): void
{
    if ($userId === 0) return;

    try {
        $identifier = "user:{$userId}:utr:{$utr}";

        getDB()->prepare(
            "INSERT INTO rate_limits (ip, action, identifier) VALUES ('system', 'amount_mismatch', ?)"
        )->execute([$identifier]);

        $stmt = getDB()->prepare(
            "SELECT COUNT(*) FROM rate_limits
             WHERE action = 'amount_mismatch' AND identifier = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        $stmt->execute([$identifier]);

        if ((int)$stmt->fetchColumn() >= 3) {
            markUserSuspicious($userId);
        }
    } catch (Throwable) {}
}

// ── Auto-expire stale pending payments ───────────────────────────────────────
function expireStalePendingPayment(int $paymentId, string $createdAt): bool
{
    if ((time() - strtotime($createdAt)) < PAYMENT_STALE_SECONDS) {
        return false;
    }

    $stmt = getDB()->prepare(
        "UPDATE payments SET status = 'failed' WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$paymentId]);

    if ($stmt->rowCount() > 0) {
        logBharatPe('payment_expired', ['payment_id' => $paymentId]);
        return true;
    }

    return false;
}

/**
 * Verify a UPI payment against the BharatPe merchant transaction API.
 *
 * API response shape expected:
 *   { "status": true, "data": { "transactions": [ { ... } ] } }
 *
 * UTR field used: bankReferenceNo  (NOT internalUtr, NOT id)
 *
 * Env vars required:
 *   BHARATPE_API_URL   — full endpoint URL
 *   BHARATPE_TOKEN     — bearer token from merchant dashboard
 *
 * Optional:
 *   BHARATPE_TEST_MODE — set "true" to bypass API during local dev
 *
 * @param  string $utr    bankReferenceNo submitted by the user.
 * @param  float  $amount Amount submitted by the user (rounded to 2 dp).
 * @return bool   true only when a fresh, matching SUCCESS/PAYMENT_RECV transaction exists.
 */
/**
 * Returns:
 *   true  — payment verified
 *   false — payment not found / rejected
 *   null  — API unreachable, caller should treat as pending and retry
 */
function verifyWithBharatPe(string $utr, float $amount, int $userId = 0): bool|null
{
    $amount = round($amount, 2); // normalise once at entry point

    // ── Test mode bypass ──────────────────────────────────────
    if (getenv('BHARATPE_TEST_MODE') === 'true') {
        return true;
    }

    $token  = trim((string)(getenv('BHARATPE_TOKEN')  ?: ''));
    $apiUrl = trim((string)(getenv('BHARATPE_API_URL') ?: ''));

    if ($token === '' || $apiUrl === '') {
        error_log('[BharatPe] BHARATPE_TOKEN or BHARATPE_API_URL not set — aborting.');
        return null;
    }

    $headers = [
        'token: ' . $token,
        'accept: application/json',
    ];

    // ── cURL with retry (max 2 attempts, 5 s timeout each) ───
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

        logBharatPe('api_attempt_failed', [
            'attempt'  => $attempt,
            'http'     => $httpCode,
            'curl_err' => $curlErr,
            'utr'      => $utr,
        ]);

        if ($attempt < BHARATPE_MAX_RETRIES) {
            usleep(300_000);
        }
    }

    // API unreachable → track failure + return null so payment stays pending
    if ($response === null) {
        logBharatPe('api_unreachable', ['utr' => $utr, 'retries' => BHARATPE_MAX_RETRIES]);
        trackApiFailure();
        return null;
    }

    // ── Decode & validate structure ───────────────────────────
    $body = json_decode($response, true);

    if (!is_array($body)) {
        logBharatPe('invalid_json', ['utr' => $utr]);
        trackApiFailure();
        return null;
    }

    if (($body['status'] ?? null) !== true) {
        logBharatPe('api_status_false', ['utr' => $utr, 'body' => substr($response, 0, 200)]);
        trackApiFailure();
        return null;
    }

    $transactions = $body['data']['transactions'] ?? null;

    if (!is_array($transactions) || empty($transactions)) {
        logBharatPe('no_transactions', ['utr' => $utr]);
        return false;
    }

    // ── Sort newest-first so freshest match wins ──────────────
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

        // round() both sides eliminates float representation drift (₹100 vs ₹100.0000001)
        $apiAmount = round((float)($txn['amount'] ?? 0), 2);
        if ($apiAmount <= 0)                           continue;

        if (abs($apiAmount - $amount) >= 0.01) {
            logBharatPe('amount_mismatch', [
                'utr'      => $utr,
                'expected' => $amount,
                'got'      => $apiAmount,
                'user_id'  => $userId,
            ]);
            trackMismatchAttempt($userId, $utr);
            continue;
        }

        // abs() covers both past timestamps AND slight server clock drift forward
        $txnTime = intval($txn['paymentTimestamp'] ?? 0) / 1000;
        if (abs(time() - $txnTime) > 1800) {
            logBharatPe('stale_transaction', ['utr' => $utr, 'age_sec' => abs(time() - $txnTime)]);
            continue;
        }

        // Duplicate credit guard
        $stmt = getDB()->prepare(
            "SELECT id FROM payments WHERE utr = ? AND status = 'success' LIMIT 1"
        );
        $stmt->execute([$utr]);
        if ($stmt->fetch()) {
            logBharatPe('already_credited', ['utr' => $utr]);
            return false;
        }

        logBharatPe('matched', ['utr' => $utr, 'amount' => $amount]);
        return true;
    }

    logBharatPe('not_matched', ['utr' => $utr, 'amount' => $amount, 'scanned' => $scanned]);
    return false;
}

/**
 * Validate and sanitize the incoming payment payload.
 *
 * @param  array $input  Raw $_POST or json-decoded body.
 * @return array{utr:string, amount:float, user_id:int}
 * @throws InvalidArgumentException on validation failure.
 */
function validatePaymentInput(array $input): array
{
    $utr     = trim((string)($input['utr']     ?? ''));
    $amount  = $input['amount']  ?? null;
    $userId  = $input['user_id'] ?? null;

    if ($utr === '' || !preg_match('/^[A-Za-z0-9]{8,64}$/', $utr)) {
        throw new InvalidArgumentException('Invalid UTR format.');
    }

    if ($amount === null || !is_numeric($amount) || (float)$amount <= 0) {
        throw new InvalidArgumentException('Amount must be a positive number.');
    }

    if ($userId === null || !filter_var($userId, FILTER_VALIDATE_INT) || (int)$userId < 1) {
        throw new InvalidArgumentException('Invalid user_id.');
    }

    return [
        'utr'     => strtoupper($utr),
        'amount'  => round((float)$amount, 2),
        'user_id' => (int)$userId,
    ];
}

/**
 * Insert a new payment record with status = pending.
 *
 * Uses a DB transaction + SELECT FOR UPDATE so two simultaneous requests
 * with the same UTR cannot both pass the duplicate check (race condition).
 *
 * @throws RuntimeException if the UTR already exists.
 * @return int Inserted payment ID.
 */
function insertPendingPayment(int $userId, string $utr, float $amount): int
{
    $db = getDB();
    $db->beginTransaction();

    try {
        // FOR UPDATE locks the row (or the gap) — second concurrent request blocks here
        // until this transaction commits, then sees the row and throws duplicate error.
        $stmt = $db->prepare(
            'SELECT id FROM payments WHERE utr = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$utr]);

        if ($stmt->fetch()) {
            $db->rollBack();
            throw new RuntimeException('Duplicate UTR: this transaction has already been submitted.');
        }

        $stmt = $db->prepare(
            'INSERT INTO payments (user_id, utr, amount, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $utr, $amount, 'pending']);
        $id = (int)$db->lastInsertId();

        $db->commit();
        return $id;

    } catch (RuntimeException $e) {
        throw $e; // already rolled back above
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Finalise a payment: update status and credit wallet on success.
 *
 * The UPDATE uses `AND status != 'success'` so a duplicate call
 * (race condition, API retry, double click) affects 0 rows — and
 * rowCount() === 0 means the wallet credit is skipped entirely.
 * This makes the function safe to call more than once for the same payment.
 */
function finalisePayment(int $paymentId, int $userId, float $amount, bool $success): void
{
    $db     = getDB();
    $status = $success ? 'success' : 'failed';

    $db->beginTransaction();
    try {
        // Only update if not already finalised — prevents double credit
        $stmt = $db->prepare(
            "UPDATE payments SET status = ? WHERE id = ? AND status != 'success'"
        );
        $stmt->execute([$status, $paymentId]);

        // rowCount() === 0 means payment was already marked success by a concurrent request
        if ($success && $stmt->rowCount() === 1) {
            $db->prepare(
                'UPDATE users SET balance = balance + ? WHERE id = ?'
            )->execute([round($amount, 2), $userId]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Fetch aggregate stats for the admin dashboard.
 */
function getDashboardStats(): array
{
    $db = getDB();

    $row = $db->query(
        "SELECT
            COUNT(*)                                        AS total,
            SUM(status = 'success')                         AS success,
            SUM(status = 'failed')                          AS failed,
            SUM(status = 'pending')                         AS pending,
            COALESCE(SUM(CASE WHEN status='success' THEN amount END), 0) AS revenue
         FROM payments"
    )->fetch();

    return [
        'total'   => (int)$row['total'],
        'success' => (int)$row['success'],
        'failed'  => (int)$row['failed'],
        'pending' => (int)$row['pending'],
        'revenue' => (float)$row['revenue'],
    ];
}

/**
 * Fetch paginated payment rows for the admin payments table.
 *
 * @param  string $statusFilter  '' | 'pending' | 'success' | 'failed'
 * @param  string $utrSearch     Partial UTR to search.
 * @param  int    $limit
 * @param  int    $offset
 * @return array{rows: array, total: int}
 */
function getPaymentsPage(
    string $statusFilter = '',
    string $utrSearch = '',
    int $limit = 25,
    int $offset = 0
): array {
    $db     = getDB();
    $where  = [];
    $params = [];

    if (in_array($statusFilter, ['pending', 'success', 'failed'], true)) {
        $where[]  = 'p.status = ?';
        $params[] = $statusFilter;
    }

    if ($utrSearch !== '') {
        $where[]  = 'p.utr LIKE ?';
        $params[] = '%' . $utrSearch . '%';
    }

    $whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $db->prepare("SELECT COUNT(*) FROM payments p {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $db->prepare(
        "SELECT p.id, p.user_id, u.name AS user_name, p.utr, p.amount, p.status, p.created_at
         FROM payments p
         LEFT JOIN users u ON u.id = p.user_id
         {$whereSQL}
         ORDER BY p.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute($params);

    return ['rows' => $stmt->fetchAll(), 'total' => $total];
}

/**
 * Return a JSON response and terminate.
 */
function jsonResponse(int $httpCode, array $body): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Rate limiting
// ============================================================

/**
 * Check if an action is within the allowed rate limit, and record the attempt.
 *
 * Two axes are checked independently:
 *   1. IP address — prevents a single machine from spamming.
 *   2. identifier  — prevents distributed attacks against a specific target
 *                    (e.g. same email from many IPs, same UTR from many IPs).
 *
 * @param string $action      Short key, e.g. 'login', 'verify_payment'.
 * @param int    $limit       Max allowed attempts in the window.
 * @param int    $seconds     Window size in seconds.
 * @param string $identifier  Optional secondary key (email, UTR, …).
 * @param int    $idLimit     Max allowed attempts per identifier (default = $limit).
 *
 * @return bool  true = allowed, false = blocked.
 */
function checkRateLimit(
    string $action,
    int    $limit   = 5,
    int    $seconds = 60,
    string $identifier = '',
    int    $idLimit    = 0
): bool {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if ($idLimit === 0) {
        $idLimit = $limit;
    }

    $cutoff = date('Y-m-d H:i:s', time() - $seconds);

    // ── 1. Check by IP ────────────────────────────────────────
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM rate_limits
         WHERE ip = ? AND action = ? AND created_at > ?'
    );
    $stmt->execute([$ip, $action, $cutoff]);

    if ((int)$stmt->fetchColumn() >= $limit) {
        return false;
    }

    // ── 2. Check by identifier (if provided) ─────────────────
    if ($identifier !== '') {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM rate_limits
             WHERE action = ? AND identifier = ? AND created_at > ?'
        );
        $stmt->execute([$action, $identifier, $cutoff]);

        if ((int)$stmt->fetchColumn() >= $idLimit) {
            return false;
        }
    }

    // ── 3. Record this attempt ────────────────────────────────
    $db->prepare(
        'INSERT INTO rate_limits (ip, action, identifier) VALUES (?, ?, ?)'
    )->execute([$ip, $action, $identifier]);

    // ── 4. Probabilistic cleanup (1% chance) — keeps table small ──
    if (random_int(1, 100) === 1) {
        $db->prepare(
            "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
        )->execute();
    }

    return true;
}

/**
 * Terminate with a 429 JSON response if the rate limit is exceeded.
 */
function enforceRateLimit(
    string $action,
    int    $limit      = 5,
    int    $seconds    = 60,
    string $identifier = '',
    int    $idLimit    = 0
): void {
    if (!checkRateLimit($action, $limit, $seconds, $identifier, $idLimit)) {
        jsonResponse(429, [
            'status'  => 'error',
            'message' => 'Too many attempts. Please wait a moment and try again.',
        ]);
    }
}

// ============================================================
// User auth functions
// ============================================================

/**
 * Register a new user. Returns the new user's ID.
 *
 * @throws InvalidArgumentException on bad input.
 * @throws RuntimeException if email already exists.
 */
function registerUser(string $name, string $email, string $password): int
{
    $name  = trim($name);
    $email = strtolower(trim($email));

    if ($name === '' || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Name must be 1–100 characters.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
        throw new InvalidArgumentException('Invalid email address.');
    }

    if (mb_strlen($password) < 8) {
        throw new InvalidArgumentException('Password must be at least 8 characters.');
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
        throw new RuntimeException('An account with this email already exists.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $db->prepare(
        'INSERT INTO users (name, email, password, balance) VALUES (?, ?, ?, 0.00)'
    );
    $stmt->execute([$name, $email, $hash]);

    return (int)$db->lastInsertId();
}

/**
 * Verify credentials and return the user row, or false on failure.
 *
 * @return array{id:int, name:string, email:string, balance:float}|false
 */
function loginUser(string $email, string $password): array|false
{
    $email = strtolower(trim($email));

    if ($email === '' || $password === '') {
        return false;
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT id, name, email, password, balance FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    return [
        'id'      => (int)$user['id'],
        'name'    => $user['name'],
        'email'   => $user['email'],
        'balance' => (float)$user['balance'],
    ];
}

/**
 * Start session (if not already started).
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => true,   // only send over HTTPS
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
        ]);
    }
}

/**
 * Return (and lazily create) the CSRF token for the current session.
 * Always call startSession() before this.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the CSRF token from POST or X-CSRF-Token header.
 * Terminates with 403 on failure.
 */
function verifyCsrf(): void
{
    $submitted = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        // JSON or HTML response based on Accept header
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
        } else {
            echo 'Invalid or expired form submission. Please go back and try again.';
        }
        exit;
    }
}

/**
 * Return the logged-in user's ID from session, or null if not logged in.
 */
function getSessionUserId(): ?int
{
    startSession();
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) && $id > 0 ? $id : null;
}

/**
 * Require a logged-in user session for JSON API endpoints.
 * Terminates with 401 if not authenticated.
 *
 * @return int Authenticated user ID.
 */
function requireUserSession(): int
{
    $userId = getSessionUserId();
    if ($userId === null) {
        jsonResponse(401, ['status' => 'error', 'message' => 'Not authenticated. Please log in.']);
    }
    return $userId;
}

/**
 * Fetch a user's current balance.
 */
function getUserBalance(int $userId): float
{
    $stmt = getDB()->prepare('SELECT balance FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return (float)($stmt->fetchColumn() ?: 0);
}
