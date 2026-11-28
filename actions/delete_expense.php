<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);

// Exclusão permitida estritamente via método POST para prevenir ataques via GET/CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ./");
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCsrf($csrfToken)) {
    die("Ação não autorizada ou sessão expirada. Por favor, atualize a página.");
}
if (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
    Security::flashRedirect('./', 'sucesso', 'Essa operação já tinha sido registrada.');
}

$id = $_POST['id'] ?? 0;

if (ctype_digit((string)$id) && (int)$id > 0) {
    try {
        Security::ensureTransferColumn($pdo);
        Security::ensureGoalColumn($pdo);

        $pdo->beginTransaction();

        $chk = $pdo->prepare("SELECT transfer_id, goal_id, credit_card_id, type, amount FROM transactions WHERE id = ? AND user_id = ? AND visivel = 1 FOR UPDATE");
        $chk->execute([(int)$id, $userId]);
        $tx = $chk->fetch(PDO::FETCH_ASSOC);

        if ($tx) {
            $transferId = $tx['transfer_id'] ?? null;
            $goalId     = !empty($tx['goal_id']) ? (int)$tx['goal_id'] : null;
            $txAmount   = (float)$tx['amount'];
            $txType     = $tx['type'];

            if (!empty($transferId)) {
                // Se pertencer a uma transferência interna, desativa ambas as pernas vinculadas para manter o saldo equilibrado
                $stmt = $pdo->prepare("UPDATE transactions SET visivel = 0 WHERE transfer_id = ? AND user_id = ? AND visivel = 1");
                $stmt->execute([$transferId, $userId]);
            } else {
                // Soft Delete: altera visibilidade para 0 sem remover fisicamente o registro
                $stmt = $pdo->prepare("UPDATE transactions SET visivel = 0 WHERE id = ? AND user_id = ? AND visivel = 1");
                $stmt->execute([(int)$id, $userId]);
            }

            if ($stmt->rowCount() >= 1) {
                // Cancela parcelas pendentes vinculadas caso seja uma compra parcelada de cartão
                $stmtParc = $pdo->prepare("UPDATE installments SET status = 'cancelled' WHERE transaction_id = ? AND status = 'pending'");
                $stmtParc->execute([(int)$id]);

                // Se for lançamento atrelado a meta financeira, ajusta o current_amount da meta atomicamente
                if ($goalId !== null) {
                    if ($txType === 'expense') {
                        // Aporte cancelado: decrementa o saldo guardado na meta
                        $stmtGoal = $pdo->prepare("UPDATE goals SET current_amount = GREATEST(0, current_amount - ?) WHERE id = ? AND user_id = ?");
                        $stmtGoal->execute([$txAmount, $goalId, $userId]);
                    } elseif ($txType === 'income') {
                        // Resgate cancelado: devolve o valor para a meta
                        $stmtGoal = $pdo->prepare("UPDATE goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
                        $stmtGoal->execute([$txAmount, $goalId, $userId]);
                    }
                }
            }
        }

        $pdo->commit();
        if ($tx) {
            $_SESSION['droma_undo_tx'] = ['id' => (int)$id, 'ts' => time()];
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro ao excluir lançamento: " . $e->getMessage());
    }
}

$origem = $_POST['origem'] ?? '';
if ($origem === 'calendario') {
    $redirect = "calendar";
    if (!empty($_POST['mes']) && !empty($_POST['ano'])) {
        $redirect .= "?mes=" . (int)$_POST['mes'] . "&ano=" . (int)$_POST['ano'];
    }
    Security::flashRedirect($redirect, 'sucesso', 'Lançamento excluído com sucesso!');
} elseif ($origem === 'extrato') {
    Security::flashRedirect('statement', 'sucesso', 'Lançamento excluído com sucesso!');
} else {
    Security::flashRedirect('./', 'sucesso', 'Lançamento excluído com sucesso!');
}


