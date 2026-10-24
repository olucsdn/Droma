<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

// Carrega todas as contas do usuário
$stmtContas = $pdo->prepare("SELECT id, name, type FROM accounts WHERE user_id = ? ORDER BY name ASC");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

// Carrega contas disponíveis para novo cartão (regra: 1 cartão por conta)
$stmtContasDisp = $pdo->prepare("
    SELECT a.id, a.name, a.type 
    FROM accounts a
    LEFT JOIN credit_cards c ON c.account_id = a.id
    WHERE a.user_id = ? AND c.id IS NULL
    ORDER BY a.name ASC
");
$stmtContasDisp->execute([$userId]);
$contasDisponiveis = $stmtContasDisp->fetchAll(PDO::FETCH_ASSOC);

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $accountId  = (int)($_POST['account_id'] ?? 0);
            $nome       = trim($_POST['name'] ?? '');
            $limiteRaw  = str_replace(',', '.', trim($_POST['limit_amount'] ?? '0'));
            $fechamento = (int)($_POST['closing_day'] ?? 1);
            $vencimento = (int)($_POST['due_day'] ?? 10);
            $cor        = trim($_POST['color'] ?? '#D4AF6C');

            if ($accountId <= 0 || !Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Selecione uma conta bancária válida para vincular ao cartão de crédito.';
            } elseif (!Security::validateAccountHasNoCard($pdo, $accountId)) {
                $erro = 'A conta bancária selecionada já possui um cartão de crédito vinculado. Cada conta pode ter no máximo 1 cartão.';
            } elseif (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome válido para o cartão.';
            } elseif (!is_numeric($limiteRaw) || (float)$limiteRaw < 0) {
                $erro = 'Informe um valor de limite válido.';
            } elseif ($fechamento < 1 || $fechamento > 31 || $vencimento < 1 || $vencimento > 31) {
                $erro = 'Os dias de fechamento e vencimento devem estar entre 1 e 31.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day, color)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$userId, $accountId, $nome, (float)$limiteRaw, $fechamento, $vencimento, $cor]);
                    header("Location: cartoes.php?sucesso=" . urlencode("Cartão '{$nome}' cadastrado e vinculado com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao cadastrar cartão: " . $e->getMessage());
                    $erro = 'Erro ao cadastrar o cartão no banco de dados.';
                }
            }
        } elseif ($acao === 'editar') {
            $cardId     = (int)($_POST['card_id'] ?? 0);
            $nome       = trim($_POST['name'] ?? '');
            $limiteRaw  = str_replace(',', '.', trim($_POST['limit_amount'] ?? '0'));
            $fechamento = (int)($_POST['closing_day'] ?? 1);
            $vencimento = (int)($_POST['due_day'] ?? 10);
            $cor        = trim($_POST['color'] ?? '#D4AF6C');

            if (!Security::validateCreditCardOwnership($pdo, $cardId, $userId)) {
                $erro = 'Cartão não encontrado ou não autorizado.';
            } elseif (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome válido para o cartão.';
            } elseif (!is_numeric($limiteRaw) || (float)$limiteRaw < 0) {
                $erro = 'Informe um valor de limite válido.';
            } elseif ($fechamento < 1 || $fechamento > 31 || $vencimento < 1 || $vencimento > 31) {
                $erro = 'Os dias de fechamento e vencimento devem estar entre 1 e 31.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "UPDATE credit_cards
                         SET name = ?, limit_amount = ?, closing_day = ?, due_day = ?, color = ?
                         WHERE id = ? AND user_id = ?"
                    );
                    $stmt->execute([$nome, (float)$limiteRaw, $fechamento, $vencimento, $cor, $cardId, $userId]);
                    header("Location: cartoes.php?sucesso=" . urlencode("Cartão '{$nome}' atualizado com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao atualizar cartão: " . $e->getMessage());
                    $erro = 'Erro ao salvar alterações do cartão.';
                }
            }
        } elseif ($acao === 'excluir') {
            $cardId = (int)($_POST['card_id'] ?? 0);

            if (!Security::validateCreditCardOwnership($pdo, $cardId, $userId)) {
                $erro = 'Cartão não encontrado ou não autorizado.';
            } else {
                // Checa se há parcelas ou compras pendentes
                $stmtCheck = $pdo->prepare(
                    "SELECT 
                        (SELECT COUNT(*) FROM installments WHERE credit_card_id = ? AND status = 'pending') +
                        (SELECT COUNT(*) FROM transactions WHERE credit_card_id = ? AND status = 'pending' AND id NOT IN (SELECT transaction_id FROM installments)) AS total_pendente"
                );
                $stmtCheck->execute([$cardId, $cardId]);
                $totalPendencias = (int)$stmtCheck->fetchColumn();

                if ($totalPendencias > 0) {
                    $erro = "Este cartão possui {$totalPendencias} compras ou parcelas pendentes na fatura. Realize o pagamento ou cancele os lançamentos antes de excluir o cartão.";
                } else {
                    try {
                        $del = $pdo->prepare("DELETE FROM credit_cards WHERE id = ? AND user_id = ?");
                        $del->execute([$cardId, $userId]);
                        header("Location: cartoes.php?sucesso=" . urlencode("Cartão excluído com sucesso!"));
                        exit;
                    } catch (PDOException $e) {
                        error_log("Erro ao excluir cartão: " . $e->getMessage());
                        $erro = 'Erro ao excluir o cartão.';
                    }
                }
            }
        } elseif ($acao === 'pagar_fatura') {
            $cardId    = (int)($_POST['card_id'] ?? 0);
            $accountId = (int)($_POST['account_id'] ?? 0);
            $dataPagto = trim($_POST['payment_date'] ?? date('Y-m-d'));

            if (!Security::validateCreditCardOwnership($pdo, $cardId, $userId)) {
                $erro = 'Cartão não encontrado ou não autorizado.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Conta bancária de pagamento inválida.';
            } else {
                // Busca nome do cartão
                $stmtCard = $pdo->prepare("SELECT name FROM credit_cards WHERE id = ?");
                $stmtCard->execute([$cardId]);
                $cardNome = $stmtCard->fetchColumn();

                // Calcula o total pendente das parcelas e compras à vista do cartão
                $stmtParc = $pdo->prepare("SELECT id, amount FROM installments WHERE credit_card_id = ? AND status = 'pending'");
                $stmtParc->execute([$cardId]);
                $parcelasPendentes = $stmtParc->fetchAll(PDO::FETCH_ASSOC);

                $stmtTx = $pdo->prepare("SELECT id, amount FROM transactions WHERE credit_card_id = ? AND status = 'pending' AND id NOT IN (SELECT transaction_id FROM installments)");
                $stmtTx->execute([$cardId]);
                $comprasAvista = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

                $totalFatura = 0.00;
                foreach ($parcelasPendentes as $p) $totalFatura += (float)$p['amount'];
                foreach ($comprasAvista as $c) $totalFatura += (float)$c['amount'];

                if ($totalFatura <= 0) {
                    $erro = 'Não há faturas ou lançamentos pendentes para pagamento neste cartão.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        // Garante categoria 'Fatura de Cartão'
                        $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Pagamento de Cartão' AND type = 'expense' LIMIT 1");
                        $stmtCat->execute([$userId]);
                        $catId = $stmtCat->fetchColumn();

                        if (!$catId) {
                            $insCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Pagamento de Cartão', 'expense')");
                            $insCat->execute([$userId]);
                            $catId = (int)$pdo->lastInsertId();
                        }

                        // 1. Gera a transação de despesa na conta bancária (desembolso real de caixa)
                        $descPagamento = "Pagamento da Fatura - {$cardNome}";
                        $stmtPagto = $pdo->prepare(
                            "INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency)
                             VALUES (?, ?, ?, 'expense', ?, ?, ?, 'completed', 'alta')"
                        );
                        $stmtPagto->execute([$userId, $accountId, $catId, $totalFatura, $descPagamento, $dataPagto]);

                        // 2. Baixa as parcelas do cartão
                        $updParc = $pdo->prepare("UPDATE installments SET status = 'completed' WHERE credit_card_id = ? AND status = 'pending'");
                        $updParc->execute([$cardId]);

                        // 3. Baixa as compras avulsas no cartão
                        $updTx = $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE credit_card_id = ? AND status = 'pending'");
                        $updTx->execute([$cardId]);

                        $pdo->commit();

                        $msg = "Fatura do cartão '{$cardNome}' no valor de R$ " . number_format($totalFatura, 2, ',', '.') . " paga com sucesso!";
                        header("Location: cartoes.php?sucesso=" . urlencode($msg));
                        exit;

                    } catch (PDOException $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log("Erro ao pagar fatura de cartão: " . $e->getMessage());
                        $erro = 'Erro ao processar o pagamento da fatura.';
                    }
                }
            }
        }
    }
}

