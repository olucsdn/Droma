<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();
Security::ensureTransferColumn($pdo);

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

$tiposPermitidos = [
    'checking'   => 'Conta Corrente',
    'savings'    => 'Poupança',
    'cash'       => 'Carteira / Dinheiro',
    'investment' => 'Investimentos'
];

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } elseif (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
        Security::flashRedirect('accounts', 'sucesso', 'Essa operação já tinha sido registrada.');
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $nome           = trim($_POST['name'] ?? '');
            $tipo           = $_POST['type'] ?? 'checking';
            $saldoInicialRaw = str_replace(',', '.', trim($_POST['initial_balance'] ?? '0'));
            $saldoInicial   = is_numeric($saldoInicialRaw) ? (float) $saldoInicialRaw : 0.00;

            if (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome para a conta de até 100 caracteres.';
            } elseif (!array_key_exists($tipo, $tiposPermitidos)) {
                $erro = 'Tipo de conta inválido.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$userId, $nome, $tipo, $saldoInicial]);
                    Security::flashRedirect('accounts', 'sucesso', "Conta '{$nome}' criada com sucesso!");
                } catch (PDOException $e) {
                    error_log("Erro ao criar conta: " . $e->getMessage());
                    $erro = 'Erro ao cadastrar a conta no banco de dados.';
                }
            }
        } elseif ($acao === 'editar') {
            $accountId      = (int)($_POST['account_id'] ?? 0);
            $nome           = trim($_POST['name'] ?? '');
            $tipo           = $_POST['type'] ?? 'checking';
            $saldoInicialRaw = str_replace(',', '.', trim($_POST['initial_balance'] ?? '0'));
            $saldoInicial   = is_numeric($saldoInicialRaw) ? (float) $saldoInicialRaw : 0.00;

            if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Conta não encontrada ou não autorizada.';
            } elseif (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome válido para a conta.';
            } elseif (!array_key_exists($tipo, $tiposPermitidos)) {
                $erro = 'Tipo de conta inválido.';
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE accounts SET name = ?, type = ?, initial_balance = ? WHERE id = ? AND user_id = ?");
                    $stmt->execute([$nome, $tipo, $saldoInicial, $accountId, $userId]);
                    Security::flashRedirect('accounts', 'sucesso', "Conta '{$nome}' atualizada com sucesso!");
                } catch (PDOException $e) {
                    error_log("Erro ao atualizar conta: " . $e->getMessage());
                    $erro = 'Erro ao atualizar a conta.';
                }
            }
        } elseif ($acao === 'excluir') {
            $accountId = (int)($_POST['account_id'] ?? 0);

            if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Conta inválida ou não autorizada.';
            } else {
                // 1. Regra de Negócio: Não permite excluir a única conta
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
                $countStmt->execute([$userId]);
                if ((int)$countStmt->fetchColumn() <= 1) {
                    $erro = 'Você não pode excluir sua única conta. O sistema precisa de ao menos uma conta ativa.';
                } else {
                    // 2. Regra de Negócio: Não permite excluir conta com transações associadas
                    $txStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE account_id = ? AND user_id = ?");
                    $txStmt->execute([$accountId, $userId]);
                    $qtdTransacoes = (int)$txStmt->fetchColumn();

                    if ($qtdTransacoes > 0) {
                        $erro = "Esta conta possui {$qtdTransacoes} movimentação(ões) vinculada(s). Exclua ou transfira essas movimentações antes de remover a conta para proteger seu histórico financeiro.";
                    } else {
                        // 3. Regra de Negócio: Não permite excluir conta com cartão de crédito vinculado (subclasse)
                        $cardStmt = $pdo->prepare("SELECT name FROM credit_cards WHERE account_id = ? AND user_id = ?");
                        $cardStmt->execute([$accountId, $userId]);
                        $cardNome = $cardStmt->fetchColumn();

                        if ($cardNome) {
                            $erro = "Esta conta possui o cartão de crédito '{$cardNome}' vinculado como subclasse. Remova o cartão em Cartões de Crédito antes de excluir esta conta.";
                        } else {
                            try {
                                $delStmt = $pdo->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
                                $delStmt->execute([$accountId, $userId]);
                                Security::flashRedirect('accounts', 'sucesso', 'Conta removida com sucesso!');
                            } catch (PDOException $e) {
                                error_log("Erro ao excluir conta: " . $e->getMessage());
                                $erro = 'Erro ao excluir a conta.';
                            }
                        }
                    }
                }
            }
        }
    }
}

