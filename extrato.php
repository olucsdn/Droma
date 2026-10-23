<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

// Parâmetros de Filtro
$periodo    = $_GET['periodo'] ?? 'este_mes';
$dataInicio = $_GET['data_inicio'] ?? '';
$dataFim    = $_GET['data_fim'] ?? '';
$accountId  = !empty($_GET['account_id']) && ctype_digit((string)$_GET['account_id']) ? (int)$_GET['account_id'] : 0;
$categoryId = !empty($_GET['category_id']) && ctype_digit((string)$_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$tipo       = $_GET['type'] ?? '';
$status     = $_GET['status'] ?? '';
$busca      = trim($_GET['busca'] ?? '');
$ordem      = $_GET['ordem'] ?? 'data_desc';

// Carrega contas do usuário para o filtro
$stmtContas = $pdo->prepare("SELECT id, name FROM accounts WHERE user_id = ? ORDER BY name ASC");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

// Carrega categorias do usuário para o filtro
$stmtCats = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? ORDER BY type DESC, name ASC");
$stmtCats->execute([$userId]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// Montagem Dinâmica da Consulta SQL Segura
$where = ["t.user_id = ?"];
$params = [$userId];

// Filtro de Período
$hoje = date('Y-m-d');
switch ($periodo) {
    case 'hoje':
        $where[] = "t.date = CURDATE()";
        break;
    case 'este_mes':
        $where[] = "MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())";
        break;
    case 'mes_anterior':
        $where[] = "t.date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH) AND t.date < DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        break;
    case 'ultimos_30_dias':
        $where[] = "t.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        break;
    case 'este_ano':
        $where[] = "YEAR(t.date) = YEAR(CURDATE())";
        break;
    case 'personalizado':
        if (!empty($dataInicio)) {
            $where[] = "t.date >= ?";
            $params[] = $dataInicio;
        }
        if (!empty($dataFim)) {
            $where[] = "t.date <= ?";
            $params[] = $dataFim;
        }
        break;
    case 'todos':
        // Sem restrição de data
        break;
    default:
        $periodo = 'este_mes';
        $where[] = "MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())";
        break;
}

// Filtro de Conta
if ($accountId > 0) {
    $where[] = "t.account_id = ?";
    $params[] = $accountId;
}

// Filtro de Categoria
if ($categoryId > 0) {
    $where[] = "t.category_id = ?";
    $params[] = $categoryId;
}

// Filtro de Tipo (receita/despesa)
if (in_array($tipo, ['income', 'expense'], true)) {
    $where[] = "t.type = ?";
    $params[] = $tipo;
}

// Filtro de Status
if (in_array($status, ['pending', 'completed', 'cancelled'], true)) {
    $where[] = "t.status = ?";
    $params[] = $status;
}

// Filtro de Busca Textual
if ($busca !== '') {
    $where[] = "(t.description LIKE ? OR c.name LIKE ?)";
    $termoBusca = '%' . $busca . '%';
    $params[] = $termoBusca;
    $params[] = $termoBusca;
}

// Ordenação
$orderSql = "t.date DESC, t.id DESC";
if ($ordem === 'data_asc') {
    $orderSql = "t.date ASC, t.id ASC";
} elseif ($ordem === 'valor_desc') {
    $orderSql = "t.amount DESC, t.date DESC";
} elseif ($ordem === 'valor_asc') {
    $orderSql = "t.amount ASC, t.date DESC";
}

$whereClause = implode(" AND ", $where);

// Consulta Principal com JOINs
$sql = "SELECT 
            t.id, 
            t.type, 
            t.amount, 
            t.description, 
            t.date, 
            t.due_date, 
            t.status, 
            t.urgency,
            a.name AS conta,
            c.name AS categoria
        FROM transactions t
        LEFT JOIN accounts a ON a.id = t.account_id
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE {$whereClause}
        ORDER BY {$orderSql}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cálculo dos KPIs do Período Filtrado
$totais = [
    'entradas'   => 0.00,
    'saidas'     => 0.00,
    'pendentes'  => 0.00,
    'quantidade' => count($lancamentos)
];

foreach ($lancamentos as $l) {
    $val = (float)$l['amount'];
    if ($l['status'] !== 'cancelled') {
        if ($l['type'] === 'income') {
            $totais['entradas'] += $val;
        } else {
            $totais['saidas'] += $val;
        }
    }
    if ($l['status'] === 'pending') {
        $totais['pendentes'] += $val;
    }
}
$saldoFiltrado = $totais['entradas'] - $totais['saidas'];

// Exportação CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $nomeArquivo = 'extrato-wert-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    
    $saida = fopen('php://output', 'w');
    // Insere BOM UTF-8 para compatibilidade perfeita com Microsoft Excel
    fprintf($saida, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Cabeçalho das Colunas
    fputcsv($saida, ['ID', 'Data', 'Tipo', 'Descrição', 'Categoria', 'Conta', 'Valor (R$)', 'Status', 'Vencimento', 'Urgência'], ';', '"', "\\");

    foreach ($lancamentos as $l) {
        fputcsv($saida, [
            $l['id'],
            date('d/m/Y', strtotime($l['date'])),
            $l['type'] === 'income' ? 'Entrada' : 'Saída',
            $l['description'] ?: 'Sem descrição',
            $l['categoria'] ?: 'Sem categoria',
            $l['conta'] ?: 'Sem conta',
            number_format($l['amount'], 2, ',', ''),
            $l['status'] === 'completed' ? 'Concluído' : ($l['status'] === 'pending' ? 'Pendente' : 'Cancelado'),
            !empty($l['due_date']) ? date('d/m/Y', strtotime($l['due_date'])) : '',
            $l['urgency']
        ], ';', '"', "\\");
    }
    fclose($saida);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wert - Extrato e Relatórios</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'extrato';
$tituloPagina = 'Extrato Financeiro';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <!-- KPIs do Filtro Atual -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Resultado do Período</span>
            <span class="valor <?= $saldoFiltrado >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($saldoFiltrado, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Entradas menos Saídas do filtro
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Entradas Filtradas</span>
            <span class="valor positivo">+ R$ <?= number_format($totais['entradas'], 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Receitas no período
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Saídas Filtradas</span>
            <span class="valor negativo">- R$ <?= number_format($totais['saidas'], 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Despesas no período
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Lançamentos</span>
            <span class="valor" style="color:var(--color-gold);"><?= $totais['quantidade'] ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Pendentes: R$ <?= number_format($totais['pendentes'], 2, ',', '.') ?>
            </span>
        </div>
    </section>

    <!-- Barra de Filtros Avançados -->
    <section class="bloco" style="margin-bottom:24px;">
        <div class="cabecalho-secao" style="margin-bottom:16px;">
            <h2 style="margin:0;">Filtros de Pesquisa</h2>
            <?php
            $queryExport = $_GET;
            $queryExport['export'] = 'csv';
            $urlExport = 'extrato.php?' . http_build_query($queryExport);
            ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="importar.php" class="btn-aba" style="padding:6px 14px; text-decoration:none; background:rgba(0, 200, 150, 0.15); color:var(--color-emerald); border:1px solid rgba(0,200,150,0.3);" title="Importar extrato bancário em lote">
                    ⬆ Importar Extrato
                </a>
                <a href="<?= htmlspecialchars($urlExport) ?>" class="btn-aba ativo" style="padding:6px 14px; text-decoration:none;" title="Baixar resultado em arquivo CSV">
                    ⬇ Exportar CSV
                </a>
            </div>
        </div>

        <form method="GET" action="extrato.php" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap:12px; align-items:end; width:100%; min-width:0;">

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Período
                <select name="periodo" id="selectPeriodo" style="width:100%; height:38px; margin-top:4px;" onchange="toggleDatasPersonalizadas()">
                    <option value="este_mes" <?= $periodo === 'este_mes' ? 'selected' : '' ?>>Este Mês</option>
                    <option value="mes_anterior" <?= $periodo === 'mes_anterior' ? 'selected' : '' ?>>Mês Anterior</option>
                    <option value="ultimos_30_dias" <?= $periodo === 'ultimos_30_dias' ? 'selected' : '' ?>>Últimos 30 Dias</option>
                    <option value="este_ano" <?= $periodo === 'este_ano' ? 'selected' : '' ?>>Este Ano</option>
                    <option value="todos" <?= $periodo === 'todos' ? 'selected' : '' ?>>Todo o Histórico</option>
                    <option value="personalizado" <?= $periodo === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                </select>
            </label>

            <div id="campoDataInicio" style="display:<?= $periodo === 'personalizado' ? 'block' : 'none' ?>;">
                <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Data Inicial
                    <input type="date" name="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>" style="width:100%; height:38px; margin-top:4px;">
                </label>
            </div>

            <div id="campoDataFim" style="display:<?= $periodo === 'personalizado' ? 'block' : 'none' ?>;">
                <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Data Final
                    <input type="date" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>" style="width:100%; height:38px; margin-top:4px;">
                </label>
            </div>

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Conta
                <select name="account_id" style="width:100%; height:38px; margin-top:4px;">
                    <option value="">Todas as Contas</option>
                    <?php foreach ($contas as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $accountId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Categoria
                <select name="category_id" style="width:100%; height:38px; margin-top:4px;">
                    <option value="">Todas as Categorias</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?> (<?= $cat['type'] === 'income' ? 'receita' : 'despesa' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Tipo
                <select name="type" style="width:100%; height:38px; margin-top:4px;">
                    <option value="">Todos os Tipos</option>
                    <option value="income" <?= $tipo === 'income' ? 'selected' : '' ?>>Entrada (Receita)</option>
                    <option value="expense" <?= $tipo === 'expense' ? 'selected' : '' ?>>Saída (Despesa)</option>
                </select>
            </label>

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Status
                <select name="status" style="width:100%; height:38px; margin-top:4px;">
                    <option value="">Todos os Status</option>
                    <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Concluído / Pago</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pendente</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelado</option>
                </select>
            </label>

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Buscar Descrição
                <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Ex: Mercado, Luz..." style="width:100%; height:38px; margin-top:4px;">
            </label>

            <label style="margin:0; font-size:12px; color:var(--color-text-secondary);">Ordenação
                <select name="ordem" style="width:100%; height:38px; margin-top:4px;">
                    <option value="data_desc" <?= $ordem === 'data_desc' ? 'selected' : '' ?>>Mais recentes primeiro</option>
                    <option value="data_asc" <?= $ordem === 'data_asc' ? 'selected' : '' ?>>Mais antigas primeiro</option>
                    <option value="valor_desc" <?= $ordem === 'valor_desc' ? 'selected' : '' ?>>Maior valor primeiro</option>
                    <option value="valor_asc" <?= $ordem === 'valor_asc' ? 'selected' : '' ?>>Menor valor primeiro</option>
                </select>
            </label>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn-header-primary" style="height:38px; padding:0 18px; flex:1;">Filtrar</button>
                <a href="extrato.php" class="sair" style="height:38px; display:inline-flex; align-items:center; justify-content:center; padding:0 14px;" title="Limpar Filtros">Limpar</a>
            </div>
        </form>
    </section>

    <!-- Tabela / Lista de Lançamentos Filtrados -->
    <section class="bloco">
        <h2 style="margin-bottom:16px;">Lançamentos Encontrados (<?= count($lancamentos) ?>)</h2>

        <?php if (empty($lancamentos)): ?>
            <p class="vazio" style="text-align:center; padding:40px 0;">Nenhum lançamento encontrado para os critérios de busca selecionados.</p>
        <?php else: ?>
            <ul class="lista-lancamentos">
                <?php foreach ($lancamentos as $l): ?>
                    <li>
                        <div class="info">
                            <span class="descricao"><?= htmlspecialchars($l['description'] ?: 'Sem descrição') ?></span>
                            <span class="categoria">
                                <?= htmlspecialchars($l['categoria'] ?? 'Sem categoria') ?>
                                · <strong style="color:var(--color-text);"><?= htmlspecialchars($l['conta'] ?? 'Sem conta') ?></strong>
                                · <?= date('d/m/Y', strtotime($l['date'])) ?>
                                · <span class="badge-status badge-<?= $l['status'] ?>">
                                    <?= $l['status'] === 'completed' ? 'Pago' : ($l['status'] === 'cancelled' ? 'Cancelado' : 'Pendente') ?>
                                </span>
                                <?php if (!empty($l['due_date'])): ?>
                                    · vence <?= date('d/m/Y', strtotime($l['due_date'])) ?>
                                <?php endif; ?>
                                <?php if (!empty($l['urgency'])): ?>
                                    · urgência <span class="<?= $l['urgency'] === 'alta' ? 'badge-urgencia-alta' : '' ?>"><?= ['baixa' => 'baixa', 'media' => 'média', 'alta' => 'alta'][$l['urgency']] ?? $l['urgency'] ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                            <span class="valor-item <?= $l['type'] === 'income' ? 'positivo' : 'negativo' ?>">
                                <?= $l['type'] === 'income' ? '+' : '-' ?> R$ <?= number_format($l['amount'], 2, ',', '.') ?>
                            </span>
                            <div style="display:flex; gap:6px;">
                                <a href="editar_gasto.php?id=<?= $l['id'] ?>&origem=extrato" class="btn-acao-lancamento" title="Editar este lançamento">Editar</a>
                                <form action="excluir_gasto.php" method="POST" style="margin:0; display:inline;" onsubmit="return confirm('Deseja realmente excluir este lançamento?');">
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
    </section>

</main>

<script>
function toggleDatasPersonalizadas() {
    const sel = document.getElementById('selectPeriodo').value;
    const campoInicio = document.getElementById('campoDataInicio');
    const campoFim = document.getElementById('campoDataFim');
    const exibir = sel === 'personalizado';
    campoInicio.style.display = exibir ? 'block' : 'none';
    campoFim.style.display = exibir ? 'block' : 'none';
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