// Carrega cartões e calcula limites utilizados e disponíveis com a conta vinculada (subclasse)
$stmt = $pdo->prepare(
    "SELECT c.*, a.name AS account_name, a.type AS account_type
     FROM credit_cards c
     INNER JOIN accounts a ON a.id = c.account_id
     WHERE c.user_id = ? 
     ORDER BY c.name ASC"
);
$stmt->execute([$userId]);
$cartoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalLimiteGeral = 0.00;
$totalUtilizadoGeral = 0.00;

foreach ($cartoes as &$card) {
    $cardId = (int)$card['id'];

    // 1. Soma das parcelas pendentes
    $sParc = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM installments WHERE credit_card_id = ? AND status = 'pending'");
    $sParc->execute([$cardId]);
    $gastoParcelas = (float)$sParc->fetchColumn();

    // 2. Soma de compras à vista pendentes (exclui compras desmembradas em parcelas)
    $sTx = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE credit_card_id = ? AND status = 'pending' AND id NOT IN (SELECT transaction_id FROM installments)");
    $sTx->execute([$cardId]);
    $gastoAvista = (float)$sTx->fetchColumn();

    $card['limite_utilizado'] = $gastoParcelas + $gastoAvista;
    $card['limite_disponivel'] = max(0, (float)$card['limit_amount'] - $card['limite_utilizado']);
    $card['pct_uso'] = $card['limit_amount'] > 0 ? min(100, round(($card['limite_utilizado'] / $card['limit_amount']) * 100)) : 0;

    $totalLimiteGeral += (float)$card['limit_amount'];
    $totalUtilizadoGeral += $card['limite_utilizado'];
}
unset($card);