// Busca as contas com saldos, movimentações calculadas e cartão vinculado (sem duplicidade e sem poluição de transferências)
$stmt = $pdo->prepare(
    "SELECT 
        a.id, 
        a.name, 
        a.type, 
        a.initial_balance,
        a.created_at,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'income' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_receitas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'expense' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_despesas,
        (SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND status = 'completed' AND visivel = 1 AND (transfer_id IS NOT NULL OR category_name = 'Transferência')) AS saldo_transferencias,
        (SELECT COUNT(id) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_lancamentos,
        c.id AS linked_card_id,
        c.name AS linked_card_name,
        c.limit_amount AS linked_card_limit,
        c.color AS linked_card_color
     FROM accounts a
     LEFT JOIN credit_cards c ON c.account_id = a.id AND c.active = 1
     WHERE a.user_id = ?
     ORDER BY a.name ASC"
);
$stmt->execute([$userId]);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totais consolidados (transferências internas não inflam entradas nem saídas)
$saldoGeral = 0.00;
$totalEntradasGeral = 0.00;
$totalSaidasGeral = 0.00;

foreach ($contas as &$c) {
    $c['saldo_atual'] = (float)$c['initial_balance'] + (float)$c['total_receitas'] - (float)$c['total_despesas'] + (float)$c['saldo_transferencias'];
    $saldoGeral += $c['saldo_atual'];
    $totalEntradasGeral += (float)$c['total_receitas'];
    $totalSaidasGeral += (float)$c['total_despesas'];
}
unset($c);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Gestão de Contas</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'contas';
$tituloPagina = 'Gestão de Contas';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($erro)): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 12px 16px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 13px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div class="alerta-dashboard">
            <span><?= htmlspecialchars($sucesso) ?></span>
            <span style="cursor:pointer; font-weight:bold; font-size:16px;" onclick="this.parentElement.remove();">&times;</span>
        </div>
    <?php endif; ?>

    <!-- Resumo Geral das Contas -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Saldo Consolidado</span>
            <span class="valor <?= $saldoGeral >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($saldoGeral, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Soma de todas as suas contas
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Total em Entradas</span>
            <span class="valor positivo">+ R$ <?= number_format($totalEntradasGeral, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Receitas realizadas acumuladas
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Total em Saídas</span>
            <span class="valor negativo">- R$ <?= number_format($totalSaidasGeral, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Despesas realizadas acumuladas
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Contas Ativas</span>
            <span class="valor" style="color:var(--color-gold);"><?= count($contas) ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Carteiras e contas cadastradas
            </span>
        </div>
    </section>

    <!-- Grade com Listagem e Criação -->
    <section class="grade">

        <!-- Lista de Contas -->
        <div class="bloco">
            <div class="cabecalho-secao" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2 style="margin:0;">Suas Contas Financeiras</h2>
                <a href="transfer" class="btn-aba" style="padding:6px 14px; text-decoration:none;">⇄ Transferir entre Contas</a>
            </div>

            <div class="contas-grid">
                <?php foreach ($contas as $c): ?>
                    <div style="background:var(--color-surface-secondary); border:1px solid var(--color-border); border-radius:var(--radius-md); padding:16px; display:flex; flex-direction:column; justify-content:space-between;">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <strong style="font-size:16px; color:var(--color-text);"><?= htmlspecialchars($c['name']) ?></strong>
                                <span class="badge-status" style="background:rgba(212, 175, 108, 0.15); color:var(--color-gold); border:1px solid rgba(212, 175, 108, 0.3); font-size:11px; padding:2px 8px; border-radius:var(--radius-pill);">
                                    <?= $tiposPermitidos[$c['type']] ?? $c['type'] ?>
                                </span>
                            </div>

                            <div style="margin-top:10px;">
                                <span style="font-size:12px; color:var(--color-text-secondary); display:block;">Saldo Atual</span>
                                <span style="font-size:22px; font-weight:800; color:<?= $c['saldo_atual'] >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                                    R$ <?= number_format($c['saldo_atual'], 2, ',', '.') ?>
                                </span>
                            </div>

                            <div style="margin-top:14px; padding-top:10px; border-top:1px solid var(--color-border); font-size:12px; color:var(--color-text-secondary); display:flex; flex-direction:column; gap:4px;">
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Saldo Inicial:</span>
                                    <span>R$ <?= number_format($c['initial_balance'], 2, ',', '.') ?></span>
                                </div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Entradas:</span>
                                    <span style="color:var(--color-emerald);">+ R$ <?= number_format($c['total_receitas'], 2, ',', '.') ?></span>
                                </div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Saídas:</span>
                                    <span style="color:var(--color-danger);">- R$ <?= number_format($c['total_despesas'], 2, ',', '.') ?></span>
                                </div>
                                <div style="display:flex; justify-content:space-between;">
                                    <span>Lançamentos:</span>
                                    <span><?= $c['total_lancamentos'] ?></span>
                                </div>

                                <?php if (!empty($c['linked_card_id'])): ?>
                                    <div style="margin-top:8px; padding:6px 10px; background:rgba(212, 175, 108, 0.1); border-left:3px solid <?= htmlspecialchars($c['linked_card_color'] ?: 'var(--color-gold)') ?>; border-radius:4px; font-size:11px; display:flex; justify-content:space-between; align-items:center;">
                                        <span style="color:var(--color-gold-light);">💳 Cartão: <strong><?= htmlspecialchars($c['linked_card_name']) ?></strong></span>
                                        <span style="color:var(--color-text-secondary);">Lim: R$ <?= number_format($c['linked_card_limit'], 2, ',', '.') ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Ações da Conta -->
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; padding-top:12px; border-top:1px solid var(--color-border);">
                            <button type="button" class="btn-aba" style="padding:4px 10px; font-size:12px;" onclick="iniciarEdicaoConta(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)">
                                Editar
                            </button>

                            <form action="accounts" method="POST" style="margin:0;" data-confirm="Deseja realmente excluir a conta &quot;<?= htmlspecialchars($c['name'], ENT_QUOTES) ?>&quot;?" data-confirm-title="Excluir Conta?">
                                <?= Security::csrfField() ?>
                                <input type="hidden" name="acao" value="excluir">
                                <input type="hidden" name="account_id" value="<?= $c['id'] ?>">
                                <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;" title="Excluir conta">
                                    Excluir
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Formulário para Criar ou Editar Conta -->
        <div class="bloco" style="height:fit-content;">
            <h2 id="formTituloConta">+ Nova Conta</h2>
            <form id="formConta" action="accounts" method="POST" class="form-lancamento" style="max-width:100%; padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" id="contaAcao" value="criar">
                <input type="hidden" name="account_id" id="contaId" value="">

                <label>Nome da Conta
                    <input type="text" name="name" id="contaNome" placeholder="Ex: Nubank, Carteira, Banco do Brasil..." maxlength="100" required>
                </label>

                <label>Tipo de Conta
                    <select name="type" id="contaTipo" required>
                        <?php foreach ($tiposPermitidos as $chave => $rotulo): ?>
                            <option value="<?= $chave ?>"><?= $rotulo ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Saldo Inicial (R$)
                    <input type="number" step="0.01" name="initial_balance" id="contaSaldoInicial" placeholder="0,00" value="0.00" required>
                </label>

                <div style="display:flex; gap:10px; margin-top:8px;">
                    <button type="submit" id="btnSalvarConta" style="flex:1;">Criar Conta</button>
                    <button type="button" id="btnCancelarEdicao" class="sair" style="display:none;" onclick="cancelarEdicaoConta()">Cancelar</button>
                </div>
            </form>
        </div>

    </section>

</main>

<script>
function iniciarEdicaoConta(conta) {
    document.getElementById('formTituloConta').textContent = 'Editar Conta: ' + conta.name;
    document.getElementById('contaAcao').value = 'editar';
    document.getElementById('contaId').value = conta.id;
    document.getElementById('contaNome').value = conta.name;
    document.getElementById('contaTipo').value = conta.type;
    document.getElementById('contaSaldoInicial').value = parseFloat(conta.initial_balance).toFixed(2);
    document.getElementById('btnSalvarConta').textContent = 'Salvar Alterações';
    document.getElementById('btnCancelarEdicao').style.display = 'inline-block';
    document.getElementById('formConta').scrollIntoView({ behavior: 'smooth' });
}

function cancelarEdicaoConta() {
    document.getElementById('formTituloConta').textContent = '+ Nova Conta';
    document.getElementById('contaAcao').value = 'criar';
    document.getElementById('contaId').value = '';
    document.getElementById('contaNome').value = '';
    document.getElementById('contaTipo').value = 'checking';
    document.getElementById('contaSaldoInicial').value = '0.00';
    document.getElementById('btnSalvarConta').textContent = 'Criar Conta';
    document.getElementById('btnCancelarEdicao').style.display = 'none';
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


