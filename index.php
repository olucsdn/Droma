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
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#070A0E">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="img/icon-192.svg">
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
        <div class="cartao">
            <span class="rotulo">Saldo Atual</span>
            <span class="valor">R$ <?= number_format($saldoTotal, 2, ',', '.') ?></span>
        </div>
        <div class="cartao">
            <span class="rotulo">Entradas do mês</span>
            <span class="valor positivo">R$ <?= number_format($receitasMes, 2, ',', '.') ?></span>
        </div>
        <div class="cartao">
            <span class="rotulo">Despesas do mês</span>
            <span class="valor negativo">R$ <?= number_format($despesasMes, 2, ',', '.') ?></span>
        </div>
        <div class="cartao">
            <span class="rotulo">Fim do mês</span>
            <span class="valor <?= $resultadoMes >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($resultadoMes, 2, ',', '.') ?>
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
                <p class="vazio">Nenhum lançamento registrado ainda.</p>
            <?php else: ?>
                <ul class="lista-lancamentos">
                    <?php foreach ($ultimosLancamentos as $l): ?>
                        <li>
                            <div class="info">
                                <span class="descricao"><?= htmlspecialchars($l['description'] ?: 'Sem descrição') ?></span>
                                <span class="categoria">
                                    <?= htmlspecialchars($l['categoria'] ?? 'Sem categoria') ?> · <?= date('d/m/Y', strtotime($l['date'])) ?>
                                    · <span class="badge-status badge-<?= $l['status'] ?>"><?= $l['status'] === 'completed' ? 'Pago' : ($l['status'] === 'cancelled' ? 'Cancelado' : 'Pendente') ?></span>
                                    <?php if (!empty($l['due_date'])): ?> · vence <?= date('d/m/Y', strtotime($l['due_date'])) ?><?php endif; ?>
                                    <?php if (!empty($l['urgency'])): ?> · urgência <span class="<?= $l['urgency'] === 'alta' ? 'badge-urgencia-alta' : '' ?>"><?= ['baixa' => 'baixa', 'media' => 'média', 'alta' => 'alta'][$l['urgency']] ?? $l['urgency'] ?></span><?php endif; ?>
                                </span>
                            </div>
                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                <span class="valor-item <?= $l['type'] === 'income' ? 'positivo' : 'negativo' ?>">
                                    <?= $l['type'] === 'income' ? '+' : '-' ?> R$ <?= number_format($l['amount'], 2, ',', '.') ?>
                                </span>
                                <div style="display:flex; gap:6px;">
                                    <a href="edit_expense?id=<?= $l['id'] ?>" class="btn-acao-lancamento" title="Editar este lançamento">Editar</a>
                                    <form action="delete_expense" method="POST" style="margin:0; display:inline;" onsubmit="return confirm('Deseja realmente excluir este lançamento?');">
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
                <h2 style="margin:0;">Distribuição por Categoria</h2>
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
    '#D4AF6C', '#00C896', '#3282F6', '#A78BFA',
    '#F472B6', '#E5B85C', '#64748B', '#38BDF8'
];

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

        const row = document.createElement('div');
        row.className = 'item-detalhe-cat';
        row.innerHTML = `
            <div style="display:flex; align-items:center;">
                <span class="cat-indicador" style="background:${cor};"></span>
                <span>${item.categoria}</span>
            </div>
            <div style="display:flex; gap:12px; font-weight:bold;">
                <span style="color:var(--texto-secundario); font-size:12px;">${pct}%</span>
                <span>R$ ${val.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}</span>
            </div>
        `;
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


