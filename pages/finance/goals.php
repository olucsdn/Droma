<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
Security::ensureGoalColumn($pdo);
Security::ensureTransferColumn($pdo);
CategoryService::ensureColumns($pdo);

$erro = $_GET['erro'] ?? '';
$sucesso = $_GET['sucesso'] ?? '';

// Helper para obter ou criar categoria de Metas com snapshot seguro
function obterOuCriarCategoriaMeta(PDO $pdo, int $userId, string $nome, string $type): int {
    return Security::getOrCreateCategory($pdo, $userId, $nome, $type);
}

// Carrega as contas do usuário com saldos atuais calculados
$stmtContas = $pdo->prepare("
    SELECT 
        a.id, 
        a.name, 
        a.type,
        a.initial_balance,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'income' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_receitas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND type = 'expense' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')) AS total_despesas,
        (SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) FROM transactions WHERE account_id = a.id AND user_id = a.user_id AND status = 'completed' AND visivel = 1 AND (transfer_id IS NOT NULL OR category_name = 'Transferência')) AS saldo_transferencias
    FROM accounts a
    WHERE a.user_id = ?
    ORDER BY a.name ASC
");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

foreach ($contas as &$c) {
    $c['saldo_atual'] = (float)$c['initial_balance'] + (float)$c['total_receitas'] - (float)$c['total_despesas'] + (float)$c['saldo_transferencias'];
}
unset($c);

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } elseif (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
        Security::flashRedirect('goals', 'sucesso', 'Essa operação já tinha sido registrada.');
    } else {
        $acao = $_POST['acao'] ?? '';

        // 1. CRIAR META FINANCEIRA
        if ($acao === 'criar') {
            $nome          = trim($_POST['name'] ?? '');
            $targetRaw     = str_replace(['R$', ' '], '', trim($_POST['target_amount'] ?? ''));
            $targetRaw     = str_contains($targetRaw, ',') ? str_replace(',', '.', str_replace('.', '', $targetRaw)) : $targetRaw;
            $deadline      = !empty($_POST['deadline']) ? $_POST['deadline'] : null;
            $accountId     = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
            $initialRaw    = str_replace(['R$', ' '], '', trim($_POST['initial_deposit'] ?? '0'));
            $initialRaw    = str_contains($initialRaw, ',') ? str_replace(',', '.', str_replace('.', '', $initialRaw)) : $initialRaw;
            $initialAmt    = (float)$initialRaw;

            if (empty($nome) || !is_numeric($targetRaw) || (float)$targetRaw <= 0) {
                $erro = 'Informe um nome para a meta e um valor-alvo válido maior que zero.';
            } else {
                $targetAmount = (float)$targetRaw;

                // Se informou depósito inicial, valida se a conta pertence ao usuário e tem saldo
                $saldoOrigem = 0.00;
                if ($initialAmt > 0) {
                    if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                        $erro = 'Selecione uma conta bancária válida para o aporte inicial.';
                    } else {
                        foreach ($contas as $c) {
                            if ((int)$c['id'] === $accountId) {
                                $saldoOrigem = (float)$c['saldo_atual'];
                                break;
                            }
                        }
                        if ($initialAmt > $saldoOrigem) {
                            $erro = "Saldo insuficiente na conta selecionada para o aporte inicial. Saldo disponível: R$ " . number_format($saldoOrigem, 2, ',', '.');
                        }
                    }
                }

                if (empty($erro)) {
                    try {
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("
                            INSERT INTO goals (user_id, name, target_amount, current_amount, deadline)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$userId, $nome, $targetAmount, $initialAmt, $deadline]);
                        $goalId = (int)$pdo->lastInsertId();

                        // Se houver aporte inicial com fundos da conta, gera o débito rastreado
                        if ($initialAmt > 0 && $accountId > 0) {
                            $catId = obterOuCriarCategoriaMeta($pdo, $userId, 'Meta Financeira', 'expense');
                            $descTx = "Aporte Inicial — Meta: " . $nome;
                            $stmtTx = $pdo->prepare("
                                INSERT INTO transactions 
                                    (user_id, account_id, goal_id, category_id, category_name, type, amount, description, date, status, urgency, visivel)
                                VALUES (?, ?, ?, ?, 'Meta Financeira', 'expense', ?, ?, CURDATE(), 'completed', 'baixa', 1)
                            ");
                            $stmtTx->execute([$userId, $accountId, $goalId, $catId, $initialAmt, $descTx]);
                        }

                        $pdo->commit();
                        Security::flashRedirect('goals', 'sucesso', "Meta '{$nome}' criada com sucesso!");

                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        error_log("Erro ao criar meta: " . $e->getMessage());
                        $erro = 'Erro ao salvar meta no banco de dados.';
                    }
                }
            }

        // 2. NOVO APORTE NA META (+ Guardar Dinheiro)
        } elseif ($acao === 'novo_aporte') {
            $goalId    = (int)($_POST['goal_id'] ?? 0);
            $accountId = (int)($_POST['account_id'] ?? 0);
            $valorRaw  = str_replace(['R$', ' '], '', trim($_POST['amount'] ?? ''));
            $valorRaw  = str_contains($valorRaw, ',') ? str_replace(',', '.', str_replace('.', '', $valorRaw)) : $valorRaw;
            $dataAporte = Security::sanitizeDate($_POST['date'] ?? null, date('Y-m-d'));

            if (!Security::validateGoalOwnership($pdo, $goalId, $userId)) {
                $erro = 'Meta financeira não encontrada ou não autorizada.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Selecione uma conta válida de onde sairá o dinheiro.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
                $erro = 'Informe um valor válido maior que zero para o aporte.';
            } else {
                $valor = (float)$valorRaw;

                try {
                    $pdo->beginTransaction();

                    // Trava a conta e recalcula o saldo atual sob bloqueio
                    $stmtAcc = $pdo->prepare("SELECT id, name, initial_balance FROM accounts WHERE id = ? AND user_id = ? FOR UPDATE");
                    $stmtAcc->execute([$accountId, $userId]);
                    $accRow = $stmtAcc->fetch(PDO::FETCH_ASSOC);

                    if (!$accRow) {
                        $pdo->rollBack();
                        $erro = 'Conta de origem não encontrada.';
                    } else {
                        $stmtSaldo = $pdo->prepare("
                            SELECT
                                COALESCE(SUM(CASE WHEN type = 'income' AND status = 'completed' AND visivel = 1 THEN amount ELSE 0 END), 0) AS receitas,
                                COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'completed' AND visivel = 1 THEN amount ELSE 0 END), 0) AS despesas,
                                COALESCE(SUM(CASE WHEN transfer_id IS NOT NULL AND visivel = 1 AND status = 'completed' THEN (CASE WHEN type = 'income' THEN amount ELSE -amount END) ELSE 0 END), 0) AS transf
                            FROM transactions
                            WHERE account_id = ? AND user_id = ?
                        ");
                        $stmtSaldo->execute([$accountId, $userId]);
                        $s = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
                        $saldoOrigem = (float)$accRow['initial_balance'] + (float)$s['receitas'] - (float)$s['despesas'] + (float)$s['transf'];
                        $nomeConta = $accRow['name'];

                        if ($valor > $saldoOrigem) {
                            $pdo->rollBack();
                            $erro = "Saldo insuficiente na conta '{$nomeConta}'. Saldo disponível: R$ " . number_format($saldoOrigem, 2, ',', '.') . ".";
                        } else {
                            // Trava e busca nome da meta
                            $stmtG = $pdo->prepare("SELECT name FROM goals WHERE id = ? AND user_id = ? FOR UPDATE");
                            $stmtG->execute([$goalId, $userId]);
                            $nomeMeta = $stmtG->fetchColumn() ?: 'Meta';

                            // 1. Débito rastreado na conta de origem
                            $catId = obterOuCriarCategoriaMeta($pdo, $userId, 'Meta Financeira', 'expense');
                            $descTx = "Aporte na Meta: " . $nomeMeta;
                            $stmtTx = $pdo->prepare("
                                INSERT INTO transactions 
                                    (user_id, account_id, goal_id, category_id, category_name, type, amount, description, date, status, urgency, visivel)
                                VALUES (?, ?, ?, ?, 'Meta Financeira', 'expense', ?, ?, ?, 'completed', 'baixa', 1)
                            ");
                            $stmtTx->execute([$userId, $accountId, $goalId, $catId, $valor, $descTx, $dataAporte]);

                            // 2. Incrementa valor acumulado na meta
                            $stmtUpd = $pdo->prepare("UPDATE goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
                            $stmtUpd->execute([$valor, $goalId, $userId]);

                            $pdo->commit();
                            Security::flashRedirect('goals', 'sucesso', "R$ " . number_format($valor, 2, ',', '.') . " guardados com sucesso na meta '{$nomeMeta}'!");
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Erro ao realizar aporte na meta: " . $e->getMessage());
                    $erro = 'Ocorreu um erro ao processar o aporte na meta.';
                }
            }

        // 3. NOVO RESGATE DA META (⇄ Devolver dinheiro para a conta)
        } elseif ($acao === 'novo_resgate') {
            $goalId    = (int)($_POST['goal_id'] ?? 0);
            $accountId = (int)($_POST['account_id'] ?? 0);
            $valorRaw  = str_replace(['R$', ' '], '', trim($_POST['amount'] ?? ''));
            $valorRaw  = str_contains($valorRaw, ',') ? str_replace(',', '.', str_replace('.', '', $valorRaw)) : $valorRaw;
            $dataResgate = Security::sanitizeDate($_POST['date'] ?? null, date('Y-m-d'));

            if (!Security::validateGoalOwnership($pdo, $goalId, $userId)) {
                $erro = 'Meta financeira não encontrada ou não autorizada.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Selecione a conta bancária para onde o dinheiro deve retornar.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
                $erro = 'Informe um valor válido maior que zero para o resgate.';
            } else {
                $valor = (float)$valorRaw;

                try {
                    $pdo->beginTransaction();

                    // Trava a meta e valida o saldo dentro da transação
                    $stmtG = $pdo->prepare("SELECT name, current_amount FROM goals WHERE id = ? AND user_id = ? FOR UPDATE");
                    $stmtG->execute([$goalId, $userId]);
                    $metaData = $stmtG->fetch(PDO::FETCH_ASSOC);

                    if (!$metaData) {
                        $pdo->rollBack();
                        $erro = 'Meta não localizada.';
                    } else {
                        $saldoMeta = (float)$metaData['current_amount'];
                        if ($valor > round($saldoMeta + 0.009, 2)) {
                            $pdo->rollBack();
                            $erro = "O valor do resgate (R$ " . number_format($valor, 2, ',', '.') . ") excede o valor acumulado nesta meta (R$ " . number_format($saldoMeta, 2, ',', '.') . ").";
                        } else {
                            // 1. Crédito rastreado na conta de destino
                            $catId = obterOuCriarCategoriaMeta($pdo, $userId, 'Meta Financeira', 'income');
                            $descTx = "Resgate da Meta: " . $metaData['name'];
                            $stmtTx = $pdo->prepare("
                                INSERT INTO transactions 
                                    (user_id, account_id, goal_id, category_id, category_name, type, amount, description, date, status, urgency, visivel)
                                VALUES (?, ?, ?, ?, 'Meta Financeira', 'income', ?, ?, ?, 'completed', 'baixa', 1)
                            ");
                            $stmtTx->execute([$userId, $accountId, $goalId, $catId, $valor, $descTx, $dataResgate]);

                            // 2. Decrementa valor acumulado na meta
                            $stmtUpd = $pdo->prepare("UPDATE goals SET current_amount = current_amount - ? WHERE id = ? AND user_id = ?");
                            $stmtUpd->execute([$valor, $goalId, $userId]);

                            $pdo->commit();
                            Security::flashRedirect('goals', 'sucesso', "R$ " . number_format($valor, 2, ',', '.') . " resgatados da meta '{$metaData['name']}' com sucesso!");
                        }
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Erro ao resgatar da meta: " . $e->getMessage());
                    $erro = 'Ocorreu um erro ao processar o resgate da meta.';
                }
            }

        // 4. EXCLUIR META
        } elseif ($acao === 'excluir') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            if (!Security::validateGoalOwnership($pdo, $goalId, $userId)) {
                $erro = 'Meta inválida ou não autorizada.';
            } else {
                try {
                    $stmt = $pdo->prepare("DELETE FROM goals WHERE id = ? AND user_id = ?");
                    $stmt->execute([$goalId, $userId]);
                    Security::flashRedirect('goals', 'sucesso', 'Meta removida com sucesso.');
                } catch (Throwable $e) {
                    error_log("Erro ao excluir meta: " . $e->getMessage());
                    $erro = 'Erro ao excluir a meta financeira.';
                }
            }
        }
    }
}

// Carrega as metas do usuário com cálculos atômicos de aportes e resgates vinculados
$stmt = $pdo->prepare("
    SELECT 
        g.*,
        COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' AND t.visivel = 1 THEN t.amount ELSE 0 END), 0) AS total_aportes,
        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' AND t.visivel = 1 THEN t.amount ELSE 0 END), 0) AS total_resgates,
        COUNT(CASE WHEN t.visivel = 1 THEN t.id ELSE NULL END) AS qtd_movimentacoes
    FROM goals g
    LEFT JOIN transactions t ON t.goal_id = g.id AND t.user_id = g.user_id
    WHERE g.user_id = ?
    GROUP BY g.id, g.user_id, g.name, g.target_amount, g.current_amount, g.deadline, g.created_at, g.updated_at
    ORDER BY g.deadline ASC, g.id DESC
");
$stmt->execute([$userId]);
$metas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totais gerais consolidados das metas
$totalAcumuladoGeral = 0.00;
$totalAlvoGeral = 0.00;

foreach ($metas as $m) {
    $totalAcumuladoGeral += (float)$m['current_amount'];
    $totalAlvoGeral += (float)$m['target_amount'];
}
$pctGeral = $totalAlvoGeral > 0 ? min(100, round(($totalAcumuladoGeral / $totalAlvoGeral) * 100, 1)) : 0.0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Metas Financeiras - Droma</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
    <style>
        .btn-acao-meta {
            padding: 6px 12px;
            font-size: 11.5px;
            font-weight: 700;
            border-radius: var(--radius-sm, 6px);
            border: 1px solid var(--color-border);
            background: var(--color-surface);
            color: var(--color-text);
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
        }
        .btn-acao-meta:hover {
            transform: translateY(-1px);
        }
        .btn-meta-aporte {
            border-color: rgba(0, 200, 150, 0.4);
            color: var(--color-emerald);
        }
        .btn-meta-aporte:hover {
            background: rgba(0, 200, 150, 0.12);
            border-color: var(--color-emerald);
        }
        .btn-meta-resgate {
            border-color: rgba(212, 175, 108, 0.4);
            color: var(--color-gold);
        }
        .btn-meta-resgate:hover {
            background: rgba(212, 175, 108, 0.12);
            border-color: var(--color-gold);
        }
        .modal-card-meta {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg, 16px);
            padding: 24px;
            max-width: min(440px, calc(100vw - 24px));
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'metas';
$tituloPagina = 'Metas Financeiras';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($erro)): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 13.5px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div class="alerta-dashboard">
            <span><?= htmlspecialchars($sucesso) ?></span>
            <span style="cursor:pointer; font-weight:bold; font-size:16px;" onclick="this.parentElement.remove();">&times;</span>
        </div>
    <?php endif; ?>

    <!-- Resumo Consolidado das Metas (Caixinhas de Economia) -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Total Guardado em Metas</span>
            <span class="valor positivo">R$ <?= number_format($totalAcumuladoGeral, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Capital reservado em objetivos
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Alvo Total Planejado</span>
            <span class="valor" style="color:var(--color-gold);">R$ <?= number_format($totalAlvoGeral, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Soma de todos os objetivos
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Progresso Geral</span>
            <span class="valor" style="color:var(--color-emerald);"><?= $pctGeral ?>%</span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Média de conclusão dos planos
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Metas Ativas</span>
            <span class="valor"><?= count($metas) ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Planos em acompanhamento
            </span>
        </div>
    </section>

    <section class="grade">

        <!-- Lista e acompanhamento de metas -->
        <div class="bloco">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2 style="margin:0;">Suas Caixinhas e Metas</h2>
                <span style="font-size:12px; color:var(--color-text-secondary);">Guarde e resgate com débito/crédito real</span>
            </div>

            <?php if (empty($metas)): ?>
                <div class="droma-empty" style="margin-top:14px;">
                    <p>Nenhuma meta cadastrada ainda. Use o formulário ao lado para planejar seus objetivos (Reserva de Emergência, Viagem, etc.).</p>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:16px; margin-top:16px;">
                    <?php foreach ($metas as $m): 
                        $currentAmt = (float)$m['current_amount'];
                        $targetAmt  = (float)$m['target_amount'];
                        $pct        = $targetAmt > 0 ? min(100, round(($currentAmt / $targetAmt) * 100)) : 0;
                        $restam     = max(0.0, $targetAmt - $currentAmt);
                    ?>
                        <div class="cartao-meta">
                            <div class="meta-topo">
                                <div>
                                    <strong style="font-size:15px;"><?= htmlspecialchars($m['name']) ?></strong>
                                    <?php if ($currentAmt >= $targetAmt && $targetAmt > 0): ?>
                                        <span style="font-size:11px; background:rgba(0,200,150,0.15); color:var(--color-emerald); padding:2px 6px; border-radius:4px; margin-left:6px; font-weight:700;">✓ CONCLUÍDA</span>
                                    <?php endif; ?>
                                </div>
                                <span class="meta-pct"><?= $pct ?>%</span>
                            </div>

                            <div class="meta-barra">
                                <div class="meta-progresso" style="width: <?= $pct ?>%;"></div>
                            </div>

                            <div class="meta-valores">
                                <span>R$ <?= number_format($currentAmt, 2, ',', '.') ?> acumulado</span>
                                <span><?= $restam > 0 ? 'Faltam R$ ' . number_format($restam, 2, ',', '.') : 'Meta atingida!' ?> · Alvo: R$ <?= number_format($targetAmt, 2, ',', '.') ?></span>
                            </div>

                            <?php if (!empty($m['deadline'])): ?>
                                <?php
                                    $hojeDt = new DateTime('today');
                                    $prazoDt = new DateTime($m['deadline']);
                                    $mesesRestantes = max(1, (($prazoDt->format('Y') - $hojeDt->format('Y')) * 12) + ($prazoDt->format('n') - $hojeDt->format('n')));
                                    $aporteMensal = $restam > 0 ? ($restam / $mesesRestantes) : 0.0;
                                    $atrasada = ($prazoDt < $hojeDt && $restam > 0);
                                ?>
                                <span style="font-size:11.5px; color:var(--color-text-secondary);">
                                    📅 Prazo limite: <?= date('d/m/Y', strtotime($m['deadline'])) ?>
                                    <?php if ($atrasada): ?>
                                        · <strong style="color:var(--color-danger);">Prazo vencido</strong>
                                    <?php elseif ($restam > 0): ?>
                                        · Plano: <strong style="color:var(--color-gold);">R$ <?= number_format($aporteMensal, 2, ',', '.') ?>/mês</strong> (<?= $mesesRestantes ?> <?= $mesesRestantes === 1 ? 'mês' : 'meses' ?>)
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>

                            <!-- Ações Financeiras Reais: Guardar, Resgatar, Extrato e Excluir -->
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-top:10px; border-top:1px solid var(--color-border); padding-top:10px;">
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" class="btn-acao-meta btn-meta-aporte" onclick="abrirModalAporte(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)">
                                        + Guardar
                                    </button>
                                    
                                    <button type="button" class="btn-acao-meta btn-meta-resgate" onclick="abrirModalResgate(<?= htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8') ?>)" <?= $currentAmt <= 0 ? 'disabled style="opacity:0.4; cursor:not-allowed;"' : '' ?>>
                                        ⇄ Resgatar
                                    </button>

                                    <a href="statement?busca=<?= urlencode($m['name']) ?>" class="btn-acao-meta" title="Ver movimentações desta meta no extrato">
                                        Extrato <?= ($m['qtd_movimentacoes'] > 0) ? '(' . $m['qtd_movimentacoes'] . ')' : '' ?>
                                    </a>
                                </div>

                                <form action="goals" method="POST" data-confirm="Deseja realmente excluir esta meta? Se houver transações vinculadas, elas permanecerão registradas no seu extrato." data-confirm-title="Excluir Meta?">
                                    <?= Security::csrfField() ?>
                                    <input type="hidden" name="acao" value="excluir">
                                    <input type="hidden" name="goal_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;">Excluir</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Formulário para nova meta -->
        <div class="bloco">
            <h2>+ Nova Meta</h2>
            <form action="goals" method="POST" class="form-lancamento" style="max-width:100%; padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="criar">

                <label>Nome do Objetivo / Meta
                    <input type="text" name="name" placeholder="Ex: Reserva de emergência, Viagem, Carro..." required>
                </label>

                <label>Valor Alvo (R$)
                    <input type="text" name="target_amount" placeholder="Ex: 5000,00" required>
                </label>

                <label>Conta para Aporte Inicial (Opcional)
                    <select name="account_id">
                        <option value="">Nenhum aporte inicial (Começar com R$ 0,00)</option>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?> (Saldo disponível: R$ <?= number_format($c['saldo_atual'], 2, ',', '.') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Valor do Aporte Inicial (R$)
                    <input type="text" name="initial_deposit" placeholder="0,00 (sai da conta selecionada)">
                </label>

                <label>Data Limite (Opcional)
                    <input type="date" name="deadline">
                </label>

                <button type="submit">Criar Meta Financeira</button>
            </form>
        </div>

    </section>

    <!-- Modal Rápido: Guardar Dinheiro (+ Aporte) -->
    <div id="modalAporte" onclick="if(event.target===this) fecharModais();" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
        <div class="modal-card-meta">
            <h2 id="tituloModalAporte" style="margin-top:0; color:var(--color-emerald);">+ Guardar Dinheiro na Meta</h2>
            <p id="descModalAporte" style="font-size:13px; color:var(--color-text-secondary); margin-bottom:16px;">
                O valor será debitado da conta de origem selecionada e alocado como capital reservado nesta meta.
            </p>

            <form action="goals" method="POST" class="form-lancamento" style="padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="novo_aporte">
                <input type="hidden" name="goal_id" id="aporteGoalId" value="">

                <label>Conta de Origem (Saída do Dinheiro)
                    <select name="account_id" id="aporteAccountId" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?> (Saldo: R$ <?= number_format($c['saldo_atual'], 2, ',', '.') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Valor a Guardar (R$)
                    <input type="text" name="amount" placeholder="0,00" required autofocus>
                </label>

                <label>Data do Aporte
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </label>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" style="flex:1;">Confirmar e Guardar</button>
                    <button type="button" class="sair" onclick="fecharModais()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Rápido: Resgatar Dinheiro (⇄ Resgate) -->
    <div id="modalResgate" onclick="if(event.target===this) fecharModais();" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
        <div class="modal-card-meta">
            <h2 id="tituloModalResgate" style="margin-top:0; color:var(--color-gold);">⇄ Resgatar Dinheiro da Meta</h2>
            <p id="descModalResgate" style="font-size:13px; color:var(--color-text-secondary); margin-bottom:16px;">
                O valor sairá da meta e voltará como saldo disponível na conta bancária de destino escolhida.
            </p>

            <form action="goals" method="POST" class="form-lancamento" style="padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="novo_resgate">
                <input type="hidden" name="goal_id" id="resgateGoalId" value="">

                <label>Conta de Destino (Receber o Dinheiro)
                    <select name="account_id" id="resgateAccountId" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>">
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Valor a Resgatar (R$)
                    <input type="text" name="amount" id="resgateAmountInput" placeholder="0,00" required autofocus>
                    <span id="avisoSaldoMeta" style="font-size:11.5px; color:var(--color-text-secondary); display:block; margin-top:2px;"></span>
                </label>

                <label>Data do Resgate
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </label>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" style="flex:1;">Confirmar Resgate</button>
                    <button type="button" class="sair" onclick="fecharModais()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

</main>

<script>
function abrirModalAporte(meta) {
    document.getElementById('aporteGoalId').value = meta.id;
    document.getElementById('tituloModalAporte').textContent = '+ Guardar na Meta: ' + meta.name;
    document.getElementById('modalAporte').style.display = 'flex';
}

function abrirModalResgate(meta) {
    document.getElementById('resgateGoalId').value = meta.id;
    document.getElementById('tituloModalResgate').textContent = '⇄ Resgatar da Meta: ' + meta.name;
    const maxVal = parseFloat(meta.current_amount || 0).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('avisoSaldoMeta').textContent = 'Saldo disponível nesta meta para resgate: R$ ' + maxVal;
    document.getElementById('modalResgate').style.display = 'flex';
}

function fecharModais() {
    document.getElementById('modalAporte').style.display = 'none';
    document.getElementById('modalResgate').style.display = 'none';
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
