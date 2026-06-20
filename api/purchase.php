<?php
// POST /api/purchase.php?player_id=1
// Headers: Idempotency-Key: <unique-string>
// Body: {"item_id": 3, "quantity": 1}

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/shop.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error(405, 'method_not_allowed', 'POST only');
}

$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;
if ($playerId <= 0) {
    json_error(400, 'invalid_player_id');
}

$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
if ($idempotencyKey === '') {
    json_error(400, 'missing_idempotency_key', 'Idempotency-Key header is required');
}

$rawBody = file_get_contents('php://input') ?: '';

// replay check: if we've seen this key before, return the stored response.
// A retried purchase has to look identical to the first one, otherwise
// the client has a bug and we 409 instead of running it.
$existing = find_idempotency_record($playerId, $idempotencyKey);
if ($existing !== null) {
    $storedHash = $existing['request_hash'];
    $thisHash = hash('sha256', $rawBody);
    if (!hash_equals($storedHash, $thisHash)) {
        json_error(409, 'idempotency_conflict', 'Idempotency-Key reused with a different request body');
    }
    http_response_code((int)$existing['response_status']);
    header('Content-Type: application/json');
    header('Idempotent-Replay: true');
    echo $existing['response_body'];
    exit;
}

$body = read_json_body();
$itemId   = isset($body['item_id'])  ? (int)$body['item_id']  : 0;
$quantity = isset($body['quantity']) ? (int)$body['quantity'] : 1;

if ($itemId <= 0) {
    json_error(400, 'invalid_item_id', 'Body must include positive integer item_id');
}

try {
    $result = run_purchase($playerId, $itemId, $quantity);
    $status = 201;
    $responseBody = json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (PurchaseFailed $e) {
    $status = $e->http;
    $responseBody = json_encode([
        'error'   => $e->errorCode,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}

// store the captured response so a retry gets the same answer
store_idempotency_record($playerId, $idempotencyKey, $rawBody, $status, $responseBody);

http_response_code($status);
header('Content-Type: application/json');
echo $responseBody;
