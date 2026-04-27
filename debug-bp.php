<?php
// TEMPORARY DEBUG — delete after use
header('Content-Type: application/json');

$token      = getenv('BHARATPE_TOKEN')      ?: '';
$baseUrl    = getenv('BHARATPE_API_URL')    ?: '';
$merchantId = getenv('BHARATPE_MERCHANT_ID') ?: '';

$envOk = $token !== '' && $baseUrl !== '' && $merchantId !== '';

$istOffset = 19800;
$nowIst    = time() + $istOffset;
$y = (int)gmdate('Y', $nowIst);
$m = (int)gmdate('n', $nowIst);
$d = (int)gmdate('j', $nowIst);
$sDate = (gmmktime(0, 0, 0, $m, $d, $y) - $istOffset) * 1000;
$eDate = (gmmktime(23, 59, 59, $m, $d, $y) - $istOffset) * 1000;

$apiUrl = $baseUrl . '?' . http_build_query([
    'module'            => 'PAYMENT_QR',
    'merchantId'        => $merchantId,
    'sDate'             => $sDate,
    'eDate'             => $eDate,
    'pageSize'          => 10,
    'pageCount'         => 0,
    'isFromOtDashboard' => 1,
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['token: ' . $token, 'accept: application/json'],
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$raw      = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$body = json_decode($raw, true);
$txns = $body['data']['transactions'] ?? [];

echo json_encode([
    'env_vars_set'   => $envOk,
    'token_preview'  => $token ? substr($token, 0, 6) . '...' : 'MISSING',
    'merchant_id'    => $merchantId ?: 'MISSING',
    'date_ist'       => "$y-" . str_pad($m,2,'0',STR_PAD_LEFT) . "-" . str_pad($d,2,'0',STR_PAD_LEFT),
    'api_http_code'  => $httpCode,
    'curl_error'     => $curlErr ?: null,
    'txn_count'      => count($txns),
    'utrs'           => array_column($txns, 'bankReferenceNo'),
], JSON_PRETTY_PRINT);
