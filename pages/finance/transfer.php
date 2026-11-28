<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);
Security::ensureTransferColumn($pdo);

$erro = $_GET['erro'] ?? '';
$sucesso = $_GET['sucesso'] ?? '';

// Busca as contas do usuário com saldos atuais calculados (apenas transações ativas)
$stmt = $pdo->prepare(
    "SELECT 
        a.id, 
        a.name, 
        a.type,
        a.initial_balance,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'income' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_receitas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'expense' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_despesas,
        (SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND status = 'completed' AND visivel = 1 AND (transfer_id IS NOT NULL OR category_name = 'Transferência')) AS saldo_transferencias
     FROM accounts a
     WHERE a.user_id = ?
     ORDER BY a.name ASC"
);
$stmt->execute([$userId]);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($contas as &$c) {
    $c['saldo_atual'] = (float)$c['initial_balance'] + (float)$c['total_receitas'] - (float)$c['total_despesas'] + (float)$c['saldo_transferencias'];
}
unset($c);

// Processamento da Transferência
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } elseif (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
        Security::flashRedirect('transfer', 'sucesso', 'Essa operação já tinha sido registrada.');
    } else {
        $fromId     = (int)($_POST['from_account_id'] ?? 0);
        $toId       = (int)($_POST['to_account_id'] ?? 0);
        $valorRaw   = trim($_POST['amount'] ?? '');
        if (is_string($valorRaw)) {
            $valorRaw = str_replace(['R$', ' '], '', $valorRaw);
            if (str_contains($valorRaw, ',') && str_contains($valorRaw, '.')) {
                $valorRaw = str_replace('.', '', $valorRaw);
                $valorRaw = str_replace(',', '.', $valorRaw);
            } elseif (str_contains($valorRaw, ',')) {
                $valorRaw = str_replace(',', '.', $valorRaw);
            }
        }
        $data       = Security::sanitizeDate($_POST['date'] ?? null, date('Y-m-d'));
        $descricao  = trim($_POST['description'] ?? '');

        if ($fromId <= 0 || $toId <= 0) {
            $erro = 'Selecione a conta de origem e a conta de destino.';
        } elseif ($fromId === $toId) {
            $erro = 'A conta de origem e a conta de destino devem ser diferentes.';
        } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
            $erro = 'Informe um valor válido maior que zero.';
        } elseif (!Security::validateAccountOwnership($pdo, $fromId, $userId) || !Security::validateAccountOwnership($pdo, $toId, $userId)) {
            $erro = 'Contas selecionadas inválidas ou não autorizadas.';
        } else {
            $valor = (float) $valorRaw;

            // Busca nomes das contas
            $stmtNomeFrom = $pdo->prepare("SELECT name FROM accounts WHERE id = ?");
            $stmtNomeFrom->execute([$fromId]);
            $nomeFrom = $stmtNomeFrom->fetchColumn() ?: 'Conta Origem';

            $stmtNomeTo = $pdo->prepare("SELECT name FROM accounts WHERE id = ?");
            $stmtNomeTo->execute([$toId]);
            $nomeTo = $stmtNomeTo->fetchColumn() ?: 'Conta Destino';

            try {
                $pdo->beginTransaction();

                // Pessimistic locking nas contas para evitar concorrência e race condition
                $stmtLock = $pdo->prepare("SELECT id FROM accounts WHERE id IN (?, ?) AND user_id = ? FOR UPDATE");
                $stmtLock->execute([$fromId, $toId, $userId]);

                if ($stmtLock->rowCount() !== 2) {
                    $pdo->rollBack();
                    $erro = 'Contas selecionadas inválidas ou não autorizadas.';
                } else {
                    // Recalcula atomicamente o saldo da conta de origem sob o lock com a fórmula canônica do sistema
                    $stmtSaldoLock = $pdo->prepare("
                        SELECT (
                            a.initial_balance +
                            COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'income' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')), 0) -
                            COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'expense' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')), 0) +
                            COALESCE((SELECT SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND status = 'completed' AND visivel = 1 AND (transfer_id IS NOT NULL OR category_name = 'Transferência')), 0)
                        ) AS saldo_atual
                        FROM accounts a
                        WHERE a.id = ? AND a.user_id = ?
                    ");
                    $stmtSaldoLock->execute([$fromId, $userId]);
                    $saldoOrigemAtual = (float)$stmtSaldoLock->fetchColumn();

                    if ($valor > $saldoOrigemAtual) {
                        $pdo->rollBack();
                        $erro = "Saldo insuficiente na conta de origem '{$nomeFrom}'. Saldo disponível: R$ " . number_format($saldoOrigemAtual, 2, ',', '.') . " (não é permitido transferir sem saldo).";
                    } else {
                        // Identificador único (UUID v4) que amarra as duas pernas da transferência
                        $transferId = sprintf(
                            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0x0fff) | 0x4000,
                            mt_rand(0, 0x3fff) | 0x8000,
                            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                        );

                        // Garante categorias de transferência para o usuário
                        $catExpId = Security::getOrCreateCategory($pdo, $userId, 'Transferência', 'expense');
                        $catIncId = Security::getOrCreateCategory($pdo, $userId, 'Transferência', 'income');

                        // 1. Débito na conta de origem (Saída)
                        $descOrigem = !empty($descricao) ? "{$descricao} (Transferência para {$nomeTo})" : "Transferência para {$nomeTo}";
                        $stmtDebito = $pdo->prepare(
                            "INSERT INTO transactions (user_id, account_id, transfer_id, category_id, category_name, type, amount, description, date, status, urgency, visivel)
                             VALUES (?, ?, ?, ?, 'Transferência', 'expense', ?, ?, ?, 'completed', 'baixa', 1)"
                        );
                        $stmtDebito->execute([$userId, $fromId, $transferId, $catExpId, $valor, $descOrigem, $data]);

                        // 2. Crédito na conta de destino (Entrada)
                        $descDestino = !empty($descricao) ? "{$descricao} (Transferência de {$nomeFrom})" : "Transferência de {$nomeFrom}";
                        $stmtCredito = $pdo->prepare(
                            "INSERT INTO transactions (user_id, account_id, transfer_id, category_id, category_name, type, amount, description, date, status, urgency, visivel)
                             VALUES (?, ?, ?, ?, 'Transferência', 'income', ?, ?, ?, 'completed', 'baixa', 1)"
                        );
                        $stmtCredito->execute([$userId, $toId, $transferId, $catIncId, $valor, $descDestino, $data]);

                        $pdo->commit();

                        $msg = "Transferência de R$ " . number_format($valor, 2, ',', '.') . " de '{$nomeFrom}' para '{$nomeTo}' realizada com sucesso!";
                        if (!headers_sent()) {
                            Security::flashRedirect('accounts', 'sucesso', $msg);
                        } else {
                            Security::setFlash('sucesso', $msg);
                            echo "<script>window.location.href=" . json_encode('accounts') . ";</script>";
                        }
                        exit;
                    }
                }

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Erro na transferência entre contas: " . $e->getMessage());
                $erro = 'Ocorreu um erro ao processar a transferência no banco de dados.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Transferência entre Contas</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'contas';
$tituloPagina = 'Transferência entre Contas';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($erro)): ?>
        <div style="max-width: 480px; margin: 0 auto 16px auto; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (count($contas) < 2): ?>
        <div class="bloco" style="max-width:520px; margin: 40px auto; text-align:center;">
            <h2>Mínimo de 2 Contas Necessário</h2>
            <p style="color:var(--color-text-secondary); margin:16px 0;">Para realizar transferências entre contas, você precisa ter cadastradas pelo menos 2 contas financeiras ativas.</p>
            <a href="accounts" class="btn-header-primary" style="display:inline-block;">Cadastrar Nova Conta</a>
        </div>
    <?php else: ?>
        <form class="form-lancamento" action="transfer" method="POST">
            <?= Security::csrfField() ?>

            <div style="margin-bottom: 20px; text-align: center;">
                <h1 style="font-size:22px; color:var(--color-text); margin-bottom:6px;">Transferir entre Contas</h1>
                <p style="font-size:13px; color:var(--color-text-secondary);">Mova saldo de uma carteira/conta para outra sem duplicar seus gastos.</p>
            </div>

            <label>Conta de Origem (De onde sai o dinheiro)
                <select name="from_account_id" id="fromAccount" required>
                    <option value="">Selecione a conta de origem...</option>
                    <?php foreach ($contas as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['name']) ?> (Saldo atual: R$ <?= number_format($c['saldo_atual'], 2, ',', '.') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Conta de Destino (Para onde vai o dinheiro)
                <select name="to_account_id" id="toAccount" required>
                    <option value="">Selecione a conta de destino...</option>
                    <?php foreach ($contas as $c): ?>
                        <option value="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['name']) ?> (Saldo atual: R$ <?= number_format($c['saldo_atual'], 2, ',', '.') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Valor da Transferência
                <input type="text" id="amountDisplayTransfer" class="money-mask" data-target-name="amount" placeholder="R$ 0,00" autofocus>
                <input type="hidden" name="amount" id="amountValueTransfer" value="0.00" required>
            </label>

            <label>Data da Transferência
                <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
            </label>

            <label>Observação / Descrição (opcional)
                <input type="text" name="description" maxlength="150" placeholder="Ex: Reserva para poupança, Saque de dinheiro...">
            </label>

            <button type="submit">Confirmar Transferência</button>
        </form>
    <?php endif; ?>

</main>

<script>
const fromSelect = document.getElementById('fromAccount');
const toSelect = document.getElementById('toAccount');

if (fromSelect && toSelect) {
    function validarSelecaoContas() {
        if (fromSelect.value && toSelect.value && fromSelect.value === toSelect.value) {
            toSelect.setCustomValidity('A conta de destino não pode ser a mesma conta de origem.');
        } else {
            toSelect.setCustomValidity('');
        }
    }
    fromSelect.addEventListener('change', validarSelecaoContas);
    toSelect.addEventListener('change', validarSelecaoContas);
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


