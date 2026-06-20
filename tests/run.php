<?php
// Quick test runner. Run with: php tests/run.php
// Each test resets the seeded data before running, so tests are independent
// and can re-run as many times as I want without piling up junk in the DB.

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/shop.php';

$tests = 0;
$failures = 0;

function it(string $name, callable $fn): void {
    global $tests, $failures;
    $tests++;
    try {
        resetTestData();
        $fn();
        echo "  PASS  $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL  $name\n";
        echo "        " . $e->getMessage() . "\n";
    }
}

function assert_eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        throw new Exception($msg !== '' ? $msg : "Expected $exp, got $act");
    }
}

function assert_throws(string $expectedClass, callable $fn, string $msg = ''): void {
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $expectedClass) {
            return;
        }
        throw new Exception("Expected $expectedClass, got " . get_class($e) . ": " . $e->getMessage());
    }
    throw new Exception($msg !== '' ? $msg : "Expected $expectedClass, no exception thrown");
}

/**
 * Reset to the seeded state. Cheaper than a full schema reload and works
 * whether the previous test passed or failed.
 * Gold values mirror the INSERTs in schema.sql.
 */
function resetTestData(): void {
    $pdo = db();
    $pdo->exec('DELETE FROM idempotency_log');
    $pdo->exec('DELETE FROM inventory');
    $pdo->exec('UPDATE players SET gold = 500  WHERE id = 1');
    $pdo->exec('UPDATE players SET gold = 1200 WHERE id = 2');
    $pdo->exec('UPDATE players SET gold = 75   WHERE id = 3');
}

it('purchase deducts gold and creates inventory entry', function () {
    $result = run_purchase(playerId: 1, itemId: 1, quantity: 2); // 2x Iron Sword @ 100
    assert_eq(200, $result['purchase']['gold_spent']);
    assert_eq(300, $result['purchase']['gold_remaining']);
    assert_eq(2,   $result['purchase']['inventory_total_for_item']);
});

it('second purchase stacks quantity', function () {
    run_purchase(playerId: 1, itemId: 1, quantity: 2);                // first buy: 2 swords
    $result = run_purchase(playerId: 1, itemId: 1, quantity: 1);      // second buy: 1 more
    assert_eq(3,   $result['purchase']['inventory_total_for_item']);
    assert_eq(200, $result['purchase']['gold_remaining']);
});

it('insufficient gold rejected', function () {
    // cael has 75 gold, Stormcaller Staff costs 1500
    assert_throws(PurchaseFailed::class, function () {
        run_purchase(playerId: 3, itemId: 7, quantity: 1);
    });
});

it('missing player', function () {
    assert_throws(PurchaseFailed::class, function () {
        run_purchase(playerId: 99999, itemId: 1, quantity: 1);
    });
});

it('missing item', function () {
    assert_throws(PurchaseFailed::class, function () {
        run_purchase(playerId: 1, itemId: 99999, quantity: 1);
    });
});

it('invalid quantity rejected', function () {
    assert_throws(PurchaseFailed::class, function () {
        run_purchase(playerId: 1, itemId: 1, quantity: 0);
    });
});

it('idempotency replay returns stored response', function () {
    $key = 'test-key-' . uniqid('', true);
    store_idempotency_record(1, $key, '{"item_id":1,"quantity":1}', 201, '{"purchase":{"x":1}}');

    $found = find_idempotency_record(1, $key);
    assert_eq(false, $found === null, 'record should exist');
    assert_eq(201, (int)$found['response_status']);
    assert_eq('{"purchase":{"x":1}}', $found['response_body']);
});

// final clean so the next run starts fresh and the live app sees seed values
resetTestData();

echo "\n$tests run, $failures failed\n";
exit($failures === 0 ? 0 : 1);
