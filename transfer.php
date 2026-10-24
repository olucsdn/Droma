<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

// Busca as contas do usuário com saldos atuais calculados
$stmt = $pdo->prepare(
    "SELECT 
        a.id, 
        a.name, 
        a.type,
        a.initial_balance,
        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_receitas,
        COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_despesas
     FROM accounts a
     LEFT JOIN transactions t ON t.account_id = a.id AND t.user_id = a.user_id
     WHERE a.user_id = ?
     GROUP BY a.id, a.name, a.type, a.initial_balance
     ORDER BY a.name ASC"
);
$stmt->execute([$userId]);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($contas as &$c) {
    $c['saldo_atual'] = (float)$c['initial_balance'] + (float)$c['total_receitas'] - (float)$c['total_despesas'];
}
unset($c);

// Processamento da Transferência
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } else {
        $fromId     = (int)($_POST['from_account_id'] ?? 0);
        $toId       = (int)($_POST['to_account_id'] ?? 0);
        $valorRaw   = str_replace(',', '.', trim($_POST['amount'] ?? ''));
        $data       = trim($_POST['date'] ?? date('Y-m-d'));
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

                // Garante categorias de transferência para o usuário
                $stmtCatExp = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Transferência' AND type = 'expense' LIMIT 1");
                $stmtCatExp->execute([$userId]);
                $catExpId = $stmtCatExp->fetchColumn();

                if (!$catExpId) {
                    $insCatExp = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Transferência', 'expense')");
                    $insCatExp->execute([$userId]);
                    $catExpId = (int)$pdo->lastInsertId();
                }

                $stmtCatInc = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Transferência' AND type = 'income' LIMIT 1");
                $stmtCatInc->execute([$userId]);
                $catIncId = $stmtCatInc->fetchColumn();

                if (!$catIncId) {
                    $insCatInc = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Transferência', 'income')");
                    $insCatInc->execute([$userId]);
                    $catIncId = (int)$pdo->lastInsertId();
                }

                // 1. Débito na conta de origem (Saída)
                $descOrigem = !empty($descricao) ? "{$descricao} (Transferência para {$nomeTo})" : "Transferência para {$nomeTo}";
                $stmtDebito = $pdo->prepare(
                    "INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency)
                     VALUES (?, ?, ?, 'expense', ?, ?, ?, 'completed', 'baixa')"
                );
                $stmtDebito->execute([$userId, $fromId, $catExpId, $valor, $descOrigem, $data]);

                // 2. Crédito na conta de destino (Entrada)
                $descDestino = !empty($descricao) ? "{$descricao} (Transferência de {$nomeFrom})" : "Transferência de {$nomeFrom}";
                $stmtCredito = $pdo->prepare(
                    "INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency)
                     VALUES (?, ?, ?, 'income', ?, ?, ?, 'completed', 'baixa')"
                );
                $stmtCredito->execute([$userId, $toId, $catIncId, $valor, $descDestino, $data]);

                $pdo->commit();

                $msg = "Transferência de R$ " . number_format($valor, 2, ',', '.') . " de '{$nomeFrom}' para '{$nomeTo}' realizada com sucesso!";
                header("Location: accounts.php?sucesso=" . urlencode($msg));
                exit;

            } catch (PDOException $e) {
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
    <title>Droma - Transferência entre Contas</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
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
            <a href="accounts.php" class="btn-header-primary" style="display:inline-block;">Cadastrar Nova Conta</a>
        </div>
    <?php else: ?>
        <form class="form-lancamento" action="transfer.php" method="POST">
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

            <label>Valor da Transferência (R$)
                <input type="number" step="0.01" min="0.01" name="amount" placeholder="0,00" required autofocus>
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

