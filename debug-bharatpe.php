<?php
// TEMPORARY DEBUG FILE - DELETE AFTER USE
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/bootstrap.php';

$token      = trim((string)(getenv('BHARATPE_TOKEN')       ?: ''));
$baseUrl    = trim((string)(getenv('BHARATPE_API_URL')     ?: ''));
$merchantId = trim((string)(getenv('BHARATPE_MERCHANT_ID') ?: ''));

$istOffset = 19800;
$nowIst    = time() + $istOffset;
$y = (int)gmdate('Y', $nowIst);
$m = (int)gmdate('n', $nowIst);
$d = (int)gmdate('j', $nowIst);

$sDate = (gmmktime(0,  0,  0,  $m, $d, $y) - $istOffset) * 1000;
$eDate = (gmmktime(23, 59, 59, $m, $d, $y) - $istOffset) * 1000;

$apiUrl = $baseUrl . '?' . http_build_query([
    'module'            => 'PAYMENT_QR',
    'merchantId'        => $merchantId,
    'sDate'             => $sDate,
    'eDate'             => $eDate,
    'pageSize'          => 100,
    'pageCount'         => 0,
    'isFromOtDashboard' => 1,
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['token: ' . $token, 'accept: application/json'],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo json_encode([
    'http_code'    => $code,
    'curl_error'   => $err,
    'raw_response' => json_decode($raw, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
