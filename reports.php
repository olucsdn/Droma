<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

// Anos disponíveis para seleção (com base nas movimentações do usuário)
$stmtAnos = $pdo->prepare("SELECT DISTINCT YEAR(date) AS ano FROM transactions WHERE user_id = ? ORDER BY ano DESC");
$stmtAnos->execute([$userId]);
$anosDisponiveis = $stmtAnos->fetchAll(PDO::FETCH_COLUMN);
$anoAtual = (int) date('Y');
if (!in_array($anoAtual, $anosDisponiveis)) {
    array_unshift($anosDisponiveis, $anoAtual);
}

// Filtros recebidos
$anoSelecionado = isset($_GET['ano']) && is_numeric($_GET['ano']) ? (int) $_GET['ano'] : $anoAtual;
$mesSelecionado = isset($_GET['mes']) && is_numeric($_GET['mes']) ? (int) $_GET['mes'] : 0; // 0 = ano todo

$nomesMeses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// 1. Fluxo Mensal de todo o ano selecionado (12 meses)
$fluxoMensal = [];
for ($m = 1; $m <= 12; $m++) {
    $fluxoMensal[$m] = [
        'mes_num'    => $m,
        'mes_nome'   => $nomesMeses[$m],
        'receitas'   => 0.00,
        'despesas'   => 0.00,
        'saldo'      => 0.00,
        'poupanca'   => 0.0
    ];
}

