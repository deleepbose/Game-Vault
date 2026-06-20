<?php
// GET /api/inventory.php?player_id=1&page=1&per_page=20

require_once __DIR__ . '/../lib/db.php';
cors();

$playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;
$page     = isset($_GET['page'])      ? max(1, (int)$_GET['page']) : 1;
$perPage  = isset($_GET['per_page'])  ? max(1, min(100, (int)$_GET['per_page'])) : 20;

if ($playerId <= 0) {
    json_error(400, 'invalid_player_id');
}

// confirm the player exists, otherwise return 404 instead of an empty list
$stmt = db()->prepare('SELECT id FROM players WHERE id = ?');
$stmt->execute([$playerId]);
if ($stmt->fetch() === false) {
    json_error(404, 'player_not_found');
}

$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    'SELECT i.id AS item_id, i.name, i.type, i.rarity, inv.quantity, inv.acquired_at
     FROM inventory inv
     JOIN items i ON i.id = inv.item_id
     WHERE inv.player_id = ?
     ORDER BY inv.acquired_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->bindValue(1, $playerId, PDO::PARAM_INT);
$stmt->bindValue(2, $perPage, PDO::PARAM_INT);
$stmt->bindValue(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

foreach ($rows as &$r) {
    $r['item_id'] = (int)$r['item_id'];
    $r['quantity'] = (int)$r['quantity'];
}

// total for pagination header
$stmt = db()->prepare('SELECT COUNT(*) FROM inventory WHERE player_id = ?');
$stmt->execute([$playerId]);
$total = (int)$stmt->fetchColumn();

json_response(200, [
    'player_id' => $playerId,
    'page'      => $page,
    'per_page'  => $perPage,
    'total'     => $total,
    'entries'   => $rows,
]);
