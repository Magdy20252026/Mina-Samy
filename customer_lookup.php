<?php
session_start();

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'customer' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$phone = trim((string) ($_GET['phone'] ?? ''));

if ($phone === '') {
    echo json_encode([
        'success' => true,
        'customer' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$lookupStmt = $pdo->prepare("
    SELECT
        id,
        name,
        phone,
        COALESCE(balance, 0) AS balance
    FROM customers
    WHERE phone = ?
    -- Keep the existing oldest-record preference used by the phone lookup logic in customers.php.
    ORDER BY id ASC
    LIMIT 1
");
$lookupStmt->execute([$phone]);
$customer = $lookupStmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo json_encode([
        'success' => true,
        'customer' => null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$customerBalance = round((float) ($customer['balance'] ?? 0), 2);

echo json_encode([
    'success' => true,
    'customer' => [
        'id' => (int) ($customer['id'] ?? 0),
        'name' => trim((string) ($customer['name'] ?? '')),
        'phone' => trim((string) ($customer['phone'] ?? '')),
        'balance' => $customerBalance,
        'balance_label' => formatMoney($customerBalance) . ' ج.م',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
