<?php
// GET /api/player.php?id=1

require_once __DIR__ . '/../lib/db.php';
cors();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    json_error(400, 'invalid_id', 'Query param id must be a positive integer');
}

$stmt = db()->prepare('SELECT id, username, gold FROM players WHERE id = ?');
$stmt->execute([$id]);
$player = $stmt->fetch();

if ($player === false) {
    json_error(404, 'player_not_found');
}

json_response(200, [
    'id'       => (int)$player['id'],
    'username' => $player['username'],
    'gold'     => (int)$player['gold'],
]);