$stmtFluxo = $pdo->prepare("
    SELECT MONTH(date) AS mes, type, SUM(amount) AS total
    FROM transactions
    WHERE user_id = ? AND YEAR(date) = ? AND status = 'completed'
    GROUP BY MONTH(date), type
");
$stmtFluxo->execute([$userId, $anoSelecionado]);
$rowsFluxo = $stmtFluxo->fetchAll(PDO::FETCH_ASSOC);

foreach ($rowsFluxo as $r) {
    $m = (int) $r['mes'];
    $val = (float) $r['total'];
    if ($r['type'] === 'income') {
        $fluxoMensal[$m]['receitas'] += $val;
    } else {
        $fluxoMensal[$m]['despesas'] += $val;
    }
}

$totalReceitasAno = 0.0;
$totalDespesasAno = 0.0;
foreach ($fluxoMensal as $m => &$item) {
    $item['saldo'] = $item['receitas'] - $item['despesas'];
    $item['poupanca'] = $item['receitas'] > 0 ? round(($item['saldo'] / $item['receitas']) * 100, 1) : 0.0;
    $totalReceitasAno += $item['receitas'];
    $totalDespesasAno += $item['despesas'];
}
unset($item);

// 2. Despesas e Receitas por Categoria no recorte selecionado (ano todo ou mês específico)
$whereCat = "t.user_id = ? AND YEAR(t.date) = ? AND t.status = 'completed'";
$paramsCat = [$userId, $anoSelecionado];
if ($mesSelecionado > 0) {
    $whereCat .= " AND MONTH(t.date) = ?";
    $paramsCat[] = $mesSelecionado;
}

// Despesas por Categoria
$stmtDespCat = $pdo->prepare("
    SELECT COALESCE(c.name, 'Sem categoria') AS categoria,
           SUM(t.amount) AS total,
           COUNT(t.id) AS qtd
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE {$whereCat} AND t.type = 'expense'
    GROUP BY c.id, c.name
    ORDER BY total DESC
");
$stmtDespCat->execute($paramsCat);
$despesasPorCategoria = $stmtDespCat->fetchAll(PDO::FETCH_ASSOC);

// Receitas por Categoria
$stmtRecCat = $pdo->prepare("
    SELECT COALESCE(c.name, 'Sem categoria') AS categoria,
           SUM(t.amount) AS total,
           COUNT(t.id) AS qtd
    FROM transactions t
    LEFT JOIN categories c ON c.id = t.category_id
    WHERE {$whereCat} AND t.type = 'income'
    GROUP BY c.id, c.name
    ORDER BY total DESC
");
$stmtRecCat->execute($paramsCat);
$receitasPorCategoria = $stmtRecCat->fetchAll(PDO::FETCH_ASSOC);

// Paleta visual consistente para colorir categorias no gráfico e barras
$palette = ['#D4AF6C', '#00C896', '#3282F6', '#A78BFA', '#F472B6', '#E5B85C', '#64748B', '#38BDF8', '#F87171', '#34D399'];
foreach ($despesasPorCategoria as $idx => &$d) {
    $d['cor'] = $palette[$idx % count($palette)];
}
unset($d);

foreach ($receitasPorCategoria as $idx => &$r) {
    $r['cor'] = $palette[$idx % count($palette)];
}
unset($r);

// Totais do período selecionado
$totalDespPeriodo = array_sum(array_column($despesasPorCategoria, 'total'));
$totalRecPeriodo  = array_sum(array_column($receitasPorCategoria, 'total'));
$saldoPeriodo     = $totalRecPeriodo - $totalDespPeriodo;
$taxaPoupancaPeriodo = $totalRecPeriodo > 0 ? round(($saldoPeriodo / $totalRecPeriodo) * 100, 1) : 0.0;

// 3. Distribuição e Saldos por Conta Bancária
$stmtContas = $pdo->prepare("
    SELECT a.id, a.name, a.type, a.initial_balance,
           COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' THEN t.amount
                             WHEN t.type = 'expense' AND t.status = 'completed' THEN -t.amount
                             ELSE 0 END), 0) AS movimentacao
    FROM accounts a
    LEFT JOIN transactions t ON t.account_id = a.id AND t.user_id = a.user_id
    WHERE a.user_id = ?
    GROUP BY a.id, a.name, a.type, a.initial_balance
    ORDER BY a.name ASC
");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);
$saldoTotalContas = 0.0;
foreach ($contas as &$c) {
    $c['saldo_atual'] = (float)$c['initial_balance'] + (float)$c['movimentacao'];
    $saldoTotalContas += $c['saldo_atual'];
}
unset($c);

// 4. Cartões de Crédito — Compras no Período
$stmtCartoes = $pdo->prepare("
    SELECT cc.name AS cartao, cc.color, COUNT(t.id) AS compras, COALESCE(SUM(t.amount), 0) AS total
    FROM transactions t
    JOIN credit_cards cc ON cc.id = t.credit_card_id
    WHERE t.user_id = ? AND YEAR(t.date) = ? " . ($mesSelecionado > 0 ? "AND MONTH(t.date) = ?" : "") . "
    GROUP BY cc.id, cc.name, cc.color
    ORDER BY total DESC
");
$stmtCartoes->execute($paramsCat);
$comprasCartoes = $stmtCartoes->fetchAll(PDO::FETCH_ASSOC);

// Exportação CSV do Relatório
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $nomeArquivo = 'relatorio-droma-' . $anoSelecionado . ($mesSelecionado > 0 ? "-mes{$mesSelecionado}" : '') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    
    $saida = fopen('php://output', 'w');
    fprintf($saida, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

    fputcsv($saida, ['=== RELATORIO FINANCEIRO DROMA ==='], ';', '"', "\\");
    fputcsv($saida, ['Ano', $anoSelecionado, 'Mes', $mesSelecionado > 0 ? $nomesMeses[$mesSelecionado] : 'Ano Completo'], ';', '"', "\\");
    fputcsv($saida, ['Receitas Totais', number_format($totalRecPeriodo, 2, ',', '.')], ';', '"', "\\");
    fputcsv($saida, ['Despesas Totais', number_format($totalDespPeriodo, 2, ',', '.')], ';', '"', "\\");
    fputcsv($saida, ['Saldo Liquido', number_format($saldoPeriodo, 2, ',', '.')], ';', '"', "\\");
    fputcsv($saida, ['Taxa de Poupanca (%)', $taxaPoupancaPeriodo . '%'], ';', '"', "\\");
    fputcsv($saida, [], ';', '"', "\\");

    fputcsv($saida, ['=== FLUXO MENSAL DO ANO ==='], ';', '"', "\\");
    fputcsv($saida, ['Mes', 'Receitas (R$)', 'Despesas (R$)', 'Saldo (R$)', 'Taxa Poupanca (%)'], ';', '"', "\\");
    foreach ($fluxoMensal as $fm) {
        fputcsv($saida, [
            $fm['mes_nome'],
            number_format($fm['receitas'], 2, ',', '.'),
            number_format($fm['despesas'], 2, ',', '.'),
            number_format($fm['saldo'], 2, ',', '.'),
            $fm['poupanca'] . '%'
        ], ';', '"', "\\");
    }

    fputcsv($saida, [], ';', '"', "\\");
    fputcsv($saida, ['=== DESPESAS POR CATEGORIA ==='], ';', '"', "\\");
    fputcsv($saida, ['Categoria', 'Qtd Lancamentos', 'Total (R$)', 'Participacao (%)'], ';', '"', "\\");
    foreach ($despesasPorCategoria as $d) {
        $pct = $totalDespPeriodo > 0 ? round(($d['total'] / $totalDespPeriodo) * 100, 1) : 0;
        fputcsv($saida, [
            $d['categoria'],
            $d['qtd'],
            number_format($d['total'], 2, ',', '.'),
            $pct . '%'
        ], ';', '"', "\\");
    }

    fclose($saida);
    exit;
}

$tituloPeriodo = $mesSelecionado > 0 ? "{$nomesMeses[$mesSelecionado]} de {$anoSelecionado}" : "Ano de {$anoSelecionado}";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - Relatórios Analíticos</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <style>
        .relatorios-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 24px;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        .relatorios-titulo-bloco {
            flex: 1 1 280px;
            min-width: 0;
        }
        .relatorios-titulo-bloco h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--color-text);
            margin-bottom: 4px;
            line-height: 1.25;
            word-break: break-word;
        }
        .relatorios-titulo-bloco p {
            color: var(--color-text-secondary);
            font-size: 13.5px;
            line-height: 1.4;
            margin: 0;
            word-break: break-word;
        }
        .relatorios-controles {
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            min-width: 0;
            box-sizing: border-box;
        }
        .filtro-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--color-surface);
            padding: 10px 16px;
            border-radius: var(--radius-md);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-card);
            min-width: 0;
            box-sizing: border-box;
        }
        .filtro-grupo {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .filtro-grupo label {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--color-text-secondary);
            white-space: nowrap;
        }
        .filtro-grupo select {
            background: var(--color-surface-secondary);
            color: var(--color-text);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            padding: 7px 12px;
            font-size: 13px;
            font-weight: 500;
            outline: none;
            cursor: pointer;
            transition: border-color 160ms ease, background 160ms ease;
            min-width: 0;
            box-sizing: border-box;
        }
        .filtro-grupo select:focus {
            border-color: var(--color-gold);
            background: var(--color-surface-hover);
        }
        .acoes-export {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            text-align: center;
            margin: 0 auto;
        }
        .acoes-export .sair {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 12.5px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: var(--radius-md);
            transition: all 160ms ease;
        }
        @media (max-width: 768px) {
            .relatorios-header {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            .relatorios-controles {
                width: 100%;
                align-items: stretch;
            }
            .filtro-bar {
                width: 100%;
                display: flex;
                flex-direction: row;
                gap: 10px;
                padding: 12px;
            }
            .filtro-grupo {
                flex: 1;
                min-width: 0;
            }
            .filtro-grupo select {
                width: 100%;
            }
            .acoes-export {
                justify-content: center;
            }
        }
        @media (max-width: 480px) {
            .filtro-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 14px 12px;
            }
            .filtro-grupo {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
                width: 100%;
            }
            .filtro-grupo select {
                width: 100%;
                height: 42px;
            }
            .acoes-export {
                display: flex;
                justify-content: center;
                gap: 8px;
                width: 100%;
            }
            .acoes-export .sair {
                flex: 1;
                justify-content: center;
                padding: 10px 8px;
                font-size: 12px;
            }
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr));
            gap: 16px;
            margin-bottom: 24px;
            width: 100%;
            min-width: 0;
        }
        @media (max-width: 768px) {
            .kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
        }
        @media (max-width: 350px) {
            .kpi-grid {
                grid-template-columns: 1fr;
            }
        }
        .kpi-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
        }
        .kpi-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--color-gold);
        }
        .kpi-card.positivo::after { background: var(--color-emerald); }
        .kpi-card.negativo::after { background: var(--color-danger); }
        .kpi-card .rotulo {
            font-size: clamp(10px, 2.7vw, 12px);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--color-text-secondary);
            font-weight: 600;
            min-width: 0;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        .kpi-card .valor {
            font-size: clamp(16px, 4.2vw, 22px);
            font-weight: 800;
            color: var(--color-text);
            min-width: 0;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        .kpi-card .valor.positivo { color: var(--color-emerald); }
        .kpi-card .valor.negativo { color: var(--color-danger); }

        .charts-row {
            display: grid;
            grid-template-columns: minmax(0, 1.8fr) minmax(0, 1fr);
            gap: 20px;
            margin-bottom: 24px;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        @media (max-width: 980px) {
            .charts-row {
                grid-template-columns: minmax(0, 1fr) !important;
                gap: 16px;
            }
        }
        .chart-box {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-width: 0;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }
        @media (max-width: 480px) {
            .chart-box {
                padding: 14px 12px;
                border-radius: var(--radius-md);
            }
        }
        .chart-box h3 {
            margin: 0 0 16px 0;
            font-size: 15px;
            color: var(--color-gold-light);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
            min-width: 0;
            line-height: 1.3;
        }
        .chart-box h3 span {
            min-width: 0;
            overflow-wrap: break-word;
            word-break: break-word;
        }
        .chart-container {
            position: relative;
            flex: 1;
            min-height: 280px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            overflow: hidden;
        }
        .chart-container canvas {
            max-width: 100% !important;
            width: 100% !important;
        }
        .ranking-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 14px;
            max-height: 240px;
            overflow-y: auto;
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        .ranking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 12px;
            background: var(--color-surface-secondary);
            border-radius: var(--radius-sm);
            font-size: 13px;
            min-width: 0;
            box-sizing: border-box;
            gap: 8px;
        }
        .ranking-item-bar {
            height: 4px;
            background: var(--color-gold);
            border-radius: 2px;
            margin-top: 4px;
        }
        .tabela-fluxo-wrapper {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            box-sizing: border-box;
            border-radius: var(--radius-sm);
        }
        .tabela-fluxo-wrapper::-webkit-scrollbar {
            height: 6px;
        }
        .tabela-fluxo-wrapper::-webkit-scrollbar-thumb {
            background: var(--color-border);
            border-radius: 4px;
        }
        .tabela-fluxo {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
            min-width: 500px;
        }
        .tabela-fluxo th {
            padding: 10px 12px;
            color: var(--color-text-secondary);
            border-bottom: 1px solid var(--color-border);
            font-weight: 600;
            white-space: nowrap;
        }
        .tabela-fluxo td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text);
            white-space: nowrap;
        }
        .tabela-fluxo tr:hover td {
            background: var(--color-surface-secondary);
        }

        @media print {
            .topo, .filtro-bar, .acoes-export, a.sair, .btn-header-primary {
                display: none !important;
            }
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .kpi-card, .chart-box {
                background: #ffffff !important;
                border: 1px solid #cccccc !important;
                color: #000000 !important;
                box-shadow: none !important;
            }
            .kpi-card .valor, .chart-box h3 {
                color: #000000 !important;
            }
            .tabela-fluxo th, .tabela-fluxo td {
                color: #000000 !important;
                border-color: #dddddd !important;
            }
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'relatorios';
$tituloPagina = 'Relatórios Analíticos';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <div class="relatorios-header">
        <div class="relatorios-titulo-bloco">
            <h1>Relatórios Analíticos</h1>
            <p>
                Visão consolidada de receitas, despesas, margem de poupança e desempenho de contas em <strong style="color:var(--color-gold);"><?= htmlspecialchars($tituloPeriodo) ?></strong>.
            </p>
        </div>

        <div class="relatorios-controles">
            <form method="GET" action="reports.php" class="filtro-bar">
                <div class="filtro-grupo">
                    <label for="ano">Ano:</label>
                    <select name="ano" id="ano" onchange="this.form.submit()">
                        <?php foreach ($anosDisponiveis as $a): ?>
                            <option value="<?= $a ?>" <?= $a === $anoSelecionado ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filtro-grupo">
                    <label for="mes">Mês:</label>
                    <select name="mes" id="mes" onchange="this.form.submit()">
                        <option value="0" <?= $mesSelecionado === 0 ? 'selected' : '' ?>>Ano Completo (Consolidado)</option>
                        <?php foreach ($nomesMeses as $num => $nome): ?>
                            <option value="<?= $num ?>" <?= $mesSelecionado === $num ? 'selected' : '' ?>><?= $nome ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <div class="acoes-export">
                <a href="reports.php?ano=<?= $anoSelecionado ?>&mes=<?= $mesSelecionado ?>&export=csv" class="sair" title="Exportar CSV formatado">
                    📥 Exportar CSV
                </a>
                <button type="button" onclick="window.print()" class="sair" title="Imprimir relatório">
                    🖨️ Imprimir
                </button>
            </div>
        </div>
    </div>

    <!-- KPIs do Período -->
    <div class="kpi-grid">
        <div class="kpi-card positivo">
            <span class="rotulo">Receitas do Período</span>
            <span class="valor positivo">R$ <?= number_format($totalRecPeriodo, 2, ',', '.') ?></span>
        </div>
        <div class="kpi-card negativo">
            <span class="rotulo">Despesas do Período</span>
            <span class="valor negativo">R$ <?= number_format($totalDespPeriodo, 2, ',', '.') ?></span>
        </div>
        <div class="kpi-card <?= $saldoPeriodo >= 0 ? 'positivo' : 'negativo' ?>">
            <span class="rotulo">Saldo Líquido</span>
            <span class="valor <?= $saldoPeriodo >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($saldoPeriodo, 2, ',', '.') ?>
            </span>
        </div>
        <div class="kpi-card">
            <span class="rotulo">Taxa de Poupança</span>
            <span class="valor" style="color:var(--color-gold);">
                <?= $taxaPoupancaPeriodo ?>%
            </span>
        </div>
    </div>

    <!-- Linha 1 de Gráficos: Fluxo Anual e Distribuição por Categorias -->
    <div class="charts-row">
        <!-- Gráfico de Fluxo de Caixa Anual -->
        <div class="chart-box">
            <h3>
                <span>Evolução do Fluxo de Caixa (<?= $anoSelecionado ?>)</span>
                <span style="font-size:12px; font-weight:normal; color:var(--color-text-secondary);">Mês a Mês</span>
            </h3>
            <div class="chart-container">
                <canvas id="graficoFluxoAnual"></canvas>
            </div>
        </div>

        <!-- Donut de Despesas por Categoria -->
        <div class="chart-box">
            <h3>
                <span>Despesas por Categoria</span>
                <span style="font-size:12px; font-weight:normal; color:var(--color-text-secondary);"><?= $mesSelecionado > 0 ? $nomesMeses[$mesSelecionado] : 'Ano Todo' ?></span>
            </h3>
            <div class="chart-container" style="min-height: 220px; max-height:240px;">
                <canvas id="graficoDespesasCategorias"></canvas>
            </div>
            <div class="ranking-list">
                <?php if (empty($despesasPorCategoria)): ?>
                    <p style="font-size:13px; color:var(--color-text-secondary); text-align:center; padding:12px;">Nenhuma despesa registrada no período.</p>
                <?php else: ?>
                    <?php foreach ($despesasPorCategoria as $d): 
                        $pct = $totalDespPeriodo > 0 ? round(($d['total'] / $totalDespPeriodo) * 100, 1) : 0;
                    ?>
                        <div class="ranking-item">
                            <div style="flex:1; min-width:0;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:2px;">
                                    <strong style="min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1;"><?= htmlspecialchars($d['categoria']) ?></strong>
                                    <span style="flex-shrink:0; font-size:12px; font-variant-numeric:tabular-nums; color:var(--color-text-secondary);">R$ <?= number_format($d['total'], 2, ',', '.') ?> (<?= $pct ?>%)</span>
                                </div>
                                <div class="ranking-item-bar" style="width: <?= min(100, $pct) ?>%; background: <?= htmlspecialchars($d['cor']) ?>;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Linha 2: Tabela Detalhada dos 12 Meses e Saldos de Contas -->
    <div class="charts-row">
        <div class="chart-box">
            <h3>
                <span>Demonstrativo Mensal Consolidado</span>
                <a href="reports.php?ano=<?= $anoSelecionado ?>&export=csv" class="sair" style="font-size:12px; padding:4px 8px;">Baixar Tabela</a>
            </h3>
            <div class="tabela-fluxo-wrapper">
                <table class="tabela-fluxo">
                <thead>
                    <tr>
                        <th>Mês</th>
                        <th style="text-align:right;">Receitas (R$)</th>
                        <th style="text-align:right;">Despesas (R$)</th>
                        <th style="text-align:right;">Saldo (R$)</th>
                        <th style="text-align:right;">% Poupança</th>
                        <th style="text-align:center;">Situação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fluxoMensal as $fm): ?>
                        <tr>
                            <td><strong><?= $fm['mes_nome'] ?></strong></td>
                            <td style="text-align:right; color:var(--color-emerald); font-weight:600;">
                                <?= number_format($fm['receitas'], 2, ',', '.') ?>
                            </td>
                            <td style="text-align:right; color:var(--color-danger); font-weight:600;">
                                <?= number_format($fm['despesas'], 2, ',', '.') ?>
                            </td>
                            <td style="text-align:right; font-weight:700; color: <?= $fm['saldo'] >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                                <?= number_format($fm['saldo'], 2, ',', '.') ?>
                            </td>
                            <td style="text-align:right; color:var(--color-gold);">
                                <?= $fm['poupanca'] ?>%
                            </td>
                            <td style="text-align:center;">
                                <?php if ($fm['receitas'] == 0 && $fm['despesas'] == 0): ?>
                                    <span style="font-size:11px; color:var(--color-text-secondary);">Sem lançamentos</span>
                                </td>
                                <?php elseif ($fm['saldo'] >= 0): ?>
                                    <span style="background:rgba(0,200,150,0.15); color:var(--color-emerald); padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">Superávit</span>
                                <?php else: ?>
                                    <span style="background:rgba(239,68,68,0.15); color:var(--color-danger); padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">Déficit</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Composição de Saldos por Conta -->
        <div class="chart-box">
            <h3>
                <span>Saldos por Conta Bancária</span>
                <span style="font-size:12px; font-weight:normal; color:var(--color-text-secondary);">Total: R$ <?= number_format($saldoTotalContas, 2, ',', '.') ?></span>
            </h3>
            <div class="ranking-list" style="max-height: none;">
                <?php foreach ($contas as $c): ?>
                    <div class="ranking-item" style="padding:12px;">
                        <div>
                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                            <div style="font-size:11px; color:var(--color-text-secondary); text-transform:uppercase;">
                                <?= htmlspecialchars($c['type']) ?>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <span style="font-weight:700; font-size:14px; color: <?= $c['saldo_atual'] >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                                R$ <?= number_format($c['saldo_atual'], 2, ',', '.') ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($comprasCartoes)): ?>
                <h3 style="margin-top:24px;">
                    <span>Gastos em Cartões de Crédito</span>
                </h3>
                <div class="ranking-list" style="max-height: none;">
                    <?php foreach ($comprasCartoes as $cc): ?>
                        <div class="ranking-item">
                            <div>
                                <strong><?= htmlspecialchars($cc['cartao']) ?></strong>
                                <span style="font-size:11px; color:var(--color-text-secondary);"> (<?= $cc['compras'] ?> compras)</span>
                            </div>
                            <span style="color:var(--color-danger); font-weight:700;">
                                R$ <?= number_format($cc['total'], 2, ',', '.') ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<script src="js/chart.umd.min.js"></script>
