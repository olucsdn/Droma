<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

// Otimização: Consolida os 5 cálculos de totais em 1 única viagem ao banco de dados
$stmtTotais = $pdo->prepare("
    SELECT 
        (SELECT COALESCE(SUM(initial_balance), 0) FROM accounts WHERE user_id = ?) AS saldo_inicial,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status = 'completed') AS total_receitas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'completed') AS total_despesas,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())) AS receitas_mes,
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())) AS despesas_mes
");
$stmtTotais->execute([$userId, $userId, $userId, $userId, $userId]);
$totais = $stmtTotais->fetch(PDO::FETCH_ASSOC);

$saldoInicial            = (float) ($totais['saldo_inicial'] ?? 0);
$totalReceitasRealizadas = (float) ($totais['total_receitas'] ?? 0);
$totalDespesasRealizadas = (float) ($totais['total_despesas'] ?? 0);
$saldoTotal              = $saldoInicial + $totalReceitasRealizadas - $totalDespesasRealizadas;

$receitasMes  = (float) ($totais['receitas_mes'] ?? 0);
$despesasMes  = (float) ($totais['despesas_mes'] ?? 0);
$resultadoMes = $receitasMes - $despesasMes;

// Despesas por categoria no mês atual
$stmt = $pdo->prepare(
    "SELECT COALESCE(c.name, 'Sem categoria') AS categoria, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.type = 'expense'
       AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
     GROUP BY t.category_id, c.name
     ORDER BY total DESC"
);
$stmt->execute([$userId]);
$gastosPorCategoria = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Receitas por categoria no mês atual
$stmt = $pdo->prepare(
    "SELECT COALESCE(c.name, 'Sem categoria') AS categoria, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ? AND t.type = 'income'
       AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
     GROUP BY t.category_id, c.name
     ORDER BY total DESC"
);
$stmt->execute([$userId]);
$receitasPorCategoria = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Últimos lançamentos
$stmt = $pdo->prepare(
    "SELECT t.id, t.description, t.amount, t.type, t.date, t.due_date, t.status, t.urgency, c.name AS categoria
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = ?
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

// Categorias com teto de gastos configurado para o mês atual
$stmtBudgets = $pdo->prepare(
    "SELECT 
        c.id, 
        c.name, 
        c.budget_limit,
        COALESCE(SUM(CASE WHEN t.status = 'completed' AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE()) THEN t.amount ELSE 0 END), 0) AS gasto_mes_atual
     FROM categories c
     LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = c.user_id AND t.type = 'expense'
     WHERE c.user_id = ? AND c.type = 'expense' AND c.budget_limit IS NOT NULL AND c.budget_limit > 0
     GROUP BY c.id, c.name, c.budget_limit
     ORDER BY (gasto_mes_atual / c.budget_limit) DESC, c.name ASC"
);
$stmtBudgets->execute([$userId]);
$tetosDoMes = $stmtBudgets->fetchAll(PDO::FETCH_ASSOC);
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
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
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

    <section class="cartoes">
        <div class="cartao cartao-saldo">
            <div class="cartao-header-linha">
                <span class="rotulo">Saldo Atual</span>
                <span class="cartao-icone-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                </span>
            </div>
            <span class="valor">R$ <?= number_format($saldoTotal, 2, ',', '.') ?></span>
        </div>
        <div class="cartao">
            <div class="cartao-header-linha">
                <span class="rotulo">Entradas do mês</span>
                <span class="cartao-icone-badge badge-entradas" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M5 17.59L15.59 7H9V5h10v10h-2V8.41L6.41 19 5 17.59z"/></svg>
                </span>
            </div>
            <span class="valor positivo">R$ <?= number_format($receitasMes, 2, ',', '.') ?></span>
        </div>
        <div class="cartao">
            <div class="cartao-header-linha">
                <span class="rotulo">Despesas do mês</span>
                <span class="cartao-icone-badge badge-despesas" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M19 6.41L8.41 17H15v2H5V9h2v6.59L17.59 5 19 6.41z"/></svg>
                </span>
            </div>
            <span class="valor negativo">R$ <?= number_format($despesasMes, 2, ',', '.') ?></span>
        </div>
        <div class="cartao">
            <div class="cartao-header-linha">
                <span class="rotulo">Fim do mês</span>
                <span class="cartao-icone-badge badge-projecao" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6h-6z"/></svg>
                </span>
            </div>
            <span class="valor valor-fim-mes">
                R$ <?= number_format($resultadoMes, 2, ',', '.') ?>
            </span>
            <span class="legenda-subtexto">Saldo projetado após fixas</span>
        </div>
    </section>

    <section class="grade">

        <div class="bloco lancamentos">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <h2 style="margin:0;">Últimos lançamentos</h2>
                <a href="statement" class="sair" style="font-size:12px; padding: 4px 10px;">Ver extrato completo →</a>
            </div>
            <?php if (empty($ultimosLancamentos)): ?>
                <p class="vazio">Nenhum lançamento registrado ainda.</p>
            <?php else: ?>
                <ul class="lista-lancamentos">
                    <?php foreach ($ultimosLancamentos as $l): 
                        $tipo = $l['type'] ?? 'expense';
                        $status = $l['status'] ?? 'pending';
                        $catNome = $l['categoria'] ?? 'Geral';
                        $dataFmt = date('d M', strtotime($l['date']));
                        $isPago = ($status === 'completed');
                        $catLower = mb_strtolower($catNome);
                    ?>
                        <li class="item-lancamento-moderno">
                            <div class="lancamento-col-esquerda">
                                <div class="cat-icone-wrap" aria-hidden="true">
                                    <?php
                                    if (str_contains($catLower, 'aliment') || str_contains($catLower, 'mercado') || str_contains($catLower, 'lanche') || str_contains($catLower, 'restaurante')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z"/></svg>';
                                    } elseif (str_contains($catLower, 'transporte') || str_contains($catLower, 'carro') || str_contains($catLower, 'uber') || str_contains($catLower, 'combustivel') || str_contains($catLower, 'gasolina')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>';
                                    } elseif (str_contains($catLower, 'moradia') || str_contains($catLower, 'aluguel') || str_contains($catLower, 'casa') || str_contains($catLower, 'luz') || str_contains($catLower, 'agua') || str_contains($catLower, 'internet')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>';
                                    } elseif (str_contains($catLower, 'saude') || str_contains($catLower, 'farmacia') || str_contains($catLower, 'medico') || str_contains($catLower, 'hospital')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M19 10.5h-4.5V6h-5v4.5H5v5h4.5V20h5v-4.5H19z"/></svg>';
                                    } elseif (str_contains($catLower, 'educa') || str_contains($catLower, 'curso') || str_contains($catLower, 'escola') || str_contains($catLower, 'faculdade')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>';
                                    } elseif (str_contains($catLower, 'lazer') || str_contains($catLower, 'viagem') || str_contains($catLower, 'cinema') || str_contains($catLower, 'jogos') || str_contains($catLower, 'streaming')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M21 6H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-10 7H8v3H6v-3H3v-2h3V8h2v3h3v2zm4.5 2c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm4-3c-.83 0-1.5-.67-1.5-1.5S18.67 9 19.5 9s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>';
                                    } elseif ($tipo === 'income' || str_contains($catLower, 'salario') || str_contains($catLower, 'renda') || str_contains($catLower, 'pix')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>';
                                    } elseif (str_contains($catLower, 'invest')) {
                                        echo '<svg viewBox="0 0 24 24"><path d="M3.5 18.49l6-6.01 4 4L22 6.92l-1.41-1.41-7.09 7.97-4-4L2 16.99z"/></svg>';
                                    } else {
                                        echo '<svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14l4-4 4 4 4-4 4 4V5c0-1.1-.9-2-2-2zm0 10l-2-2-4 4-4-4-2 2V5h12v8z"/></svg>';
                                    }
                                    ?>
                                </div>
                                <div class="info">
                                    <span class="descricao"><?= htmlspecialchars($l['description'] ?: 'Sem descrição') ?></span>
                                    <span class="categoria-sublinha">
                                        <?= htmlspecialchars($catNome) ?> • <?= $dataFmt ?>
                                        <span class="badge-status badge-<?= $status ?>"><?= $isPago ? 'Pago' : ($status === 'cancelled' ? 'Cancelado' : 'Pendente') ?></span>
                                        <?php if (!$isPago && !empty($l['due_date'])): ?>
                                            • vence <?= date('d/m', strtotime($l['due_date'])) ?>
                                        <?php endif; ?>
                                        <?php if (!$isPago && !empty($l['urgency']) && $l['urgency'] === 'alta'): ?>
                                            • <span class="badge-urgencia-alta">urgente</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="lancamento-col-direita">
                                <span class="valor-item <?= $tipo === 'income' ? 'positivo' : 'negativo' ?>">
                                    <?= $tipo === 'income' ? '+' : '-' ?> R$ <?= number_format($l['amount'], 2, ',', '.') ?>
                                </span>
                                <div class="acoes-lancamento">
                                    <a href="edit_expense?id=<?= $l['id'] ?>" class="btn-acao-lancamento" title="Editar este lançamento">Editar</a>
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
            <div class="cabecalho-grafico-novo">
                <h2 style="margin:0 0 12px 0;">Distribuição por Categoria</h2>
                <div class="segmented-control-grafico">
                    <button type="button" class="btn-segmented ativo" id="btnDespesas">Despesas</button>
                    <button type="button" class="btn-segmented" id="btnReceitas">Receitas</button>
                    <button type="button" class="btn-segmented" id="btnComparativo">Comparativo</button>
                </div>
            </div>

            <div id="containerGraficoCategorias" class="grafico-categorias-container">
                <canvas id="graficoCategorias"></canvas>
                <div id="donutCenterInfo" class="donut-center-info">
                    <span class="donut-rotulo-centro" id="donutRotuloCentro">Total Despesas</span>
                    <span class="donut-valor-centro" id="donutValorCentro">R$ 0,00</span>
                </div>
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
    '#6366F1', '#10B981', '#F43F5E', '#06B6D4',
    '#F59E0B', '#8B5CF6', '#EC4899', '#14B8A6', '#3B82F6'
];

