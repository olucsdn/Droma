<?php
/**
 * Endpoint leve para consulta de vencimentos próximos (usado pelo módulo de Notificações Push).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';

$userId = Security::requireAuth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM transactions
         WHERE user_id = ? AND visivel = 1 AND type = 'expense' AND status = 'pending'
           AND id NOT IN (SELECT transaction_id FROM installments)
           AND COALESCE(due_date, date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY))
        +
        (SELECT COUNT(*) FROM installments i
         JOIN transactions t ON t.id = i.transaction_id
         JOIN credit_cards c ON c.id = i.credit_card_id
         WHERE c.user_id = ? AND t.visivel = 1 AND i.status = 'pending'
           AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)) AS count_due,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions
         WHERE user_id = ? AND visivel = 1 AND type = 'expense' AND status = 'pending'
           AND id NOT IN (SELECT transaction_id FROM installments)
           AND COALESCE(due_date, date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY))
        +
        (SELECT COALESCE(SUM(i.amount), 0) FROM installments i
         JOIN transactions t ON t.id = i.transaction_id
         JOIN credit_cards c ON c.id = i.credit_card_id
         WHERE c.user_id = ? AND t.visivel = 1 AND i.status = 'pending'
           AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)) AS total_due
");
$stmt->execute([$userId, $userId, $userId, $userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['count_due' => 0, 'total_due' => 0];

echo json_encode([
    'count' => (int) ($row['count_due'] ?? 0),
    'total' => round((float) ($row['total_due'] ?? 0), 2),
]);
