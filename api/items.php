<?php
// GET /api/items.php
// GET /api/items.php?type=weapon

require_once __DIR__ . '/../lib/db.php';
cors();

$type = isset($_GET['type']) ? trim($_GET['type']) : '';

if ($type !== '') {
    $stmt = db()->prepare('SELECT id, name, type, rarity, price_gold FROM items WHERE type = ? ORDER BY price_gold ASC');
    $stmt->execute([$type]);
} else {
    $stmt = db()->query('SELECT id, name, type, rarity, price_gold FROM items ORDER BY price_gold ASC');
}

$rows = $stmt->fetchAll();

// cast ints so JSON output doesn't ship numeric strings
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['price_gold'] = (int)$r['price_gold'];
}

json_response(200, ['items' => $rows]);
