<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);
CategoryService::ensureSystemCategories($pdo, $userId);

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

const MAX_EXPENSE_CATEGORIES  = CategoryService::MAX_EXPENSE_CATEGORIES;
const MAX_INCOME_CATEGORIES   = CategoryService::MAX_INCOME_CATEGORIES;
const MIN_CATEGORIES_PER_TYPE = CategoryService::MIN_CATEGORIES_PER_TYPE;

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } elseif (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
        Security::flashRedirect('categories', 'sucesso', 'Essa operação já tinha sido registrada.');
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $nome = trim($_POST['name'] ?? '');
            $tipo = $_POST['type'] ?? 'expense';
            $budgetRaw = str_replace(['R$', ' ', '.'], ['', '', ''], trim($_POST['budget_limit'] ?? ''));
            $budgetRaw = str_replace(',', '.', $budgetRaw);
            $budgetLimit = ($tipo === 'expense' && is_numeric($budgetRaw) && (float)$budgetRaw > 0) ? (float)$budgetRaw : null;

            $resultado = CategoryService::criarOuReciclar($pdo, $userId, $nome, $tipo, $budgetLimit);
            if (!$resultado['sucesso']) {
                $erro = $resultado['erro'];
            } else {
                $msg = "Categoria '{$resultado['nome']}' criada com sucesso!";
                if ($resultado['acao'] === 'reciclado') {
                    $msg = "Categoria '{$resultado['nome']}' criada com sucesso (slot reaproveitado via Object Pool)!";
                } elseif ($resultado['acao'] === 'reativado') {
                    $msg = "Categoria '{$resultado['nome']}' reativada com sucesso!";
                }
                Security::flashRedirect('categories', 'sucesso', $msg);
            }
        } elseif ($acao === 'editar') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $nome  = trim($_POST['name'] ?? '');
            $budgetRaw = str_replace(['R$', ' ', '.'], ['', '', ''], trim($_POST['budget_limit'] ?? ''));
            $budgetRaw = str_replace(',', '.', $budgetRaw);
            $budgetLimit = (is_numeric($budgetRaw) && (float)$budgetRaw > 0) ? (float)$budgetRaw : null;

            $resultado = CategoryService::editar($pdo, $userId, $catId, $nome, $budgetLimit);
            if (!$resultado['sucesso']) {
                $erro = $resultado['erro'];
            } else {
                Security::flashRedirect('categories', 'sucesso', "Categoria '{$resultado['nome']}' atualizada com sucesso!");
            }
        } elseif ($acao === 'excluir') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $resultado = CategoryService::excluir($pdo, $userId, $catId);
            if (!$resultado['sucesso']) {
                $erro = $resultado['erro'];
            } else {
                Security::flashRedirect('categories', 'sucesso', $resultado['mensagem']);
            }
        }
    }
}

// Busca categorias ativas com contagem de transações, volume histórico e gasto do mês atual para tetos
$stmt = $pdo->prepare(
    "SELECT 
        c.id, 
        c.name, 
        c.type, 
        c.budget_limit,
        c.is_system,
        c.created_at,
        COUNT(CASE WHEN t.visivel = 1 THEN t.id END) AS total_lancamentos,
        COALESCE(SUM(CASE WHEN t.visivel = 1 THEN t.amount ELSE 0 END), 0) AS total_movimentado,
        COALESCE(SUM(CASE WHEN t.visivel = 1 AND t.status = 'completed' AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE()) THEN t.amount ELSE 0 END), 0) AS gasto_mes_atual
     FROM categories c
     LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = c.user_id
     WHERE c.user_id = ? AND c.visivel = 1
     GROUP BY c.id, c.name, c.type, c.budget_limit, c.is_system, c.created_at
     ORDER BY c.name ASC"
);
$stmt->execute([$userId]);
$todasCategorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$despesas = [];
$receitas = [];
$categoriasSistema = [];
$totalTetosDefinidos = 0;
$totalLimiteTetos    = 0.0;
$totalGastoEmTetos   = 0.0;

