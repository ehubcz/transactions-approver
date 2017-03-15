<?php

declare(strict_types=1);

/**
 * eHUB transaction approve script sample.
 *
 * Sample project showing the implementation of the transaction approval using eHUB API.
 *
 * PHP version 7.1
 *
 * LICENSE: "MIT", "BSD-3-Clause", "GPL-2.0", "GPL-3.0"
 *
 * @author     Tomas Jacik <tomas@jacik.cz>
 * @copyright  2017 eHUB.cz s.r.o.
 */

require __DIR__ . '/vendor/autoload.php';

// ---------------------------------------------------------------------------------------------------------------------
// YOUR CONNECTION SETTINGS - INSERT PROPER VALUES HERE
// ---------------------------------------------------------------------------------------------------------------------

$dbDsn = 'mysql:dbname=testdb;host=127.0.0.1';
$dbUser = 'dbuser';
$dbPassword = 'xxx';

$apiKey = 'xxx';

// ---------------------------------------------------------------------------------------------------------------------
// DOWNLOAD ALL PENDING TRANSACTION FROM EHUB API USING GUZZLE HTTP CLIENT
// ---------------------------------------------------------------------------------------------------------------------

$client = new GuzzleHttp\Client;

$queryParameters = [
    'apiKey' => $apiKey,
    // Limit fields to few needed for transaction approval
    'fields' => 'id,canChangeStatus,totalCost,orderId',
    // List only transactions pending approval
    'status' => 'pending',
    // Process 100 transactions at once (API maximum for one page)
    'perPage' => 100,
];
// You can use https://private-anon-0540888989-ehub.apiary-mock.com/v2/transactions for testing
$url = 'https://api.ehub.cz/v2/transactions?' . http_build_query($queryParameters);

try {
    $response = $client->request('GET', $url);
} catch (GuzzleHttp\Exception\ClientException $e) {
    if ($e->getCode() === 401) {
        die('Error code 401 was returned, you probably entered invalid $api_key.' . PHP_EOL);
    }
    // Another non-expected error occurred.
    throw $e;
}

// Decode returned data
$data = json_decode((string) $response->getBody());

// Sort data by orderId and store order ids for later use
$orderIds = $transactionsByOrderId = [];
foreach ($data->transactions as $transaction) {
    if (!$transaction->orderId) {
        // Transaction doesn't have orderId, we need to identify it manually or log somewhere
        continue;
    }

    $orderIds[] = $transaction->orderId;
    // We assume there aren't transactions with same orderId
    $transactionsByOrderId[$transaction->orderId] = $transaction;
}

// ---------------------------------------------------------------------------------------------------------------------
// CONNECT TO DATABASE AND VALIDATE DOWNLOADED TRANSACTIONS
// ---------------------------------------------------------------------------------------------------------------------

try {
    $pdo = new PDO($dbDsn, $dbUser, $dbPassword);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Escape orderIds for SQL
$orderIds = array_map([$pdo, 'quote'], $orderIds);

$result = $pdo->query("
    SELECT
        `order_id`, `total_cost`, `status`
    FROM
        `orders`
    WHERE
        `order_id` IN (" . implode(',', $orderIds) . ") AND
        `status` IN ('paid', 'canceled')
")->fetchAll(PDO::FETCH_OBJ);

$statusChanges = [];
foreach ($result as $row) {
    $statusChange = [
        'status' => $row->status === 'paid' ? 'approved' : 'declined',
    ];
    if ((float) $row->total_cost !== (float) $transactionsByOrderId[$row->order_id]->totalCost) {
        // Fix totalCost if needed
        $statusChange['totalCost'] = $row->total_cost;
    }
    $statusChanges[$transactionsByOrderId[$row->order_id]->id] = $statusChange;
}

// ---------------------------------------------------------------------------------------------------------------------
// UPDATE TRANSACTIONS STATES ON API
// ---------------------------------------------------------------------------------------------------------------------

foreach ($statusChanges as $id => $change) {
    try {
        // You can use https://private-anon-0540888989-ehub.apiary-mock.com/v2/transactions for testing
        $url = "https://api.ehub.cz/v2/transactions/$id?apiKey=$apiKey";
        $response = $client->request('PATCH', $url, ['json' => $change]);
    } catch (GuzzleHttp\Exception\ClientException $e) {
        // Maybe you want to store exception instead and review them at the end of script
        throw $e;
    }

    // Decode returned data
    $data = json_decode((string) $response->getBody());
    if ($data->code === 200) {
        // Update was successfull, maybe we should log it to our db?
    }
}
