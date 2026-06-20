<?php
// Database connection and a couple of small HTTP helpers I use everywhere.
// PDO singleton so any request only opens one connection.

require_once __DIR__ . '/../config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function json_response(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $code, string $message = ''): void {
    json_response($status, ['error' => $code, 'message' => $message]);
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error(400, 'invalid_json', 'Request body is not valid JSON');
    }
    return $data;
}

// CORS for the JS frontend served from the same Apache, but harmless here.
function cors(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Idempotency-Key');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit;
    }
}
