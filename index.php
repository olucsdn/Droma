<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";
require_once "src/PlanningService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);
Security::ensureTransferColumn($pdo);

// Otimização: Consolida os cálculos de totais em 1 única viagem ao banco de dados (apenas registros ativos)
// Transferências internas (transfer_id IS NOT NULL) não são contabilizadas como receitas nem como despesas externas
$stmtTotais = $pdo->prepare("
    SELECT 
        (SELECT COALESCE(SUM(initial_balance), 0) FROM accounts WHERE user_id = ?) AS saldo_inicial,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL) AS total_receitas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'completed' AND visivel = 1 AND credit_card_id IS NULL) AS total_despesas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status != 'cancelled' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência') AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())) AS receitas_mes,
        ((SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status != 'cancelled' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência') AND (category_name IS NULL OR category_name != 'Pagamento de Cartão') AND id NOT IN (SELECT transaction_id FROM installments) AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE()))
         + (SELECT COALESCE(SUM(i.amount), 0) FROM installments i JOIN transactions t ON t.id = i.transaction_id JOIN credit_cards c ON c.id = i.credit_card_id WHERE c.user_id = ? AND t.visivel = 1 AND i.status != 'cancelled' AND MONTH(i.due_date) = MONTH(CURDATE()) AND YEAR(i.due_date) = YEAR(CURDATE()))) AS despesas_mes,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status = 'pending' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência') AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())) AS receitas_pendentes_mes,
        ((SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'pending' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência') AND (category_name IS NULL OR category_name != 'Pagamento de Cartão') AND id NOT IN (SELECT transaction_id FROM installments) AND MONTH(COALESCE(due_date, date)) = MONTH(CURDATE()) AND YEAR(COALESCE(due_date, date)) = YEAR(CURDATE()))
         + (SELECT COALESCE(SUM(i.amount), 0) FROM installments i JOIN transactions t ON t.id = i.transaction_id JOIN credit_cards c ON c.id = i.credit_card_id WHERE c.user_id = ? AND t.visivel = 1 AND i.status = 'pending' AND MONTH(i.due_date) = MONTH(CURDATE()) AND YEAR(i.due_date) = YEAR(CURDATE()))) AS despesas_pendentes_mes,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status != 'cancelled' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência') AND MONTH(date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))) AS receitas_mes_anterior,
        ((SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status != 'cancelled' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência') AND (category_name IS NULL OR category_name != 'Pagamento de Cartão') AND id NOT IN (SELECT transaction_id FROM installments) AND MONTH(date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)))
         + (SELECT COALESCE(SUM(i.amount), 0) FROM installments i JOIN transactions t ON t.id = i.transaction_id JOIN credit_cards c ON c.id = i.credit_card_id WHERE c.user_id = ? AND t.visivel = 1 AND i.status != 'cancelled' AND MONTH(i.due_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(i.due_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)))) AS despesas_mes_anterior
");
$stmtTotais->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
$totais = $stmtTotais->fetch(PDO::FETCH_ASSOC);

$projecao30 = PlanningService::saldoProjetado($pdo, $userId, 30);
$patrimonio = PlanningService::patrimonioLiquido($pdo, $userId);

// Verificação de contas a pagar com vencimento hoje
$stmtVencHoje = $pdo->prepare("
    SELECT (
        (SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'pending' AND visivel = 1 AND id NOT IN (SELECT transaction_id FROM installments) AND due_date = CURDATE()) +
        (SELECT COUNT(*) FROM installments i JOIN transactions t ON t.id = i.transaction_id JOIN credit_cards c ON c.id = i.credit_card_id WHERE c.user_id = ? AND i.status = 'pending' AND t.visivel = 1 AND i.due_date = CURDATE())
    ) AS total_contas_hoje,
    (
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'pending' AND visivel = 1 AND id NOT IN (SELECT transaction_id FROM installments) AND due_date = CURDATE()) +
        (SELECT COALESCE(SUM(i.amount), 0) FROM installments i JOIN transactions t ON t.id = i.transaction_id JOIN credit_cards c ON c.id = i.credit_card_id WHERE c.user_id = ? AND i.status = 'pending' AND t.visivel = 1 AND i.due_date = CURDATE())
    ) AS valor_contas_hoje
");
$stmtVencHoje->execute([$userId, $userId, $userId, $userId]);
$dadosVencHoje = $stmtVencHoje->fetch(PDO::FETCH_ASSOC);
$contasVencendoHoje = (int)($dadosVencHoje['total_contas_hoje'] ?? 0);
$valorVencendoHoje = (float)($dadosVencHoje['valor_contas_hoje'] ?? 0);

$saldoInicial            = (float) ($totais['saldo_inicial'] ?? 0);
$totalReceitasRealizadas = (float) ($totais['total_receitas'] ?? 0);
$totalDespesasRealizadas = (float) ($totais['total_despesas'] ?? 0);
$saldoTotal              = $saldoInicial + $totalReceitasRealizadas - $totalDespesasRealizadas;

$receitasMes          = (float) ($totais['receitas_mes'] ?? 0);
$despesasMes          = (float) ($totais['despesas_mes'] ?? 0);
$resultadoMes         = $receitasMes - $despesasMes;

$receitasMesAnterior  = (float) ($totais['receitas_mes_anterior'] ?? 0);
$despesasMesAnterior  = (float) ($totais['despesas_mes_anterior'] ?? 0);
$varReceitasPct       = $receitasMesAnterior > 0 ? round((($receitasMes - $receitasMesAnterior) / $receitasMesAnterior) * 100, 1) : null;
$varDespesasPct       = $despesasMesAnterior > 0 ? round((($despesasMes - $despesasMesAnterior) / $despesasMesAnterior) * 100, 1) : null;

$receitasPendentesMes = (float) ($totais['receitas_pendentes_mes'] ?? 0);
$despesasPendentesMes = (float) ($totais['despesas_pendentes_mes'] ?? 0);

// Previsão do Final do Mês: Saldo atual em conta + receitas pendentes do mês - despesas/parcelas pendentes do mês
// Elimina a dupla contagem (não soma saldo atual com entradas já realizadas no mês)
$fimDoMes = $saldoTotal + $receitasPendentesMes - $despesasPendentesMes;

// Despesas por categoria no mês atual com fallback para o snapshot histórico (ignora transferências internas)
$stmt = $pdo->prepare(
    "SELECT COALESCE(c.name, t.category_name, 'Outros') AS categoria, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
     WHERE t.user_id = ? AND t.type = 'expense' AND t.visivel = 1 AND t.transfer_id IS NULL AND (t.category_name IS NULL OR t.category_name != 'Transferência')
       AND (t.category_name IS NULL OR t.category_name != 'Pagamento de Cartão')
       AND t.id NOT IN (SELECT transaction_id FROM installments)
       AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
     GROUP BY COALESCE(c.name, t.category_name, 'Outros')
     ORDER BY total DESC"
);
$stmt->execute([$userId]);
$gastosPorCategoria = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Receitas por categoria no mês atual com fallback para o snapshot histórico (ignora transferências internas)
$stmt = $pdo->prepare(
    "SELECT COALESCE(c.name, t.category_name, 'Outros') AS categoria, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
     WHERE t.user_id = ? AND t.type = 'income' AND t.visivel = 1 AND t.transfer_id IS NULL AND (t.category_name IS NULL OR t.category_name != 'Transferência')
       AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
     GROUP BY COALESCE(c.name, t.category_name, 'Outros')
     ORDER BY total DESC"
);
$stmt->execute([$userId]);
$receitasPorCategoria = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Últimos lançamentos do mês atual (apenas ativos)
$stmt = $pdo->prepare(
    "SELECT t.id, t.transfer_id, t.goal_id, t.description, t.amount, t.type, t.date, t.due_date, t.status, t.urgency, COALESCE(c.name, t.category_name, 'Outros') AS categoria
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
     WHERE t.user_id = ? AND t.visivel = 1
       AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
     ORDER BY t.date DESC, t.id DESC
     LIMIT 8"
);
$stmt->execute([$userId]);
$ultimosLancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metas financeiras do usuário
$stmt = $pdo->prepare(
    "SELECT id, name, target_amount, current_amount, deadline
     FROM goals
     WHERE user_id = ?
     ORDER BY deadline ASC, id DESC"
);
$stmt->execute([$userId]);
$metas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categorias ativas com teto de gastos configurado para o mês atual
$stmtBudgets = $pdo->prepare(
    "SELECT 
        c.id, 
        c.name, 
        c.budget_limit,
        COALESCE(SUM(CASE WHEN t.visivel = 1 AND t.status = 'completed' AND t.transfer_id IS NULL AND (t.category_name IS NULL OR t.category_name != 'Transferência') AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE()) THEN t.amount ELSE 0 END), 0) AS gasto_mes_atual
     FROM categories c
     LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = c.user_id AND t.type = 'expense'
     WHERE c.user_id = ? AND c.visivel = 1 AND c.type = 'expense' AND c.budget_limit IS NOT NULL AND c.budget_limit > 0
     GROUP BY c.id, c.name, c.budget_limit
     ORDER BY (gasto_mes_atual / c.budget_limit) DESC, c.name ASC"
);
$stmtBudgets->execute([$userId]);
$tetosDoMes = $stmtBudgets->fetchAll(PDO::FETCH_ASSOC);

// Contas a pagar nos próximos 7 dias (pendentes)
$stmt7d = $pdo->prepare(
    "SELECT COUNT(*) AS qtd, COALESCE(SUM(amount), 0) AS total
     FROM transactions
     WHERE user_id = ? AND visivel = 1 AND type = 'expense' AND status = 'pending'
       AND COALESCE(due_date, date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
);
$stmt7d->execute([$userId]);
$vencer7d = $stmt7d->fetch(PDO::FETCH_ASSOC) ?: ['qtd' => 0, 'total' => 0];

$orcamentoTotal = 0.0;
$orcamentoUsado = 0.0;
foreach ($tetosDoMes as $b) {
    $orcamentoTotal += (float)$b['budget_limit'];
    $orcamentoUsado += (float)$b['gasto_mes_atual'];
}
$orcamentoPct = $orcamentoTotal > 0 ? round(($orcamentoUsado / $orcamentoTotal) * 100, 1) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - Painel Financeiro</title>
    <?php require_once "includes/head_favicons.php"; ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'painel';
$tituloPagina = 'Painel Geral';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($_GET['sucesso'])): ?>
        <div class="alerta-dashboard">
            <span><?= htmlspecialchars($_GET['sucesso']) ?></span>
            <span style="cursor:pointer; font-weight:bold; font-size:16px;" onclick="this.parentElement.remove();">&times;</span>
        </div>
    <?php endif; ?>

    <?php if ($contasVencendoHoje > 0): ?>
        <div style="background: linear-gradient(135deg, rgba(212, 175, 108, 0.15) 0%, rgba(184, 134, 61, 0.08) 100%); border: 1px solid rgba(212, 175, 108, 0.4); border-left: 4px solid var(--color-gold); padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 22px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 14px rgba(0,0,0,0.25);">
            <div style="display:flex; align-items:center; gap:12px;">
                <span style="font-size:22px;">🔔</span>
                <span style="font-size:13px; color:var(--color-text-primary); line-height:1.5;">
                    Você possui <strong><?= $contasVencendoHoje ?> conta(s)</strong> com vencimento para <strong>hoje</strong>, totalizando <strong style="color:var(--color-gold-light);">R$ <?= number_format($valorVencendoHoje, 2, ',', '.') ?></strong>.
                </span>
            </div>
            <a href="calendar" style="font-size:12px; font-weight:700; color:#0A0E17; background:linear-gradient(135deg, #D4AF6C 0%, #B8863D 100%); padding:7px 14px; border-radius:6px; text-decoration:none; white-space:nowrap; margin-left:12px;">
                Ver no Calendário &rarr;
            </a>
        </div>
    <?php endif; ?>

    <?php if (empty($ultimosLancamentos)): ?>
        <div class="droma-card" style="margin-bottom: 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
            <div>
                <div style="font-size:14px; font-weight:700; color:var(--droma-gold-text); margin-bottom:4px;">Primeiros passos no Droma</div>
                <div style="display:flex; flex-wrap:wrap; gap:14px; font-size:12.5px; color:var(--color-text-secondary);">
                    <span>✓ 1. <a href="accounts" style="color:inherit;">Conta e saldo inicial</a></span>
                    <span><?= !empty($tetosDoMes) ? '✓' : '○' ?> 2. <a href="categories" style="color:inherit;">Definir teto nas categorias</a></span>
                    <span>○ 3. <a href="add_expense" style="color:var(--droma-gold-text); font-weight:600;">Registrar primeiro lançamento</a></span>
                </div>
            </div>
            <a href="add_expense" class="btn-header-primary" style="text-decoration:none; padding:8px 16px; font-size:13px;">+ Começar agora</a>
        </div>
    <?php endif; ?>

    <section class="cartoes cartoes-bento-5">
        <div class="cartao cartao-hero">
            <span class="rotulo">Saldo Atual</span>
            <span class="valor">R$ <?= number_format($saldoTotal, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:2px;">
                Disponível consolidado nas contas
            </span>
        </div>
        <div class="cartao cartao-hero">
            <span class="rotulo">Fim do mês (Projeção)</span>
            <span class="valor <?= $fimDoMes >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($fimDoMes, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:2px;">
                Balanço do mês: <?= $resultadoMes >= 0 ? '+' : '−' ?> R$ <?= number_format(abs($resultadoMes), 2, ',', '.') ?>
            </span>
        </div>
        <div class="cartao">
            <span class="rotulo">Entradas do mês</span>
            <span class="valor positivo">+ R$ <?= number_format($receitasMes, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:<?= $varReceitasPct === null ? 'var(--color-text-secondary)' : ($varReceitasPct >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)') ?>; margin-top:2px;">
                <?php if ($varReceitasPct !== null): ?>
                    <?= $varReceitasPct >= 0 ? '▲ +' : '▼ −' ?><?= number_format(abs($varReceitasPct), 1, ',', '.') ?>% vs mês anterior
                <?php else: ?>
                    Mês anterior: R$ <?= number_format($receitasMesAnterior, 2, ',', '.') ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="cartao">
            <span class="rotulo">Despesas do mês</span>
            <span class="valor negativo">− R$ <?= number_format($despesasMes, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:<?= $varDespesasPct === null ? 'var(--color-text-secondary)' : ($varDespesasPct <= 0 ? 'var(--color-emerald)' : 'var(--color-danger)') ?>; margin-top:2px;">
                <?php if ($varDespesasPct !== null): ?>
                    <?= $varDespesasPct > 0 ? '▲ +' : '▼ −' ?><?= number_format(abs($varDespesasPct), 1, ',', '.') ?>% vs mês anterior
                <?php else: ?>
                    Mês anterior: R$ <?= number_format($despesasMesAnterior, 2, ',', '.') ?>
                <?php endif; ?>
            </span>
        </div>
        <a href="calendar" class="cartao" style="text-decoration:none; color:inherit;">
            <span class="rotulo">A pagar (7 dias)</span>
            <span class="valor <?= (float)$vencer7d['total'] > 0 ? 'negativo' : '' ?>">
                R$ <?= number_format((float)$vencer7d['total'], 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:2px;">
                <?= (int)$vencer7d['qtd'] ?> pendente(s)<?php if ($orcamentoPct !== null): ?> · Orçamento: <?= $orcamentoPct ?>%<?php endif; ?> →
            </span>
            <?php if ($orcamentoPct !== null): ?>
                <?php $budCls = $orcamentoPct >= 100 ? 'droma-progress-fill--danger' : ($orcamentoPct >= 80 ? 'droma-progress-fill--warning' : 'droma-progress-fill--emerald'); ?>
                <div class="droma-progress" style="margin-top:4px; height:5px;" aria-label="Uso do orçamento mensal">
                    <div class="droma-progress-fill <?= $budCls ?>" style="width: <?= min(100, $orcamentoPct) ?>%;"></div>
                </div>
            <?php endif; ?>
        </a>
    </section>

    <!-- Visão de Futuro & Patrimônio -->
    <section class="cartoes" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-top: -6px;">
        <div class="cartao">
            <span class="rotulo">Saldo Projetado (30 dias)</span>
            <span class="valor <?= $projecao30['saldo_final'] >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($projecao30['saldo_final'], 2, ',', '.') ?>
            </span>
            <span class="droma-subtext">
                <?php if ($projecao30['menor_saldo'] < 0): ?>
                    <span class="droma-text-danger">⚠ Risco de saldo negativo (R$ <?= number_format($projecao30['menor_saldo'], 2, ',', '.') ?> em <?= date('d/m', strtotime($projecao30['data_menor_saldo'])) ?>)</span>
                <?php else: ?>
                    Considerando pendentes, recorrências e faturas futuras
                <?php endif; ?>
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Patrimônio Líquido</span>
            <span class="valor <?= $patrimonio['net_worth'] >= 0 ? 'droma-text-gold' : 'negativo' ?>">
                R$ <?= number_format($patrimonio['net_worth'], 2, ',', '.') ?>
            </span>
            <span class="droma-subtext">
                Contas (R$ <?= number_format($patrimonio['accounts_total'], 2, ',', '.') ?>) + Invest. (R$ <?= number_format($patrimonio['investments_total'], 2, ',', '.') ?>) − Faturas (R$ <?= number_format($patrimonio['cards_debt'], 2, ',', '.') ?>)
            </span>
        </div>
    </section>

    <section class="grade">

        <div class="bloco lancamentos">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h2 style="margin:0;">Últimos lançamentos</h2>
                <a href="statement" class="sair" style="font-size:12px; padding: 4px 10px;">Ver extrato completo →</a>
            </div>
            <?php if (empty($ultimosLancamentos)): ?>
                <div class="droma-empty">
                    <p>Nenhum lançamento registrado neste mês.</p>
                    <a href="add_expense" class="btn-header-primary" style="text-decoration:none; padding:8px 16px; font-size:13px;">+ Registrar primeiro lançamento</a>
                </div>
            <?php else: ?>
                <ul class="lista-lancamentos">
                    <?php
                    $hojeStr = date('Y-m-d');
                    $ontemStr = date('Y-m-d', strtotime('-1 day'));
                    $ultimoGrupoData = null;
                    foreach ($ultimosLancamentos as $l):
                        $dataTx = substr((string)$l['date'], 0, 10);
                        $grupoLabel = $dataTx === $hojeStr ? 'Hoje' : ($dataTx === $ontemStr ? 'Ontem' : date('d/m/Y', strtotime($dataTx)));
                        if ($grupoLabel !== $ultimoGrupoData):
                            $ultimoGrupoData = $grupoLabel;
                    ?>
                        <li class="droma-date-group-label" style="border-bottom:1px solid var(--droma-border-hairline); padding:8px 0 4px; display:block;"><?= $grupoLabel ?></li>
                    <?php endif; ?>
                        <?php $catNome = (string)($l['categoria'] ?? 'Outros'); $catCor = Security::categoryColor($catNome); ?>
                        <li>
                            <div class="info">
                                <span class="descricao">
                                    <?php if (!empty($l['urgency'])): ?>
                                        <span class="droma-dot-urgency droma-dot-urgency--<?= htmlspecialchars($l['urgency']) ?>" title="Urgência: <?= htmlspecialchars($l['urgency']) ?>"></span>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($l['description'] ?: 'Sem descrição') ?>
                                </span>
                                <span class="categoria">
                                    <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:<?= $catCor ?>; vertical-align:middle;"></span>
                                    <?= htmlspecialchars($catNome) ?>
                                    · <span class="badge-status badge-<?= $l['status'] ?>"><?= $l['status'] === 'completed' ? 'Pago' : ($l['status'] === 'cancelled' ? 'Cancelado' : 'Pendente') ?></span>
                                    <?php if (!empty($l['due_date'])): ?> · vence <?= date('d/m/Y', strtotime($l['due_date'])) ?><?php endif; ?>
                                </span>
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                <span class="valor-item <?= $l['type'] === 'income' ? 'positivo' : 'negativo' ?>">
                                    <?= $l['type'] === 'income' ? '↙ +' : '↗ −' ?> R$ <?= number_format($l['amount'], 2, ',', '.') ?>
                                </span>
                                <div style="display:flex; gap:6px;">
                                    <?php if (!empty($l['transfer_id'])): ?>
                                        <a href="transfer" class="btn-acao-lancamento" title="Gerenciar transferência">Transferência</a>
                                    <?php elseif (!empty($l['goal_id'])): ?>
                                        <a href="goals" class="btn-acao-lancamento" title="Gerenciar meta">Meta</a>
                                    <?php else: ?>
                                        <a href="edit_expense?id=<?= $l['id'] ?>" class="btn-acao-lancamento" title="Editar este lançamento">Editar</a>
                                    <?php endif; ?>
                                    <form action="delete_expense" method="POST" style="margin:0; display:inline;" data-confirm="Deseja realmente excluir este lançamento?" data-confirm-title="Excluir Lançamento?">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;" title="Excluir lançamento">Excluir</button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="bloco grafico">
            <div class="cabecalho-secao cabecalho-grafico">
                <div class="aba-grafico" style="margin-bottom:0;">
                    <button type="button" class="btn-aba ativo" id="btnDespesas">Despesas</button>
                    <button type="button" class="btn-aba" id="btnReceitas">Receitas</button>
                    <button type="button" class="btn-aba" id="btnComparativo">Comparativo</button>
                </div>
            </div>

            <div id="totalTipoExibicao" class="total-exibicao-grafico"></div>

            <div id="containerGraficoCategorias" class="grafico-categorias-container">
                <canvas id="graficoCategorias"></canvas>
            </div>

            <div id="containerDetalhesCategorias" class="lista-detalhes-categorias"></div>

        </div>

    </section>

    <?php if (!empty($tetosDoMes)): ?>
        <section class="secao-metas" style="margin-bottom: 24px;">
            <div class="bloco">
                <div class="cabecalho-secao cabecalho-metas">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <h2 style="margin:0;">Tetos do Mês (Orçamento)</h2>
                        <span style="font-size:11px; font-weight:700; background:rgba(212,175,108,0.15); color:var(--color-gold); padding:2px 8px; border-radius:4px;">Controle</span>
                    </div>
                    <a class="sair" href="categories" style="font-size:12px; padding: 5px 12px;">Gerenciar Tetos</a>
                </div>

                <div class="grade-metas">
                    <?php foreach ($tetosDoMes as $teto): 
                        $limite = (float)$teto['budget_limit'];
                        $gasto = (float)$teto['gasto_mes_atual'];
                        $pct = $limite > 0 ? round(($gasto / $limite) * 100, 1) : 0;
                        $barWidth = min(100, $pct);
                        $statusCor = $pct > 100 ? 'var(--color-danger)' : ($pct >= 70 ? 'var(--color-gold)' : 'var(--color-emerald)');
                        $statusBadge = $pct > 100 ? 'Estourado' : ($pct >= 70 ? 'Atenção' : 'No teto');
                    ?>
                        <div class="cartao-meta">
                            <div class="meta-topo">
                                <span style="font-weight:700;"><?= htmlspecialchars($teto['name']) ?></span>
                                <span style="font-size:11px; font-weight:700; color:<?= $statusCor ?>;"><?= $statusBadge ?> (<?= $pct ?>%)</span>
                            </div>
                            <div class="meta-barra">
                                <div class="meta-progresso" style="width: <?= $barWidth ?>%; background: <?= $statusCor ?>;"></div>
                            </div>
                            <div class="meta-valores">
                                <span>Gasto: R$ <?= number_format($gasto, 2, ',', '.') ?></span>
                                <span>Teto: R$ <?= number_format($limite, 2, ',', '.') ?></span>
                            </div>
                            <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:3px;">
                                <span style="color:<?= $statusCor ?>; font-weight:600;">
                                    <?php if ($pct > 100): ?>
                                        + R$ <?= number_format($gasto - $limite, 2, ',', '.') ?> acima
                                    <?php else: ?>
                                        R$ <?= number_format($limite - $gasto, 2, ',', '.') ?> livres
                                    <?php endif; ?>
                                </span>
                                <span style="color:var(--color-text-secondary);"><?= date('m/Y') ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="secao-metas">
        <div class="bloco">
            <div class="cabecalho-secao cabecalho-metas">
                <h2 style="margin:0;">Metas Financeiras</h2>
                <a class="sair" href="goals" style="font-size:12px; padding: 5px 12px;">Gerenciar Metas</a>
            </div>

            <?php if (empty($metas)): ?>
                <p class="vazio" style="margin-top:14px;">Você ainda não possui metas cadastradas. Crie uma meta para acompanhar suas economias e sonhos!</p>
            <?php else: ?>
                <div class="grade-metas">
                    <?php foreach ($metas as $m): 
                        $pct = $m['target_amount'] > 0 ? min(100, round(($m['current_amount'] / $m['target_amount']) * 100)) : 0;
                    ?>
                        <div class="cartao-meta">
                            <div class="meta-topo">
                                <span><?= htmlspecialchars($m['name']) ?></span>
                                <span class="meta-pct"><?= $pct ?>%</span>
                            </div>
                            <div class="meta-barra">
                                <div class="meta-progresso" style="width: <?= $pct ?>%;"></div>
                            </div>
                            <div class="meta-valores">
                                <span>R$ <?= number_format($m['current_amount'], 2, ',', '.') ?> acumulado</span>
                                <span>Alvo: R$ <?= number_format($m['target_amount'], 2, ',', '.') ?></span>
                            </div>
                            <?php if (!empty($m['deadline'])): ?>
                                <span style="font-size:11px; color:var(--color-text-secondary);">Prazo: <?= date('d/m/Y', strtotime($m['deadline'])) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

</main>

<script src="js/chart.umd.min.js"></script>
<script>
if (typeof Chart === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js"><\/script>');
}
</script>
<script>
const dadosDespesas = <?= json_encode($gastosPorCategoria) ?>;
const dadosReceitas = <?= json_encode($receitasPorCategoria) ?>;

const palette = [
    '#D4AF6C', '#10B981', '#3B82F6', '#F59E0B', '#EC4899',
    '#8B5CF6', '#14B8A6', '#F97316', '#06B6D4', '#84CC16'
];

function corPorCategoria(nome) {
    const s = String(nome || '').trim().toLowerCase();
    let h = 0;
    for (let i = 0; i < s.length; i++) {
        h = ((h << 5) - h + s.charCodeAt(i)) | 0;
    }
    return palette[Math.abs(h) % palette.length];
}

const ctx = document.getElementById('graficoCategorias');
const containerGrafico = document.getElementById('containerGraficoCategorias');
const containerDetalhes = document.getElementById('containerDetalhesCategorias');
const totalTipoExibicao = document.getElementById('totalTipoExibicao');
const btnDespesas = document.getElementById('btnDespesas');
const btnReceitas = document.getElementById('btnReceitas');
const btnComparativo = document.getElementById('btnComparativo');

let chartInstance = null;

function renderizarGrafico(lista, tituloTipo) {
    containerGrafico.classList.remove('modo-comparativo');
    const totalGeral = lista.reduce((acc, item) => acc + parseFloat(item.total), 0);
    const temDados = lista.length > 0 && totalGeral > 0;

    totalTipoExibicao.textContent = 'Total de ' + tituloTipo + ': R$ ' + totalGeral.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

    const labels = temDados ? lista.map(item => item.categoria) : ['Sem ' + tituloTipo.toLowerCase() + ' no mês'];
    const data = temDados ? lista.map(item => parseFloat(item.total)) : [1];
    const cores = temDados ? lista.map(item => corPorCategoria(item.categoria)) : ['#1A2A30'];

    if (chartInstance) {
        chartInstance.destroy();
    }

    chartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: cores,
                borderColor: '#0F171B',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: temDados,
                    backgroundColor: '#1A2A30',
                    borderColor: '#2B3A43',
                    borderWidth: 1,
                    titleColor: '#F4EFE3',
                    bodyColor: '#F4EFE3',
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            const val = context.parsed || 0;
                            const pct = totalGeral > 0 ? ((val / totalGeral) * 100).toFixed(1) : 0;
                            return ' ' + context.label + ': R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });

    // Renderiza lista detalhada com porcentagens
    containerDetalhes.innerHTML = '';
    if (!temDados) {
        containerDetalhes.innerHTML = '<p class="vazio" style="text-align:center; margin:8px 0;">Nenhum lançamento registrado neste mês.</p>';
        return;
    }

    lista.forEach((item, idx) => {
        const val = parseFloat(item.total);
        const pct = ((val / totalGeral) * 100).toFixed(1);
        const cor = cores[idx % cores.length];

        const catSpan = document.createElement('span');
        catSpan.textContent = item.categoria || '';

        const row = document.createElement('div');
        row.className = 'item-detalhe-cat';
        row.innerHTML = `
            <div style="display:flex; align-items:center;">
                <span class="cat-indicador" style="background:${cor};"></span>
                <span class="cat-nome-slot"></span>
            </div>
            <div style="display:flex; gap:12px; font-weight:bold;">
                <span style="color:var(--texto-secundario); font-size:12px;">${pct}%</span>
                <span>R$ ${val.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
            </div>
        `;
        row.querySelector('.cat-nome-slot').replaceWith(catSpan);
        containerDetalhes.appendChild(row);
    });
}

function renderizarGraficoComparativo() {
    containerGrafico.classList.add('modo-comparativo');
    const categorias = [...new Set([
        ...dadosReceitas.map(item => item.categoria),
        ...dadosDespesas.map(item => item.categoria)
    ])];
    const labels = categorias.length ? categorias : ['Sem lançamentos no período'];
    const totalPorCategoria = lista => lista.reduce((totais, item) => {
        totais[item.categoria] = (totais[item.categoria] || 0) + parseFloat(item.total);
        return totais;
    }, {});
    const entradas = totalPorCategoria(dadosReceitas);
    const saidas = totalPorCategoria(dadosDespesas);

    totalTipoExibicao.textContent = 'Entradas e saídas por categoria';
    containerDetalhes.innerHTML = '';

    if (chartInstance) {
        chartInstance.destroy();
    }

    chartInstance = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Entradas',
                    data: labels.map(categoria => Object.hasOwn(entradas, categoria) ? entradas[categoria] : null),
                    backgroundColor: 'rgba(0, 200, 150, 0.78)',
                    borderColor: '#00C896',
                    borderWidth: 1,
                    borderRadius: 5,
                    categoryPercentage: 0.72,
                    barPercentage: 0.9,
                    skipNull: true
                },
                {
                    label: 'Saídas',
                    data: labels.map(categoria => Object.hasOwn(saidas, categoria) ? saidas[categoria] : null),
                    backgroundColor: 'rgba(239, 68, 68, 0.78)',
                    borderColor: '#EF4444',
                    borderWidth: 1,
                    borderRadius: 5,
                    categoryPercentage: 0.72,
                    barPercentage: 0.9,
                    skipNull: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    offset: true,
                    ticks: { color: '#A7B0BA', maxRotation: 40, minRotation: 0 },
                    grid: { display: false }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#A7B0BA',
                        callback: value => 'R$ ' + Number(value).toLocaleString('pt-BR')
                    },
                    grid: { color: 'rgba(167, 176, 186, 0.12)' }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    labels: { color: '#F4EFE3', usePointStyle: true, padding: 16 }
                },
                tooltip: {
                    backgroundColor: '#1A2A30',
                    borderColor: '#2B3A43',
                    borderWidth: 1,
                    titleColor: '#F4EFE3',
                    bodyColor: '#F4EFE3',
                    callbacks: {
                        label: context => `${context.dataset.label}: R$ ${context.parsed.y.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`
                    }
                }
            }
        }
    });
}

btnDespesas.addEventListener('click', () => {
    btnDespesas.classList.add('ativo');
    btnReceitas.classList.remove('ativo');
    btnComparativo.classList.remove('ativo');
    renderizarGrafico(dadosDespesas, 'Despesas');
});

btnReceitas.addEventListener('click', () => {
    btnReceitas.classList.add('ativo');
    btnDespesas.classList.remove('ativo');
    btnComparativo.classList.remove('ativo');
    renderizarGrafico(dadosReceitas, 'Receitas');
});

btnComparativo.addEventListener('click', () => {
    btnComparativo.classList.add('ativo');
    btnDespesas.classList.remove('ativo');
    btnReceitas.classList.remove('ativo');
    renderizarGraficoComparativo();
});

// Inicializa mostrando Despesas
renderizarGrafico(dadosDespesas, 'Despesas');
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


