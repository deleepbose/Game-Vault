<?php
// The purchase function. This is the bit worth reading.
//
// Idempotency: a retried POST with the same Idempotency-Key returns the
// original response and never re-charges the player. The key is stored
// alongside a SHA-256 of the request body, so a reused key with a different
// body is rejected as 409 (client bug).
//
// The whole purchase runs inside a transaction so a half-applied state
// is impossible.

require_once __DIR__ . '/db.php';

class PurchaseFailed extends RuntimeException {
    public string $errorCode;
    public int $http;

    public function __construct(string $errorCode, string $msg, int $http) {
        parent::__construct($msg);
        $this->errorCode = $errorCode;
        $this->http = $http;
    }
}

function find_idempotency_record(int $playerId, string $key): ?array {
    $stmt = db()->prepare('SELECT * FROM idempotency_log WHERE player_id = ? AND idempotency_key = ?');
    $stmt->execute([$playerId, $key]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function store_idempotency_record(
    int $playerId,
    string $key,
    string $requestBody,
    int $status,
    string $responseBody
): void {
    $stmt = db()->prepare(
        'INSERT INTO idempotency_log (player_id, idempotency_key, request_hash, response_status, response_body, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $playerId,
        $key,
        hash('sha256', $requestBody),
        $status,
        $responseBody,
    ]);
}

/**
 * Run a purchase. Returns the response array to send back to the client.
 *
 * @throws PurchaseFailed on any expected error (insufficient gold, missing item, etc.)
 */
function run_purchase(int $playerId, int $itemId, int $quantity): array {
    if ($quantity <= 0) {
        throw new PurchaseFailed('invalid_quantity', 'Quantity must be a positive integer', 400);
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        // lock the player row so two concurrent purchases can't both pass the gold check
        $stmt = $pdo->prepare('SELECT id, username, gold FROM players WHERE id = ? FOR UPDATE');
        $stmt->execute([$playerId]);
        $player = $stmt->fetch();
        if ($player === false) {
            throw new PurchaseFailed('player_not_found', "Player {$playerId} does not exist", 404);
        }

        $stmt = $pdo->prepare('SELECT id, name, price_gold FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if ($item === false) {
            throw new PurchaseFailed('item_not_found', "Item {$itemId} does not exist", 404);
        }

        $totalCost = (int)$item['price_gold'] * $quantity;
        if ((int)$player['gold'] < $totalCost) {
            throw new PurchaseFailed(
                'insufficient_gold',
                "Player has {$player['gold']} gold, purchase costs {$totalCost}",
                402
            );
        }

        // deduct gold
        $stmt = $pdo->prepare('UPDATE players SET gold = gold - ? WHERE id = ?');
        $stmt->execute([$totalCost, $playerId]);

        // upsert the inventory row. I avoided ON DUPLICATE KEY UPDATE because
        // the VALUES() reference syntax is deprecated in MySQL 8.0.20+. A plain
        // SELECT-then-INSERT-or-UPDATE is portable and easy to read. Safe under
        // the player row lock above, which serialises purchases per player.
        $stmt = $pdo->prepare('SELECT quantity FROM inventory WHERE player_id = ? AND item_id = ?');
        $stmt->execute([$playerId, $itemId]);
        $existing = $stmt->fetchColumn();

        if ($existing === false) {
            $stmt = $pdo->prepare(
                'INSERT INTO inventory (player_id, item_id, quantity, acquired_at)
                 VALUES (?, ?, ?, NOW())'
            );
            $stmt->execute([$playerId, $itemId, $quantity]);
            $newQty = $quantity;
        } else {
            $stmt = $pdo->prepare(
                'UPDATE inventory SET quantity = quantity + ?
                 WHERE player_id = ? AND item_id = ?'
            );
            $stmt->execute([$quantity, $playerId, $itemId]);
            $newQty = (int)$existing + $quantity;
        }

        $goldRemaining = (int)$player['gold'] - $totalCost;

        $pdo->commit();

        return [
            'purchase' => [
                'player_id'                => $playerId,
                'item_id'                  => (int)$item['id'],
                'item_name'                => $item['name'],
                'quantity_purchased'       => $quantity,
                'gold_spent'               => $totalCost,
                'gold_remaining'           => $goldRemaining,
                'inventory_total_for_item' => $newQty,
            ],
        ];
    } catch (PurchaseFailed $e) {
        $pdo->rollBack();
        throw $e;
    } catch (Throwable $e) {
        $pdo->rollBack();
        // wrap unexpected errors so callers always see PurchaseFailed
        throw new PurchaseFailed('internal_error', $e->getMessage(), 500);
    }
}
