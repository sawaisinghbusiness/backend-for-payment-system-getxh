<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function getDB(): MongoDB\Database
{
    static $db = null;
    if ($db !== null) return $db;

    $uri    = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
    $dbName = getenv('MONGODB_DB')  ?: 'upi_wallet';

    $client = new MongoDB\Client($uri);
    $db     = $client->selectDatabase($dbName);

    // Ensure indexes — idempotent, safe to call every request
    $db->users->createIndex(['email' => 1], ['unique' => true]);
    $db->payments->createIndex(['utr' => 1], ['unique' => true]);
    $db->payments->createIndex(['user_id' => 1]);
    $db->payments->createIndex(['status' => 1]);
    $db->rate_limits->createIndex(['ip' => 1, 'action' => 1, 'created_at' => 1]);
    $db->rate_limits->createIndex(['action' => 1, 'identifier' => 1, 'created_at' => 1]);
    // TTL index: auto-delete rate_limit docs older than 24 h
    $db->rate_limits->createIndex(['created_at' => 1], ['expireAfterSeconds' => 86400]);

    return $db;
}