$totalDisponivelGeral = max(0, $totalLimiteGeral - $totalUtilizadoGeral);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - Cartões de Crédito</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
    <style>
        .cartao-credito-card {
            background: linear-gradient(135deg, #16242B 0%, #0B1115 100%);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }
        .cartao-credito-chip {
            width: 38px;
            height: 28px;
            background: linear-gradient(135deg, #E8C982 0%, #B88642 100%);
            border-radius: 4px;
            margin-bottom: 12px;
        }
        .barra-limite-bg {
            background: rgba(255, 255, 255, 0.08);
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        .barra-limite-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'cartoes';
$tituloPagina = 'Cartões de Crédito';
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

    <!-- Resumo Geral de Crédito -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Limite Total dos Cartões</span>
            <span class="valor" style="color:var(--color-gold);">
                R$ <?= number_format($totalLimiteGeral, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Crédito total aprovado</span>
        </div>

        <div class="cartao">
            <span class="rotulo">Fatura / Limite Utilizado</span>
            <span class="valor negativo">
                R$ <?= number_format($totalUtilizadoGeral, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Total comprometido em aberto</span>
        </div>

        <div class="cartao">
            <span class="rotulo">Limite Disponível</span>
            <span class="valor positivo">
                R$ <?= number_format($totalDisponivelGeral, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Disponível para compras</span>
        </div>

        <div class="cartao">
            <span class="rotulo">Cartões Cadastrados</span>
            <span class="valor"><?= count($cartoes) ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Gerenciamento de faturas</span>
        </div>
    </section>

    <!-- Grade de Cartões e Formulário -->
    <section class="grade">

        <!-- Lista de Cartões de Crédito -->
        <div class="bloco">
            <div class="cabecalho-secao" style="margin-bottom:16px;">
                <h2 style="margin:0;">Seus Cartões de Crédito</h2>
                <span style="font-size:12px; color:var(--color-text-secondary);"><?= count($cartoes) ?> ativo(s)</span>
            </div>

            <?php if (empty($cartoes)): ?>
                <p class="vazio">Nenhum cartão cadastrado. Use o formulário ao lado para adicionar seus cartões e controlar limites e faturas!</p>
            <?php else: ?>
                <div class="cartoes-grid">
                    <?php foreach ($cartoes as $card): 
                        $corDestaque = htmlspecialchars($card['color'] ?: '#D4AF6C');
                    ?>
                        <div class="cartao-credito-card" style="border-top: 4px solid <?= $corDestaque ?>;">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div class="cartao-credito-chip"></div>
                                    <span style="font-size:12px; font-weight:700; color:<?= $corDestaque ?>; text-transform:uppercase;">Droma Card</span>
                                </div>

                                <strong style="font-size:18px; color:var(--color-text); display:block; margin-bottom:12px;">
                                    <?= htmlspecialchars($card['name']) ?>
                                </strong>

                                <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--color-text-secondary);">
                                    <span>Fatura em aberto:</span>
                                    <strong style="color:var(--color-danger); font-size:14px;">R$ <?= number_format($card['limite_utilizado'], 2, ',', '.') ?></strong>
                                </div>

                                <!-- Barra de Progresso do Limite -->
                                <div class="barra-limite-bg">
                                    <div class="barra-limite-fill" style="width: <?= $card['pct_uso'] ?>%; background: <?= $card['pct_uso'] > 80 ? 'var(--color-danger)' : ($card['pct_uso'] > 50 ? 'var(--color-warning)' : 'var(--color-emerald)') ?>;"></div>
                                </div>

                                <div style="display:flex; justify-content:space-between; font-size:11px; color:var(--color-text-secondary);">
                                    <span>Disponível: R$ <?= number_format($card['limite_disponivel'], 2, ',', '.') ?></span>
                                    <span>Limite: R$ <?= number_format($card['limit_amount'], 2, ',', '.') ?> (<?= $card['pct_uso'] ?>%)</span>
                                </div>

                                <div style="margin-top:14px; padding-top:10px; border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; font-size:11px; color:var(--color-text-secondary);">
                                    <span>Fecha dia: <strong><?= $card['closing_day'] ?></strong></span>
                                    <span>Vence dia: <strong><?= $card['due_day'] ?></strong></span>
                                </div>

                                <div style="margin-top:8px; padding:6px 10px; background:rgba(255,255,255,0.04); border-radius:4px; font-size:11px; color:var(--color-gold-light); display:flex; justify-content:space-between; align-items:center;">
                                    <span>🏦 Conta Vinculada:</span>
                                    <strong><?= htmlspecialchars($card['account_name'] ?? 'Conta') ?></strong>
                                </div>
                            </div>

                            <!-- Ações: Pagar Fatura, Editar e Excluir -->
                            <div style="margin-top:16px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.08); display:flex; justify-content:space-between; align-items:center;">
                                <?php if ($card['limite_utilizado'] > 0 && !empty($contas)): ?>
                                    <button type="button" class="btn-aba ativo" style="padding:4px 10px; font-size:11px;" onclick="abrirPagamentoFatura(<?= htmlspecialchars(json_encode($card), ENT_QUOTES, 'UTF-8') ?>)">
                                        ✓ Pagar Fatura
                                    </button>
                                <?php else: ?>
                                    <span style="font-size:11px; color:var(--color-emerald);">Fatura Zerada</span>
                                <?php endif; ?>

                                <div style="display:flex; gap:6px;">
                                    <button type="button" class="btn-acao-lancamento" onclick="iniciarEdicaoCartao(<?= htmlspecialchars(json_encode($card), ENT_QUOTES, 'UTF-8') ?>)">
                                        Editar
                                    </button>
                                    <form action="cartoes.php" method="POST" style="margin:0;" onsubmit="return confirm('Deseja realmente excluir este cartão?');">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="card_id" value="<?= $card['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;">
                                            Excluir
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Formulário para Criar ou Editar Cartão -->
        <div class="bloco" style="height:fit-content;">
            <h2 id="formTituloCartao">+ Novo Cartão</h2>

            <?php if (empty($contasDisponiveis)): ?>
                <div id="avisoSemContas" style="background:rgba(212, 175, 108, 0.12); border-left:3px solid var(--color-gold); padding:12px; border-radius:var(--radius-md); font-size:13px; color:var(--color-gold-light); margin-bottom:14px;">
                    ℹ️ Todas as suas contas bancárias já possuem um cartão de crédito vinculado (ou você ainda não cadastrou nenhuma conta). Como a regra do sistema é <strong>1 cartão por conta</strong>, cadastre uma nova conta em <a href="contas.php" style="color:var(--color-gold); font-weight:700;">Contas</a> para poder adicionar outro cartão.
                </div>
            <?php endif; ?>

            <form id="formCartao" action="cartoes.php" method="POST" class="form-lancamento" style="max-width:100%; padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" id="cartaoAcao" value="criar">
                <input type="hidden" name="card_id" id="cartaoId" value="">

                <div id="campoContaVinculada">
                    <label>Conta Bancária Vinculada
                        <select name="account_id" id="cartaoAccountId" <?= empty($contasDisponiveis) ? 'disabled' : 'required' ?>>
                            <option value="">Selecione uma conta...</option>
                            <?php foreach ($contasDisponiveis as $cd): ?>
                                <option value="<?= $cd['id'] ?>"><?= htmlspecialchars($cd['name']) ?> (<?= htmlspecialchars($cd['type']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <span style="font-size:11px; color:var(--color-text-secondary); display:block; margin-top:2px;">
                            ✦ O cartão de crédito será uma subclasse desta conta. (1 cartão por conta)
                        </span>
                    </label>
                </div>

                <label>Nome do Cartão
                    <input type="text" name="name" id="cartaoNome" placeholder="Ex: Nubank Mastercard, XP Visa Infinite..." maxlength="100" required>
                </label>

                <label>Limite Total (R$)
                    <input type="number" step="0.01" min="0" name="limit_amount" id="cartaoLimite" placeholder="0,00" value="1000.00" required>
                </label>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <label>Dia de Fechamento
                        <input type="number" min="1" max="31" name="closing_day" id="cartaoFechamento" value="1" required>
                    </label>

                    <label>Dia de Vencimento
                        <input type="number" min="1" max="31" name="due_day" id="cartaoVencimento" value="10" required>
                    </label>
                </div>

                <label>Cor do Cartão
                    <input type="color" name="color" id="cartaoCor" value="#D4AF6C" style="height:40px; padding:2px; cursor:pointer;">
                </label>

                <div style="display:flex; gap:10px; margin-top:8px;">
                    <button type="submit" id="btnSalvarCartao" style="flex:1;" <?= (empty($contasDisponiveis) && empty($cartoes)) ? 'disabled' : '' ?>>Criar Cartão</button>
                    <button type="button" id="btnCancelarCartao" class="sair" style="display:none;" onclick="cancelarEdicaoCartao()">Cancelar</button>
                </div>
            </form>
        </div>

    </section>

    <!-- Modal de Pagamento de Fatura -->
    <div id="modalPagamentoFatura" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-card-investimento">
            <h2 id="tituloModalFatura" style="margin-top:0;">Pagar Fatura</h2>
            <p id="descModalFatura" style="font-size:13px; color:var(--color-text-secondary);"></p>

            <form action="cartoes.php" method="POST" class="form-lancamento" style="padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="pagar_fatura">
                <input type="hidden" name="card_id" id="pagtoCardId" value="">

                <label>Conta Bancária de Pagamento
                    <select name="account_id" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Data do Pagamento
                    <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                </label>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" style="flex:1;">Confirmar Pagamento</button>
                    <button type="button" class="sair" onclick="fecharModalFatura()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

</main>

<script>
function iniciarEdicaoCartao(c) {
    document.getElementById('formTituloCartao').textContent = 'Editar Cartão: ' + c.name;
    document.getElementById('cartaoAcao').value = 'editar';
    document.getElementById('cartaoId').value = c.id;
    document.getElementById('cartaoNome').value = c.name;
    document.getElementById('cartaoLimite').value = parseFloat(c.limit_amount).toFixed(2);
    document.getElementById('cartaoFechamento').value = c.closing_day;
    document.getElementById('cartaoVencimento').value = c.due_day;
    document.getElementById('cartaoCor').value = c.color || '#D4AF6C';
    document.getElementById('btnSalvarCartao').textContent = 'Salvar Alterações';
    document.getElementById('btnSalvarCartao').disabled = false;
    document.getElementById('btnCancelarCartao').style.display = 'inline-block';
    
    // Esconde seleção de conta na edição (o cartão já está fixado à sua conta pai)
    const campoConta = document.getElementById('campoContaVinculada');
    if (campoConta) campoConta.style.display = 'none';
    const accSelect = document.getElementById('cartaoAccountId');
    if (accSelect) accSelect.required = false;
    const aviso = document.getElementById('avisoSemContas');
    if (aviso) aviso.style.display = 'none';

    document.getElementById('formCartao').scrollIntoView({ behavior: 'smooth' });
}

function cancelarEdicaoCartao() {
    document.getElementById('formTituloCartao').textContent = '+ Novo Cartão';
    document.getElementById('cartaoAcao').value = 'criar';
    document.getElementById('cartaoId').value = '';
    document.getElementById('cartaoNome').value = '';
    document.getElementById('cartaoLimite').value = '1000.00';
    document.getElementById('cartaoFechamento').value = '1';
    document.getElementById('cartaoVencimento').value = '10';
    document.getElementById('cartaoCor').value = '#D4AF6C';
    document.getElementById('btnSalvarCartao').textContent = 'Criar Cartão';
    document.getElementById('btnCancelarCartao').style.display = 'none';

    const campoConta = document.getElementById('campoContaVinculada');
    if (campoConta) campoConta.style.display = 'block';
    const accSelect = document.getElementById('cartaoAccountId');
    if (accSelect) accSelect.required = true;
    const aviso = document.getElementById('avisoSemContas');
    if (aviso) aviso.style.display = 'block';
}

function abrirPagamentoFatura(c) {
    document.getElementById('pagtoCardId').value = c.id;
    document.getElementById('tituloModalFatura').textContent = 'Pagar Fatura: ' + c.name;
    document.getElementById('descModalFatura').textContent = 'Total da fatura em aberto: R$ ' + parseFloat(c.limite_utilizado).toLocaleString('pt-BR', {minimumFractionDigits: 2});
    
    // Seleciona a conta vinculada ao cartão como padrão
    const selectAcc = document.querySelector('#modalPagamentoFatura select[name="account_id"]');
    if (selectAcc && c.account_id) {
        selectAcc.value = c.account_id;
    }

    document.getElementById('modalPagamentoFatura').style.display = 'flex';
}

function fecharModalFatura() {
    document.getElementById('modalPagamentoFatura').style.display = 'none';
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