<script>
if (typeof Chart === 'undefined') {
    document.write('<script src="https://cdn.jsdelivr.net/npm/chart.js"><\/script>');
}
</script>
<script>
const mesesLabels = <?= json_encode(array_map(function($m) { return mb_substr($m, 0, 3, 'UTF-8'); }, array_column($fluxoMensal, 'mes_nome'))) ?>;
const receitasData = <?= json_encode(array_column($fluxoMensal, 'receitas')) ?>;
const despesasData = <?= json_encode(array_column($fluxoMensal, 'despesas')) ?>;
const saldoData = <?= json_encode(array_column($fluxoMensal, 'saldo')) ?>;

// 1. Gráfico de Fluxo Anual (Barras de Receitas/Despesas + Linha de Saldo Líquido)
const ctxFluxo = document.getElementById('graficoFluxoAnual').getContext('2d');
new Chart(ctxFluxo, {
    type: 'bar',
    data: {
        labels: mesesLabels,
        datasets: [
            {
                type: 'line',
                label: 'Saldo Líquido',
                data: saldoData,
                borderColor: '#D4AF6C',
                backgroundColor: 'rgba(212, 175, 108, 0.2)',
                borderWidth: 2,
                tension: 0.3,
                pointBackgroundColor: '#D4AF6C',
                yAxisID: 'y'
            },
            {
                type: 'bar',
                label: 'Receitas',
                data: receitasData,
                backgroundColor: 'rgba(0, 200, 150, 0.75)',
                borderColor: '#00C896',
                borderWidth: 1,
                borderRadius: 4,
                yAxisID: 'y'
            },
            {
                type: 'bar',
                label: 'Despesas',
                data: despesasData,
                backgroundColor: 'rgba(239, 68, 68, 0.75)',
                borderColor: '#EF4444',
                borderWidth: 1,
                borderRadius: 4,
                yAxisID: 'y'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            x: {
                ticks: {
                    color: '#A7B0BA',
                    maxRotation: 0,
                    autoSkip: true,
                    font: {
                        size: window.innerWidth < 480 ? 9 : 11
                    }
                },
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    color: '#A7B0BA',
                    font: {
                        size: window.innerWidth < 480 ? 9 : 11
                    },
                    callback: v => {
                        if (window.innerWidth < 480) {
                            if (Math.abs(v) >= 1000) return (v / 1000).toFixed(0) + 'k';
                            return v;
                        }
                        return 'R$ ' + Number(v).toLocaleString('pt-BR');
                    }
                },
                grid: { color: 'rgba(167, 176, 186, 0.12)' }
            }
        },
        plugins: {
            legend: {
                labels: {
                    color: '#F4EFE3',
                    usePointStyle: true,
                    padding: window.innerWidth < 480 ? 8 : 14,
                    boxWidth: 8,
                    font: {
                        size: window.innerWidth < 480 ? 10 : 12
                    }
                }
            },
            tooltip: {
                backgroundColor: '#1A2A30',
                borderColor: '#2B3A43',
                borderWidth: 1,
                titleColor: '#F4EFE3',
                bodyColor: '#F4EFE3',
                callbacks: {
                    label: ctx => `${ctx.dataset.label}: R$ ${ctx.parsed.y.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`
                }
            }
        }
    }
});

// 2. Gráfico Donut de Despesas por Categoria
const despesasCatList = <?= json_encode($despesasPorCategoria) ?>;
const ctxDesp = document.getElementById('graficoDespesasCategorias').getContext('2d');

const catLabels = despesasCatList.length ? despesasCatList.map(i => i.categoria) : ['Sem despesas'];
const catValores = despesasCatList.length ? despesasCatList.map(i => parseFloat(i.total)) : [1];
const catCores = despesasCatList.length ? despesasCatList.map(i => i.cor || '#D4AF6C') : ['#1A2A30'];

new Chart(ctxDesp, {
    type: 'doughnut',
    data: {
        labels: catLabels,
        datasets: [{
            data: catValores,
            backgroundColor: catCores,
            borderColor: '#0F171B',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1A2A30',
                borderColor: '#2B3A43',
                borderWidth: 1,
                titleColor: '#F4EFE3',
                bodyColor: '#F4EFE3',
                callbacks: {
                    label: ctx => ` ${ctx.label}: R$ ${ctx.parsed.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`
                }
            }
        }
    }
});
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>