const ctx = document.getElementById('graficoCategorias');
const containerGrafico = document.getElementById('containerGraficoCategorias');
const containerDetalhes = document.getElementById('containerDetalhesCategorias');
const donutCenter = document.getElementById('donutCenterInfo');
const donutRotulo = document.getElementById('donutRotuloCentro');
const donutValor = document.getElementById('donutValorCentro');
const btnDespesas = document.getElementById('btnDespesas');
const btnReceitas = document.getElementById('btnReceitas');
const btnComparativo = document.getElementById('btnComparativo');

let chartInstance = null;

function renderizarGrafico(lista, tituloTipo) {
    containerGrafico.classList.remove('modo-comparativo');
    const totalGeral = lista.reduce((acc, item) => acc + parseFloat(item.total), 0);
    const temDados = lista.length > 0 && totalGeral > 0;

    if (donutCenter) donutCenter.style.display = temDados ? 'flex' : 'none';
    if (donutRotulo) donutRotulo.textContent = 'Total ' + tituloTipo;
    if (donutValor) donutValor.textContent = 'R$ ' + totalGeral.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

    const labels = temDados ? lista.map(item => item.categoria) : ['Sem ' + tituloTipo.toLowerCase() + ' no mês'];
    const data = temDados ? lista.map(item => parseFloat(item.total)) : [1];
    const cores = temDados ? palette.slice(0, data.length) : ['#1A2A30'];

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
                borderColor: '#141824',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            cutout: '72%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: temDados,
                    backgroundColor: '#131722',
                    borderColor: 'rgba(255, 255, 255, 0.1)',
                    borderWidth: 1,
                    titleColor: '#F8FAFC',
                    bodyColor: '#F8FAFC',
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

        const row = document.createElement('div');
        row.className = 'item-detalhe-cat';
        row.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="cat-indicador" style="background:${cor}; width:9px; height:9px; border-radius:50%; display:inline-block; flex-shrink:0;"></span>
                <span style="font-weight:500; font-size:13px; color:var(--color-text);">${item.categoria}</span>
            </div>
            <div style="display:flex; align-items:center; gap:8px; font-variant-numeric:tabular-nums;">
                <span style="font-weight:600; font-size:13px; color:#FFFFFF;">R$ ${val.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
                <span style="color:var(--color-text-secondary); font-size:12px;">(${pct}%)</span>
            </div>
        `;
        containerDetalhes.appendChild(row);
    });
}

function renderizarGraficoComparativo() {
    containerGrafico.classList.add('modo-comparativo');
    if (donutCenter) donutCenter.style.display = 'none';

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