foreach ($todasCategorias as $cat) {
    $ehSistema = ((int)($cat['is_system'] ?? 0) === 1) || CategoryService::isSystemCategoryName((string)$cat['name']);
    if ($ehSistema) {
        $categoriasSistema[] = $cat;
        continue;
    }
    if ($cat['type'] === 'expense') {
        $despesas[] = $cat;
        if (!empty($cat['budget_limit']) && (float)$cat['budget_limit'] > 0) {
            $totalTetosDefinidos++;
            $totalLimiteTetos += (float)$cat['budget_limit'];
            $totalGastoEmTetos += (float)$cat['gasto_mes_atual'];
        }
    } else {
        $receitas[] = $cat;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Gestão de Categorias</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'categorias';
$tituloPagina = 'Gestão de Categorias';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($erro)): ?>
        <div class="droma-alert droma-alert--error" role="alert">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div class="alerta-dashboard">
            <span><?= htmlspecialchars($sucesso) ?></span>
            <span style="cursor:pointer; font-weight:bold; font-size:16px;" onclick="this.parentElement.remove();">&times;</span>
        </div>
    <?php endif; ?>

    <!-- Resumo dos Contadores -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Categorias de Despesa</span>
            <span class="valor negativo">
                <?= count($despesas) ?> <span class="droma-subtext droma-m-0">/ <?= MAX_EXPENSE_CATEGORIES ?></span>
            </span>
            <span class="droma-subtext">
                <?= count($despesas) >= MAX_EXPENSE_CATEGORIES ? 'Limite máximo atingido (8)' : (MAX_EXPENSE_CATEGORIES - count($despesas)) . ' vaga(s) disponível(is)' ?>
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Categorias de Receita</span>
            <span class="valor positivo">
                <?= count($receitas) ?> <span class="droma-subtext droma-m-0">/ <?= MAX_INCOME_CATEGORIES ?></span>
            </span>
            <span class="droma-subtext">
                <?= count($receitas) >= MAX_INCOME_CATEGORIES ? 'Limite máximo atingido (3)' : (MAX_INCOME_CATEGORIES - count($receitas)) . ' vaga(s) disponível(is)' ?>
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Tetos do Mês (Orçamento)</span>
            <span class="valor droma-text-gold">
                <?= $totalTetosDefinidos > 0 ? 'R$ ' . number_format($totalGastoEmTetos, 2, ',', '.') : 'Nenhum teto' ?>
            </span>
            <span class="droma-subtext">
                <?= $totalTetosDefinidos > 0 ? 'de R$ ' . number_format($totalLimiteTetos, 2, ',', '.') . ' planejado (' . $totalTetosDefinidos . ' categorias)' : 'Defina limites para controlar gastos' ?>
            </span>
        </div>
    </section>

    <!-- Grade de Categorias e Formulário -->
    <section class="grade">

        <!-- Colunas de Categorias -->
        <div class="bloco">
            <div class="cabecalho-secao droma-mb-md">
                <h2 class="droma-m-0">Categorias de Despesas</h2>
                <span class="droma-subtext droma-m-0"><?= count($despesas) ?> de <?= MAX_EXPENSE_CATEGORIES ?> cadastradas (mín. 1)</span>
            </div>

            <div class="droma-flex-wrap" style="flex-direction:column; gap:10px; margin-bottom:0;">
                <?php if (empty($despesas)): ?>
                    <p class="vazio">Nenhuma categoria de despesa cadastrada.</p>
                <?php else: ?>
                    <?php foreach ($despesas as $cat): ?>
                        <?php
                        $temTeto = !empty($cat['budget_limit']) && (float)$cat['budget_limit'] > 0;
                        $limite = $temTeto ? (float)$cat['budget_limit'] : 0.0;
                        $gastoMes = (float)($cat['gasto_mes_atual'] ?? 0);
                        $pct = $limite > 0 ? round(($gastoMes / $limite) * 100, 1) : 0;
                        $barWidth = min(100, $pct);
                        $fillCls = $pct > 100 ? 'droma-progress-fill--danger' : ($pct >= 70 ? 'droma-progress-fill--warning' : 'droma-progress-fill--emerald');
                        $statusBadge = $pct > 100 ? 'Estourado' : ($pct >= 70 ? 'Atenção' : 'No teto');
                        $statusTextCls = $pct > 100 ? 'droma-text-danger' : ($pct >= 70 ? 'droma-text-gold' : 'droma-text-emerald');
                        ?>
                        <div class="droma-card" style="padding:12px 14px;">
                            <div class="droma-flex-center" style="flex-wrap:wrap; gap:8px;">
                                <div class="droma-flex-1">
                                    <div class="droma-flex-wrap" style="align-items:center; gap:8px; margin-bottom:0;">
                                        <span style="display:inline-block; width:9px; height:9px; border-radius:50%; background:<?= Security::categoryColor($cat['name']) ?>; flex-shrink:0;"></span>
                                        <strong class="droma-text-primary"><?= htmlspecialchars($cat['name']) ?></strong>
                                        <?php if ($temTeto): ?>
                                            <?php $badgeCls = $pct > 100 ? 'droma-badge--danger' : ($pct >= 70 ? 'droma-badge--warning' : 'droma-badge--success'); ?>
                                            <span class="droma-badge <?= $badgeCls ?> tabular-nums">
                                                <?= $statusBadge ?> · <?= $pct ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="droma-subtext-xs">
                                        <?= $cat['total_lancamentos'] ?> lançamento(s) · Histórico: R$ <?= number_format($cat['total_movimentado'], 2, ',', '.') ?>
                                    </span>
                                </div>
                                <div class="droma-flex-row droma-m-0" style="gap:6px;">
                                    <button type="button" class="btn-acao-lancamento" onclick="iniciarEdicaoCategoria(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>)">
                                        Editar
                                    </button>
                                    <?php if (count($despesas) <= MIN_CATEGORIES_PER_TYPE): ?>
                                        <button type="button" class="btn-acao-lancamento btn-acao-excluir droma-btn-reset" style="opacity:0.4; cursor:not-allowed;" title="O sistema exige no mínimo 1 categoria de despesa ativa." disabled>
                                            Excluir
                                        </button>
                                    <?php else: ?>
                                        <form action="categories" method="POST" class="droma-m-0" data-confirm="Deseja realmente excluir a categoria &quot;<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>&quot;?" data-confirm-title="Excluir Categoria?">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="acao" value="excluir">
                                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn-acao-lancamento btn-acao-excluir droma-btn-reset" title="Excluir categoria">
                                                Excluir
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($temTeto): ?>
                                <div class="droma-box-inset">
                                    <div class="droma-flex-between droma-fs-11 droma-mb-sm">
                                        <span class="droma-text-muted">Gasto neste mês: <strong class="droma-text-primary">R$ <?= number_format($gastoMes, 2, ',', '.') ?></strong></span>
                                        <span class="droma-text-muted">Teto mensal: <strong class="droma-text-gold">R$ <?= number_format($limite, 2, ',', '.') ?></strong></span>
                                    </div>
                                    <div class="droma-progress">
                                        <div class="droma-progress-fill <?= $fillCls ?>" style="width:<?= $barWidth ?>%;"></div>
                                    </div>
                                    <div class="droma-flex-between droma-fs-11 droma-mt-xs">
                                        <span class="<?= $statusTextCls ?> droma-fw-700">
                                            <?php if ($pct > 100): ?>
                                                Ultrapassou o teto em R$ <?= number_format($gastoMes - $limite, 2, ',', '.') ?>
                                            <?php else: ?>
                                                R$ <?= number_format($limite - $gastoMes, 2, ',', '.') ?> ainda disponíveis
                                            <?php endif; ?>
                                        </span>
                                        <span class="droma-text-muted"><?= $pct ?>% utilizado</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="cabecalho-secao droma-mb-md" style="margin-top:28px;">
                <h2 class="droma-m-0">Categorias de Receitas</h2>
                <span class="droma-subtext droma-m-0"><?= count($receitas) ?> de <?= MAX_INCOME_CATEGORIES ?> cadastradas (mín. 1)</span>
            </div>

            <div class="droma-flex-wrap" style="flex-direction:column; gap:8px; margin-bottom:0;">
                <?php if (empty($receitas)): ?>
                    <p class="vazio">Nenhuma categoria de receita cadastrada.</p>
                <?php else: ?>
                    <?php foreach ($receitas as $cat): ?>
                        <div class="droma-card droma-flex-center" style="padding:10px 14px; flex-wrap:wrap; gap:8px;">
                            <div class="droma-flex-1">
                                <strong class="droma-text-primary"><?= htmlspecialchars($cat['name']) ?></strong>
                                <span class="droma-subtext-xs" style="display:block;">
                                    <?= $cat['total_lancamentos'] ?> lançamento(s) · R$ <?= number_format($cat['total_movimentado'], 2, ',', '.') ?>
                                </span>
                            </div>
                            <div class="droma-flex-row droma-m-0" style="gap:6px;">
                                <button type="button" class="btn-acao-lancamento" onclick="iniciarEdicaoCategoria(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>)">
                                    Editar
                                </button>
                                <?php if (count($receitas) <= MIN_CATEGORIES_PER_TYPE): ?>
                                    <button type="button" class="btn-acao-lancamento btn-acao-excluir droma-btn-reset" style="opacity:0.4; cursor:not-allowed;" title="O sistema exige no mínimo 1 categoria de receita ativa." disabled>
                                        Excluir
                                    </button>
                                <?php else: ?>
                                    <form action="categories" method="POST" class="droma-m-0" data-confirm="Deseja realmente excluir a categoria &quot;<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>&quot;?" data-confirm-title="Excluir Categoria?">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento btn-acao-excluir droma-btn-reset" title="Excluir categoria">
                                            Excluir
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulário para Criar ou Editar Categoria -->
        <div class="bloco" style="height:fit-content;">
            <h2 id="formTituloCategoria">+ Nova Categoria</h2>

            <?php
            $limiteDespesaAtingido = count($despesas) >= MAX_EXPENSE_CATEGORIES;
            $limiteReceitaAtingido = count($receitas) >= MAX_INCOME_CATEGORIES;
            $todosLimitesAtingidos = $limiteDespesaAtingido && $limiteReceitaAtingido;
            ?>

            <?php if ($todosLimitesAtingidos): ?>
                <div id="alertaLimitesAtingidos" style="background:rgba(212,175,108,0.12); border:1px solid rgba(212,175,108,0.3); color:var(--color-gold); padding:10px 12px; border-radius:var(--radius-sm); font-size:12px; margin-bottom:12px; line-height:1.5;">
                    Você atingiu o limite de categorias (<?= MAX_EXPENSE_CATEGORIES ?> despesas e <?= MAX_INCOME_CATEGORIES ?> receitas). Para cadastrar uma nova, edite uma existente ou exclua uma categoria sem lançamentos.
                </div>
            <?php endif; ?>

            <form id="formCategoria" action="categories" method="POST" class="form-lancamento" style="max-width:100%; padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" id="catAcao" value="criar">
                <input type="hidden" name="category_id" id="catId" value="">

                <label>Nome da Categoria
                    <input type="text" name="name" id="catNome" placeholder="<?= $limiteDespesaAtingido && !$limiteReceitaAtingido ? 'Ex: Salário, Freelance, Dividendos, Aluguel...' : 'Ex: Alimentação, Moradia, Farmácia, Assinaturas...' ?>" maxlength="100" required>
                </label>

                <label id="campoTipoCategoria">Tipo de Categoria
                    <select name="type" id="catTipo" required>
                        <option value="expense" <?= $limiteDespesaAtingido ? 'disabled' : 'selected' ?>>
                            Despesa (<?= count($despesas) ?>/<?= MAX_EXPENSE_CATEGORIES ?>) <?= $limiteDespesaAtingido ? '— Limite Atingido (8/8)' : '' ?>
                        </option>
                        <option value="income" <?= $limiteReceitaAtingido ? 'disabled' : ($limiteDespesaAtingido ? 'selected' : '') ?>>
                            Receita (<?= count($receitas) ?>/<?= MAX_INCOME_CATEGORIES ?>) <?= $limiteReceitaAtingido ? '— Limite Atingido (3/3)' : '' ?>
                        </option>
                    </select>
                </label>

                <div id="campoBudgetContainer">
                    <label>Teto Mensal de Gastos (R$)
                        <span style="font-size:11px; color:var(--color-text-secondary); font-weight:normal; display:block; margin-bottom:4px;">
                            Limite máximo planejado para o mês (opcional).
                        </span>
                        <input type="text" name="budget_limit" id="catBudget" placeholder="Ex: 800,00" inputmode="decimal">
                    </label>
                </div>

                <div style="display:flex; gap:10px; margin-top:8px;">
                    <button type="submit" id="btnSalvarCategoria" style="flex:1;" <?= $todosLimitesAtingidos ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : '' ?>>Criar Categoria</button>
                    <button type="button" id="btnCancelarEdicaoCat" class="sair" style="display:none;" onclick="cancelarEdicaoCategoria()">Cancelar</button>
                </div>
            </form>
        </div>

    </section>

</main>

<script>
function iniciarEdicaoCategoria(cat) {
    document.getElementById('formTituloCategoria').textContent = 'Editar Categoria: ' + cat.name;
    document.getElementById('catAcao').value = 'editar';
    document.getElementById('catId').value = cat.id;
    document.getElementById('catNome').value = cat.name;

    const alerta = document.getElementById('alertaLimitesAtingidos');
    if (alerta) alerta.style.display = 'none';

    const campoBudget = document.getElementById('campoBudgetContainer');
    const inputBudget = document.getElementById('catBudget');
    if (cat.type === 'expense') {
        campoBudget.style.display = 'block';
        inputBudget.value = cat.budget_limit ? parseFloat(cat.budget_limit).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
    } else {
        campoBudget.style.display = 'none';
        inputBudget.value = '';
    }

    document.getElementById('campoTipoCategoria').style.display = 'none';
    const btnSalvar = document.getElementById('btnSalvarCategoria');
    btnSalvar.textContent = 'Salvar Alteração';
    btnSalvar.disabled = false;
    btnSalvar.style.opacity = '1';
    btnSalvar.style.cursor = 'pointer';

    document.getElementById('btnCancelarEdicaoCat').style.display = 'inline-block';
    document.getElementById('formCategoria').scrollIntoView({ behavior: 'smooth' });
}

function cancelarEdicaoCategoria() {
    document.getElementById('formTituloCategoria').textContent = '+ Nova Categoria';
    document.getElementById('catAcao').value = 'criar';
    document.getElementById('catId').value = '';
    document.getElementById('catNome').value = '';
    document.getElementById('catBudget').value = '';

    const alerta = document.getElementById('alertaLimitesAtingidos');
    if (alerta) alerta.style.display = 'block';

    document.getElementById('campoBudgetContainer').style.display = 'block';
    document.getElementById('campoTipoCategoria').style.display = 'block';
    const btnSalvar = document.getElementById('btnSalvarCategoria');
    btnSalvar.textContent = 'Criar Categoria';
    <?php if ($todosLimitesAtingidos): ?>
        btnSalvar.disabled = true;
        btnSalvar.style.opacity = '0.6';
        btnSalvar.style.cursor = 'not-allowed';
    <?php endif; ?>
    document.getElementById('btnCancelarEdicaoCat').style.display = 'none';
    atualizarCamposPorTipo();
}

function atualizarCamposPorTipo() {
    const selectTipo = document.getElementById('catTipo');
    const inputNome = document.getElementById('catNome');
    const campoBudget = document.getElementById('campoBudgetContainer');
    if (!selectTipo) return;

    if (selectTipo.value === 'income') {
        if (inputNome) {
            inputNome.placeholder = 'Ex: Salário, Freelance, Dividendos, Aluguel...';
        }
        if (campoBudget) {
            campoBudget.style.display = 'none';
            document.getElementById('catBudget').value = '';
        }
    } else {
        if (inputNome) {
            inputNome.placeholder = 'Ex: Alimentação, Moradia, Farmácia, Assinaturas...';
        }
        if (campoBudget) {
            campoBudget.style.display = 'block';
        }
    }
}

// Atualiza sugestão de nome e visibilidade do teto conforme o tipo selecionado
const selectTipo = document.getElementById('catTipo');
if (selectTipo) {
    selectTipo.addEventListener('change', atualizarCamposPorTipo);
    atualizarCamposPorTipo();
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


