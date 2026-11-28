<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::validateCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Requisição não autorizada.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'ID inválido.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $chk = $pdo->prepare("SELECT transfer_id, goal_id, type, amount FROM transactions WHERE id = ? AND user_id = ? AND visivel = 0 FOR UPDATE");
    $chk->execute([$id, $userId]);
    $tx = $chk->fetch(PDO::FETCH_ASSOC);

    if ($tx) {
        $transferId = $tx['transfer_id'] ?? null;
        $goalId     = !empty($tx['goal_id']) ? (int)$tx['goal_id'] : null;
        $txAmount   = (float)$tx['amount'];
        $txType     = $tx['type'];

        if (!empty($transferId)) {
            $stmt = $pdo->prepare("UPDATE transactions SET visivel = 1 WHERE transfer_id = ? AND user_id = ? AND visivel = 0");
            $stmt->execute([$transferId, $userId]);
        } else {
            $stmt = $pdo->prepare("UPDATE transactions SET visivel = 1 WHERE id = ? AND user_id = ? AND visivel = 0");
            $stmt->execute([$id, $userId]);
        }

        if ($stmt->rowCount() >= 1) {
            $pdo->prepare("UPDATE installments SET status = 'pending' WHERE transaction_id = ? AND status = 'cancelled'")->execute([$id]);

            if ($goalId !== null) {
                if ($txType === 'expense') {
                    $pdo->prepare("UPDATE goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?")
                        ->execute([$txAmount, $goalId, $userId]);
                } elseif ($txType === 'income') {
                    $pdo->prepare("UPDATE goals SET current_amount = GREATEST(0, current_amount - ?) WHERE id = ? AND user_id = ?")
                        ->execute([$txAmount, $goalId, $userId]);
                }
            }
        }
    }

    $pdo->commit();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro ao restaurar lançamento: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Erro ao restaurar lançamento.']);
}
