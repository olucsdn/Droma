<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);
Security::ensureTransferColumn($pdo);

// Ações em lote (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_lote'])) {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        Security::flashRedirect('statement', 'erro', 'Sessão expirada. Tente novamente.');
    }
    if (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
        Security::flashRedirect('statement', 'sucesso', 'Operação em lote já processada.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($v) => $v > 0)));
    $acaoLote = (string)($_POST['acao_lote'] ?? '');
    $qsBack = !empty($_POST['qs']) ? '?' . ltrim((string)$_POST['qs'], '?') : '';

    if (!empty($ids)) {
        $inPh = implode(',', array_fill(0, count($ids), '?'));
        if ($acaoLote === 'marcar_pago') {
            $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE user_id = ? AND visivel = 1 AND id IN ({$inPh})")
                ->execute(array_merge([$userId], $ids));
            Security::flashRedirect("statement{$qsBack}", 'sucesso', count($ids) . ' lançamento(s) marcado(s) como pago!');
        } elseif ($acaoLote === 'excluir') {
            $pdo->prepare("UPDATE transactions SET visivel = 0 WHERE user_id = ? AND visivel = 1 AND transfer_id IS NULL AND id IN ({$inPh})")
                ->execute(array_merge([$userId], $ids));
            Security::flashRedirect("statement{$qsBack}", 'sucesso', 'Lançamentos selecionados excluídos.');
        } elseif ($acaoLote === 'trocar_categoria') {
            $novaCatId = (int)($_POST['nova_category_id'] ?? 0);
            $stCat = $pdo->prepare("SELECT id, name, type FROM categories WHERE id = ? AND user_id = ? AND visivel = 1 LIMIT 1");
            $stCat->execute([$novaCatId, $userId]);
            $catRow = $stCat->fetch(PDO::FETCH_ASSOC);
            if ($catRow) {
                $pdo->prepare("UPDATE transactions SET category_id = ?, category_name = ? WHERE user_id = ? AND visivel = 1 AND type = ? AND id IN ({$inPh})")
                    ->execute(array_merge([$novaCatId, $catRow['name'], $userId, $catRow['type']], $ids));
                Security::flashRedirect("statement{$qsBack}", 'sucesso', 'Categoria atualizada nos lançamentos compatíveis.');
            }
        }
    }
    Security::flashRedirect("statement{$qsBack}", 'erro', 'Selecione ao menos um lançamento válido.');
}

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

// Carrega categorias ativas do usuário para o filtro
$stmtCats = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? AND visivel = 1 ORDER BY type DESC, name ASC");
$stmtCats->execute([$userId]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// Montagem Dinâmica da Consulta SQL Segura (apenas transações visíveis)
$where = ["t.user_id = ?", "t.visivel = 1"];
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
    $where[] = "(t.description LIKE ? OR c.name LIKE ? OR t.category_name LIKE ?)";
    $termoBusca = '%' . $busca . '%';
    $params[] = $termoBusca;
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

// Consulta Principal com JOINs e Fallback de Snapshot Histórico
$sql = "SELECT 
            t.id, 
            t.transfer_id,
            t.goal_id,
            t.type, 
            t.amount, 
            t.description, 
            t.date, 
            t.due_date, 
            t.status, 
            t.urgency,
            a.name AS conta,
            COALESCE(c.name, t.category_name, 'Outros') AS categoria
        FROM transactions t
        LEFT JOIN accounts a ON a.id = t.account_id
        LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
        WHERE {$whereClause}
        ORDER BY {$orderSql}";

// Exportação CSV otimizada em streaming (sem alocação de array massivo em memória)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    $nomeArquivo = 'extrato-droma-' . date('Y-m-d-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
    
    $saida = fopen('php://output', 'w');
    // Insere BOM UTF-8 para compatibilidade com Microsoft Excel
    fprintf($saida, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Cabeçalho das Colunas
    fputcsv($saida, ['ID', 'Data', 'Tipo', 'Descrição', 'Categoria', 'Conta', 'Valor (R$)', 'Status', 'Vencimento', 'Urgência'], ';', '"', "\\");

    $stmtExport = $pdo->prepare($sql);
    $stmtExport->execute($params);
    while ($l = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($saida, [
            $l['id'],
            date('d/m/Y', strtotime($l['date'])),
            $l['type'] === 'income' ? 'Entrada' : 'Saída',
            Security::sanitizeCsvCell($l['description'] ?: 'Sem descrição'),
            Security::sanitizeCsvCell($l['categoria'] ?: 'Sem categoria'),
            Security::sanitizeCsvCell($l['conta'] ?: 'Sem conta'),
            number_format((float)$l['amount'], 2, ',', ''),
            $l['status'] === 'completed' ? 'Concluído' : ($l['status'] === 'pending' ? 'Pendente' : 'Cancelado'),
            !empty($l['due_date']) ? date('d/m/Y', strtotime($l['due_date'])) : '',
            ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'][$l['urgency']] ?? $l['urgency']
        ], ';', '"', "\\");
    }
    fclose($saida);
    exit;
}

$limite = isset($_GET['limite']) && ctype_digit((string)$_GET['limite']) ? max(50, min(2000, (int)$_GET['limite'])) : 50;

// Cálculo dos KPIs via agregação SQL (sem carregar todas as linhas em memória)
$sqlKpi = "SELECT
            COUNT(*) AS quantidade,
            COALESCE(SUM(CASE WHEN t.status <> 'cancelled' AND t.type = 'income' AND (" . ($accountId > 0 ? "1=1" : "(t.transfer_id IS NULL AND COALESCE(c.name, t.category_name, '') <> 'Transferência')") . ") THEN t.amount ELSE 0 END), 0) AS entradas,
            COALESCE(SUM(CASE WHEN t.status <> 'cancelled' AND t.type = 'expense' AND (" . ($accountId > 0 ? "1=1" : "(t.transfer_id IS NULL AND COALESCE(c.name, t.category_name, '') <> 'Transferência')") . ") THEN t.amount ELSE 0 END), 0) AS saidas,
            COALESCE(SUM(CASE WHEN t.status = 'pending' THEN t.amount ELSE 0 END), 0) AS pendentes
           FROM transactions t
           LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
           WHERE {$whereClause}";
$stmtKpi = $pdo->prepare($sqlKpi);
$stmtKpi->execute($params);
$kpiRow = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];

$totais = [
    'entradas'   => (float)($kpiRow['entradas'] ?? 0),
    'saidas'     => (float)($kpiRow['saidas'] ?? 0),
    'pendentes'  => (float)($kpiRow['pendentes'] ?? 0),
    'quantidade' => (int)($kpiRow['quantidade'] ?? 0)
];
$saldoFiltrado = $totais['entradas'] - $totais['saidas'];

$stmt = $pdo->prepare($sql . " LIMIT {$limite}");
$stmt->execute($params);
$lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Extrato e Relatórios</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
    <style>
        /* ==========================================================================
           Estilização Específica do Formulário Filtros de Pesquisa (Tela Extrato)
           ========================================================================== */
        .bloco-filtros-extrato {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg, 16px);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-card);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .cabecalho-filtros-extrato {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--color-divider);
        }

        .titulo-filtros-extrato {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .titulo-filtros-extrato h2 {
            margin: 0;
            font-size: 17px;
            font-weight: 700;
            color: var(--color-text);
            letter-spacing: -0.3px;
        }

        .acoes-topo-filtros {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Botão Importar Extrato (Verde Esmeralda) */
        .btn-filtro-importar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            background: rgba(16, 185, 129, 0.12);
            color: var(--color-emerald);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: var(--radius-md, 10px);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            line-height: 1.2;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-filtro-importar:hover {
            background: rgba(16, 185, 129, 0.22);
            border-color: var(--color-emerald);
            color: #34D399;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .btn-filtro-importar:active {
            transform: translateY(1px) scale(0.98);
        }

        .btn-filtro-importar:focus-visible {
            outline: 2px solid var(--color-emerald);
            outline-offset: 2px;
        }

        /* Botão Exportar CSV (Dourado) */
        .btn-filtro-exportar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--gold-gradient);
            color: var(--color-on-gold);
            border: 1px solid rgba(249, 228, 167, 0.35);
            border-radius: var(--radius-md, 10px);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            line-height: 1.2;
            cursor: pointer;
            box-shadow: var(--shadow-gold-subtle);
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-filtro-exportar:hover {
            background: var(--gold-gradient-hover);
            box-shadow: var(--shadow-gold-hover);
            transform: translateY(-1px);
        }

        .btn-filtro-exportar:active {
            transform: translateY(1px) scale(0.98);
        }

        .btn-filtro-exportar:focus-visible {
            outline: 2px solid var(--color-gold);
            outline-offset: 2px;
        }

        /* Grid e Estrutura do Formulário */
        .form-filtros-extrato {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 195px), 1fr));
            gap: 16px;
            align-items: flex-end;
            width: 100%;
            min-width: 0;
        }

        .form-filtros-extrato .campo-filtro {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
            width: 100%;
        }

        .form-filtros-extrato .campo-filtro label {
            margin: 0;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        /* Padronização dos Campos de Formulário */
        .form-filtros-extrato input[type="text"],
        .form-filtros-extrato input[type="date"],
        .form-filtros-extrato select {
            width: 100%;
            height: 42px;
            padding: 0 14px;
            background-color: var(--color-surface-secondary);
            color: var(--color-text);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md, 10px);
            font-size: 13.5px;
            font-weight: 500;
            font-family: inherit;
            box-sizing: border-box;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            margin: 0;
        }

        /* Placeholders */
        .form-filtros-extrato input::placeholder {
            color: var(--color-text-muted);
            opacity: 0.8;
            font-size: 13px;
        }

        /* Destaque com Borda Dourada Sutil ao Selecionar / Focar */
        .form-filtros-extrato input[type="text"]:focus,
        .form-filtros-extrato input[type="date"]:focus,
        .form-filtros-extrato select:focus {
            outline: none;
            border-color: var(--color-gold);
            box-shadow: 0 0 0 3px rgba(212, 175, 108, 0.22);
            background-color: var(--color-surface-hover);
        }

        /* Estilização Refinada dos Selects */
        .form-filtros-extrato select {
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394A3B8' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 12px 12px;
            padding-right: 36px;
        }

        .form-filtros-extrato select option {
            background-color: var(--color-surface, #121826);
            color: var(--color-text, #F8FAFC);
            padding: 8px 12px;
        }

        /* Ícone do Seletor de Data */
        .form-filtros-extrato input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(0.75);
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }

        .form-filtros-extrato input[type="date"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
        }

        :root[data-theme="light"] .form-filtros-extrato input[type="date"]::-webkit-calendar-picker-indicator {
            filter: none;
        }

        /* Preservação da Alternância das Datas Personalizadas */
        .form-filtros-extrato #campoDataInicio[style*="display: block"],
        .form-filtros-extrato #campoDataFim[style*="display: block"] {
            display: flex !important;
            flex-direction: column;
            gap: 6px;
        }

        /* Grupo de Botões Filtrar e Limpar (Opção 2: Simétricos 50/50 e Refinados) */
        .form-filtros-extrato .campo-filtro-acoes {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            align-items: center;
            width: 100%;
            min-width: 0;
        }

        /* Botão Filtrar (Dourado com texto escuro e cantos arredondados) */
        .btn-filtro-submit {
            height: 42px;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            background: var(--gold-gradient);
            color: var(--color-on-gold);
            font-size: 13.5px;
            font-weight: 700;
            font-family: inherit;
            border-radius: var(--radius-md, 10px);
            border: 1px solid rgba(249, 228, 167, 0.4);
            box-shadow: var(--shadow-gold-subtle);
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            box-sizing: border-box;
            padding: 0 12px;
        }

        .btn-filtro-submit:hover {
            background: var(--gold-gradient-hover);
            box-shadow: var(--shadow-gold-hover);
            transform: translateY(-1px);
        }

        .btn-filtro-submit:active {
            transform: translateY(1px) scale(0.98);
        }

        .btn-filtro-submit:focus-visible {
            outline: 2px solid var(--color-gold);
            outline-offset: 2px;
        }

        /* Botão Limpar (Fundo escuro refinado, borda discreta e texto claro) */
        .btn-filtro-limpar {
            height: 42px;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 12px;
            background: var(--color-surface-secondary);
            color: var(--color-text);
            font-size: 13.5px;
            font-weight: 600;
            font-family: inherit;
            border-radius: var(--radius-md, 10px);
            border: 1px solid var(--color-border);
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            box-sizing: border-box;
        }

        .btn-filtro-limpar:hover {
            background: var(--color-surface-hover);
            border-color: rgba(212, 175, 108, 0.4);
            color: var(--color-gold);
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
        }

        .btn-filtro-limpar:active {
            transform: translateY(1px) scale(0.98);
        }

        .btn-filtro-limpar:focus-visible {
            outline: 2px solid var(--color-border);
            outline-offset: 2px;
        }

        /* Ajustes de Responsividade */
        @media (max-width: 768px) {
            .bloco-filtros-extrato {
                padding: 18px 16px;
                margin-bottom: 20px;
            }

            .cabecalho-filtros-extrato {
                flex-direction: column;
                align-items: stretch;
                gap: 14px;
            }

            .acoes-topo-filtros {
                width: 100%;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .btn-filtro-importar,
            .btn-filtro-exportar {
                width: 100%;
                justify-content: center;
                padding: 9px 12px;
                font-size: 12.5px;
            }

            .form-filtros-extrato {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .form-filtros-extrato .campo-filtro-acoes {
                margin-top: 4px;
            }
        }
    </style>
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
    <section class="bloco bloco-filtros-extrato">
        <div class="cabecalho-secao cabecalho-filtros-extrato">
            <div class="titulo-filtros-extrato">
                <h2>Filtros de Pesquisa</h2>
            </div>
            <?php
            $queryExport = $_GET;
            $queryExport['export'] = 'csv';
            $urlExport = 'statement?' . http_build_query($queryExport);
            ?>
            <div class="acoes-topo-filtros">
                <a href="import" class="btn-filtro-importar" title="Importar extrato bancário em lote">
                    Importar Extrato
                </a>
                <a href="<?= htmlspecialchars($urlExport) ?>" download="extrato-droma.csv" class="btn-filtro-exportar" title="Baixar resultado em arquivo CSV">
                    ⬇ Exportar CSV
                </a>
            </div>
        </div>

        <form method="GET" action="statement" class="form-filtros-extrato">

            <div class="campo-filtro">
                <label for="selectPeriodo">Período</label>
                <select name="periodo" id="selectPeriodo" onchange="toggleDatasPersonalizadas()">
                    <option value="este_mes" <?= $periodo === 'este_mes' ? 'selected' : '' ?>>Este Mês</option>
                    <option value="mes_anterior" <?= $periodo === 'mes_anterior' ? 'selected' : '' ?>>Mês Anterior</option>
                    <option value="ultimos_30_dias" <?= $periodo === 'ultimos_30_dias' ? 'selected' : '' ?>>Últimos 30 Dias</option>
                    <option value="este_ano" <?= $periodo === 'este_ano' ? 'selected' : '' ?>>Este Ano</option>
                    <option value="todos" <?= $periodo === 'todos' ? 'selected' : '' ?>>Todo o Histórico</option>
                    <option value="personalizado" <?= $periodo === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
                </select>
            </div>

            <div id="campoDataInicio" class="campo-filtro" style="display:<?= $periodo === 'personalizado' ? 'block' : 'none' ?>;">
                <label for="inputDataInicio">Data Inicial</label>
                <input type="date" id="inputDataInicio" name="data_inicio" value="<?= htmlspecialchars($dataInicio) ?>">
            </div>

            <div id="campoDataFim" class="campo-filtro" style="display:<?= $periodo === 'personalizado' ? 'block' : 'none' ?>;">
                <label for="inputDataFim">Data Final</label>
                <input type="date" id="inputDataFim" name="data_fim" value="<?= htmlspecialchars($dataFim) ?>">
            </div>

            <div class="campo-filtro">
                <label for="selectConta">Conta</label>
                <select name="account_id" id="selectConta">
                    <option value="">Todas as Contas</option>
                    <?php foreach ($contas as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $accountId === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="campo-filtro">
                <label for="inputBusca">Buscar Descrição <kbd class="droma-kbd">/</kbd></label>
                <input type="text" id="inputBusca" name="busca" value="<?= htmlspecialchars($busca) ?>" placeholder="Ex: Mercado, Luz...">
            </div>

            <?php $temFiltroAvancado = ($categoryId > 0 || $tipo !== '' || $status !== '' || $ordem !== 'data_desc'); ?>
            <details style="grid-column: 1 / -1; margin-top: 4px;" <?= $temFiltroAvancado ? 'open' : '' ?>>
                <summary style="cursor:pointer; font-size:12.5px; font-weight:600; color:var(--droma-gold-text); user-select:none;">
                    ⚙ Mais filtros (categoria, tipo, status e ordenação)
                </summary>
                <div class="form-filtros-extrato" style="margin-top:12px;">
                    <div class="campo-filtro">
                        <label for="selectCategoria">Categoria</label>
                        <select name="category_id" id="selectCategoria">
                            <option value="">Todas as Categorias</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?> (<?= $cat['type'] === 'income' ? 'receita' : 'despesa' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="campo-filtro">
                        <label for="selectTipo">Tipo</label>
                        <select name="type" id="selectTipo">
                            <option value="">Todos os Tipos</option>
                            <option value="income" <?= $tipo === 'income' ? 'selected' : '' ?>>Entrada (Receita)</option>
                            <option value="expense" <?= $tipo === 'expense' ? 'selected' : '' ?>>Saída (Despesa)</option>
                        </select>
                    </div>

                    <div class="campo-filtro">
                        <label for="selectStatus">Status</label>
                        <select name="status" id="selectStatus">
                            <option value="">Todos os Status</option>
                            <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Concluído / Pago</option>
                            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pendente</option>
                            <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelado</option>
                        </select>
                    </div>

                    <div class="campo-filtro">
                        <label for="selectOrdem">Ordenação</label>
                        <select name="ordem" id="selectOrdem">
                            <option value="data_desc" <?= $ordem === 'data_desc' ? 'selected' : '' ?>>Mais recentes primeiro</option>
                            <option value="data_asc" <?= $ordem === 'data_asc' ? 'selected' : '' ?>>Mais antigas primeiro</option>
                            <option value="valor_desc" <?= $ordem === 'valor_desc' ? 'selected' : '' ?>>Maior valor primeiro</option>
                            <option value="valor_asc" <?= $ordem === 'valor_asc' ? 'selected' : '' ?>>Menor valor primeiro</option>
                        </select>
                    </div>
                </div>
            </details>

            <div class="campo-filtro campo-filtro-acoes">
                <button type="submit" class="btn-filtro-submit">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                    </svg>
                    Filtrar
                </button>
                <a href="statement" class="btn-filtro-limpar" title="Limpar Filtros">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                        <polyline points="3 3 3 8 8 8"></polyline>
                    </svg>
                    Limpar
                </a>
            </div>
        </form>
    </section>

    <!-- Tabela / Lista de Lançamentos Filtrados -->
    <section class="bloco">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
            <h2 style="margin:0;">Lançamentos Encontrados (<?= $totais['quantidade'] ?>)</h2>
            <?php if (!empty($lancamentos)): ?>
                <label style="display:inline-flex; align-items:center; gap:6px; font-size:12.5px; color:var(--color-text-secondary); cursor:pointer;">
                    <input type="checkbox" id="chkSelecionarTodos"> Selecionar visíveis
                </label>
            <?php endif; ?>
        </div>

        <?php if (!empty($lancamentos)): ?>
            <form method="POST" action="statement" id="formAcaoLote" class="droma-sticky-toolbar" style="display:none; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px; padding:10px 14px; border:1px solid rgba(212,175,108,0.35); border-radius:10px; background:var(--color-surface-secondary);">
                <?= Security::csrfField() ?>
                <input type="hidden" name="qs" value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') ?>">
                <span id="loteCount" style="font-size:12.5px; font-weight:700; color:var(--color-gold);">0 selecionado(s)</span>
                <button type="submit" name="acao_lote" value="marcar_pago" class="btn-acao-lancamento" style="cursor:pointer;">✓ Marcar como pago</button>
                <select name="nova_category_id" style="padding:5px 8px; border-radius:6px; font-size:12px;">
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?> (<?= $cat['type'] === 'income' ? 'rec' : 'desp' ?>)</option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="acao_lote" value="trocar_categoria" class="btn-acao-lancamento" style="cursor:pointer;">Trocar categoria</button>
                <button type="submit" name="acao_lote" value="excluir" class="btn-acao-lancamento btn-acao-excluir" style="cursor:pointer; border:none;" onclick="return confirm('Excluir os lançamentos selecionados?');">Excluir</button>
            </form>
        <?php endif; ?>

        <?php if (empty($lancamentos)): ?>
            <div class="droma-empty" style="margin-top:8px;">
                <p>Nenhum lançamento encontrado para os filtros selecionados.</p>
                <a href="add_expense" class="btn-header-primary" style="text-decoration:none; padding:8px 16px; font-size:13px;">+ Novo lançamento</a>
            </div>
        <?php else: ?>
            <ul class="lista-lancamentos">
                <?php foreach ($lancamentos as $l): ?>
                    <li>
                        <div style="display:flex; align-items:flex-start; gap:10px; flex:1; min-width:0;">
                            <input type="checkbox" class="chk-lote" value="<?= (int)$l['id'] ?>" aria-label="Selecionar lançamento <?= (int)$l['id'] ?>" style="margin-top:4px;">
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
                        </div>
                        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                            <span class="valor-item <?= $l['type'] === 'income' ? 'positivo' : 'negativo' ?>">
                                <?= $l['type'] === 'income' ? '+' : '-' ?> R$ <?= number_format($l['amount'], 2, ',', '.') ?>
                            </span>
                            <div style="display:flex; gap:6px;">
                                <?php if (!empty($l['transfer_id'])): ?>
                                    <a href="transfer" class="btn-acao-lancamento" title="Gerenciar transferência">Transferência</a>
                                <?php elseif (!empty($l['goal_id'])): ?>
                                    <a href="goals" class="btn-acao-lancamento" title="Gerenciar meta">Meta</a>
                                <?php else: ?>
                                    <a href="edit_expense?id=<?= $l['id'] ?>&origem=extrato" class="btn-acao-lancamento" title="Editar este lançamento">Editar</a>
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

            <?php if ($totais['quantidade'] > count($lancamentos)): ?>
                <?php
                $qsParams = $_GET;
                $qsParams['limite'] = $limite + 50;
                $urlMais = 'statement?' . http_build_query($qsParams);
                ?>
                <div style="text-align:center; margin-top:18px;">
                    <a href="<?= htmlspecialchars($urlMais) ?>" class="btn-header-primary" style="display:inline-flex; text-decoration:none; padding:10px 22px;">
                        Carregar mais (<?= count($lancamentos) ?> de <?= $totais['quantidade'] ?>)
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

</main>

<script>
(function() {
    const formLote = document.getElementById('formAcaoLote');
    const loteCount = document.getElementById('loteCount');
    const chkAll = document.getElementById('chkSelecionarTodos');
    const chks = Array.from(document.querySelectorAll('.chk-lote'));

    function syncLote() {
        if (!formLote) return;
        formLote.querySelectorAll('input[name="ids[]"]').forEach(el => el.remove());
        const selecionados = chks.filter(c => c.checked);
        selecionados.forEach(c => {
            const h = document.createElement('input');
            h.type = 'hidden';
            h.name = 'ids[]';
            h.value = c.value;
            formLote.appendChild(h);
        });
        if (loteCount) loteCount.textContent = selecionados.length + ' selecionado(s)';
        formLote.style.display = selecionados.length > 0 ? 'flex' : 'none';
    }

    chks.forEach(c => c.addEventListener('change', syncLote));
    chkAll?.addEventListener('change', () => {
        chks.forEach(c => { c.checked = chkAll.checked; });
        syncLote();
    });
})();

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


