<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

const MAX_EXPENSE_CATEGORIES  = 8;
const MAX_INCOME_CATEGORIES   = 3;
const MIN_CATEGORIES_PER_TYPE = 1;

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $nome = trim($_POST['name'] ?? '');
            $tipo = $_POST['type'] ?? 'expense';
            $budgetRaw = str_replace(['R$', ' ', '.'], ['', '', ''], trim($_POST['budget_limit'] ?? ''));
            $budgetRaw = str_replace(',', '.', $budgetRaw);
            $budgetLimit = ($tipo === 'expense' && is_numeric($budgetRaw) && (float)$budgetRaw > 0) ? (float)$budgetRaw : null;

            $maxPermitido = ($tipo === 'expense') ? MAX_EXPENSE_CATEGORIES : MAX_INCOME_CATEGORIES;
            $tipoFormatado = ($tipo === 'income') ? 'receita' : 'despesa';

            // Regra de Negócio: Limite máximo (8 despesas e 3 receitas)
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ? AND type = ?");
            $stmtCount->execute([$userId, $tipo]);
            $qtdAtual = (int)$stmtCount->fetchColumn();

            if ($qtdAtual >= $maxPermitido) {
                $erro = "Limite atingido: você pode cadastrar no máximo {$maxPermitido} categorias de {$tipoFormatado}.";
            } elseif (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome de categoria válido com até 100 caracteres.';
            } elseif (!in_array($tipo, ['income', 'expense'], true)) {
                $erro = 'Tipo de categoria inválido.';
            } else {
                // Checa unicidade para o mesmo usuário e tipo
                $stmtCheck = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ?");
                $stmtCheck->execute([$userId, $nome, $tipo]);
                if ($stmtCheck->fetch()) {
                    $erro = "Já existe uma categoria de {$tipoFormatado} chamada '{$nome}'.";
                } else {
                    try {
                        $stmtIns = $pdo->prepare("INSERT INTO categories (user_id, name, type, budget_limit) VALUES (?, ?, ?, ?)");
                        $stmtIns->execute([$userId, $nome, $tipo, $budgetLimit]);
                        header("Location: categories?sucesso=" . urlencode("Categoria '{$nome}' criada com sucesso!"));
                        exit;
                    } catch (PDOException $e) {
                        error_log("Erro ao criar categoria: " . $e->getMessage());
                        $erro = 'Erro ao cadastrar a categoria no banco de dados.';
                    }
                }
            }
        } elseif ($acao === 'editar') {
            $catId = (int)($_POST['category_id'] ?? 0);
            $nome  = trim($_POST['name'] ?? '');

            if (!Security::validateCategoryOwnership($pdo, $catId, $userId)) {
                $erro = 'Categoria não encontrada ou não autorizada.';
            } elseif (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome de categoria válido.';
            } else {
                // Busca o tipo da categoria existente
                $stmtTipo = $pdo->prepare("SELECT type FROM categories WHERE id = ? AND user_id = ?");
                $stmtTipo->execute([$catId, $userId]);
                $tipo = $stmtTipo->fetchColumn();

                $budgetRaw = str_replace(['R$', ' ', '.'], ['', '', ''], trim($_POST['budget_limit'] ?? ''));
                $budgetRaw = str_replace(',', '.', $budgetRaw);
                $budgetLimit = ($tipo === 'expense' && is_numeric($budgetRaw) && (float)$budgetRaw > 0) ? (float)$budgetRaw : null;

                // Verifica se outro registro com mesmo nome e tipo já existe
                $stmtCheck = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ? AND id != ?");
                $stmtCheck->execute([$userId, $nome, $tipo, $catId]);

                if ($stmtCheck->fetch()) {
                    $tipoFormatado = $tipo === 'income' ? 'receita' : 'despesa';
                    $erro = "Você já possui outra categoria de {$tipoFormatado} chamada '{$nome}'.";
                } else {
                    try {
                        $stmtUpd = $pdo->prepare("UPDATE categories SET name = ?, budget_limit = ? WHERE id = ? AND user_id = ?");
                        $stmtUpd->execute([$nome, $budgetLimit, $catId, $userId]);
                        header("Location: categories?sucesso=" . urlencode("Categoria '{$nome}' atualizada com sucesso!"));
                        exit;
                    } catch (PDOException $e) {
                        error_log("Erro ao atualizar categoria: " . $e->getMessage());
                        $erro = 'Erro ao salvar a alteração da categoria.';
                    }
                }
            }
        } elseif ($acao === 'excluir') {
            $catId = (int)($_POST['category_id'] ?? 0);

            if (!Security::validateCategoryOwnership($pdo, $catId, $userId)) {
                $erro = 'Categoria não encontrada ou não autorizada.';
            } else {
                // Busca o tipo da categoria a ser excluída
                $stmtTipo = $pdo->prepare("SELECT type FROM categories WHERE id = ? AND user_id = ?");
                $stmtTipo->execute([$catId, $userId]);
                $tipoCat = (string) $stmtTipo->fetchColumn();
                $tipoFormatado = ($tipoCat === 'income') ? 'receita' : 'despesa';

                // Regra de Negócio: No mínimo 1 categoria de cada tipo (não pode excluir a última)
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ? AND type = ?");
                $stmtCount->execute([$userId, $tipoCat]);
                $qtdRestante = (int) $stmtCount->fetchColumn();

                if ($qtdRestante <= MIN_CATEGORIES_PER_TYPE) {
                    $erro = "Não é permitido excluir esta categoria. O sistema exige no mínimo 1 categoria de {$tipoFormatado} ativa.";
                } else {
                    // Regra de Negócio: Impede excluir categoria com transações vinculadas
                    $txStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE category_id = ? AND user_id = ?");
                    $txStmt->execute([$catId, $userId]);
                    $qtdTransacoes = (int) $txStmt->fetchColumn();

                    if ($qtdTransacoes > 0) {
                        $erro = "Esta categoria possui {$qtdTransacoes} lançamento(s) vinculado(s). Reclassifique ou remova as movimentações antes de excluí-la.";
                    } else {
                        try {
                            $delStmt = $pdo->prepare("DELETE FROM categories WHERE id = ? AND user_id = ?");
                            $delStmt->execute([$catId, $userId]);
                            header("Location: categories?sucesso=" . urlencode("Categoria excluída com sucesso!"));
                            exit;
                        } catch (PDOException $e) {
                            error_log("Erro ao excluir categoria: " . $e->getMessage());
                            $erro = 'Erro ao excluir a categoria.';
                        }
                    }
                }
            }
        }
    }
}

// Busca categorias com contagem de transações, volume histórico e gasto do mês atual para tetos
$stmt = $pdo->prepare(
    "SELECT 
        c.id, 
        c.name, 
        c.type, 
        c.budget_limit,
        c.created_at,
        COUNT(t.id) AS total_lancamentos,
        COALESCE(SUM(t.amount), 0) AS total_movimentado,
        COALESCE(SUM(CASE WHEN t.status = 'completed' AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE()) THEN t.amount ELSE 0 END), 0) AS gasto_mes_atual
     FROM categories c
     LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = c.user_id
     WHERE c.user_id = ?
     GROUP BY c.id, c.name, c.type, c.budget_limit, c.created_at
     ORDER BY c.name ASC"
);
$stmt->execute([$userId]);
$todasCategorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$despesas = [];
$receitas = [];
$totalTetosDefinidos = 0;
$totalLimiteTetos    = 0.0;
$totalGastoEmTetos   = 0.0;

foreach ($todasCategorias as $cat) {
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
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'categorias';
$tituloPagina = 'Gestão de Categorias';
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

    <!-- Resumo dos Contadores -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Categorias de Despesa</span>
            <span class="valor negativo">
                <?= count($despesas) ?> <span style="font-size:14px; color:var(--color-text-secondary); font-weight:normal;">/ <?= MAX_EXPENSE_CATEGORIES ?></span>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                <?= count($despesas) >= MAX_EXPENSE_CATEGORIES ? 'Limite máximo atingido (8)' : (MAX_EXPENSE_CATEGORIES - count($despesas)) . ' vaga(s) disponível(is)' ?>
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Categorias de Receita</span>
            <span class="valor positivo">
                <?= count($receitas) ?> <span style="font-size:14px; color:var(--color-text-secondary); font-weight:normal;">/ <?= MAX_INCOME_CATEGORIES ?></span>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                <?= count($receitas) >= MAX_INCOME_CATEGORIES ? 'Limite máximo atingido (3)' : (MAX_INCOME_CATEGORIES - count($receitas)) . ' vaga(s) disponível(is)' ?>
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Tetos do Mês (Orçamento)</span>
            <span class="valor" style="color:var(--color-gold);">
                <?= $totalTetosDefinidos > 0 ? 'R$ ' . number_format($totalGastoEmTetos, 2, ',', '.') : 'Nenhum teto' ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                <?= $totalTetosDefinidos > 0 ? 'de R$ ' . number_format($totalLimiteTetos, 2, ',', '.') . ' planejado (' . $totalTetosDefinidos . ' categorias)' : 'Defina limites para controlar gastos' ?>
            </span>
        </div>
    </section>

    <!-- Grade de Categorias e Formulário -->
    <section class="grade">

        <!-- Colunas de Categorias -->
        <div class="bloco">
            <div class="cabecalho-secao" style="margin-bottom:16px;">
                <h2 style="margin:0;">Categorias de Despesas</h2>
                <span style="font-size:12px; color:var(--color-text-secondary);"><?= count($despesas) ?> de <?= MAX_EXPENSE_CATEGORIES ?> cadastradas (mín. 1)</span>
            </div>

            <div style="display:flex; flex-direction:column; gap:10px;">
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
                        $statusCor = $pct > 100 ? 'var(--color-danger)' : ($pct >= 70 ? 'var(--color-gold)' : 'var(--color-emerald)');
                        $statusBadge = $pct > 100 ? 'Estourado' : ($pct >= 70 ? 'Atenção' : 'No teto');
                        ?>
                        <div style="display:flex; flex-direction:column; gap:8px; background:var(--color-surface-secondary); padding:12px 14px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                                <div style="min-width:0; flex:1;">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                        <strong style="color:var(--color-text); font-size:14px;"><?= htmlspecialchars($cat['name']) ?></strong>
                                        <?php if ($temTeto): ?>
                                            <span style="font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; padding:2px 6px; border-radius:4px; background:rgba(0,0,0,0.3); border:1px solid <?= $statusCor ?>; color:<?= $statusCor ?>;">
                                                <?= $statusBadge ?> · <?= $pct ?>%
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span style="display:block; font-size:11px; color:var(--color-text-secondary); margin-top:2px;">
                                        <?= $cat['total_lancamentos'] ?> lançamento(s) · Histórico: R$ <?= number_format($cat['total_movimentado'], 2, ',', '.') ?>
                                    </span>
                                </div>
                                <div style="display:flex; gap:6px; flex-shrink:0;">
                                    <button type="button" class="btn-acao-lancamento" onclick="iniciarEdicaoCategoria(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>)">
                                        Editar
                                    </button>
                                    <?php if (count($despesas) <= MIN_CATEGORIES_PER_TYPE): ?>
                                        <button type="button" class="btn-acao-lancamento btn-acao-excluir" style="border:none; opacity:0.4; cursor:not-allowed;" title="O sistema exige no mínimo 1 categoria de despesa ativa." disabled>
                                            Excluir
                                        </button>
                                    <?php else: ?>
                                        <form action="categories" method="POST" style="margin:0;" onsubmit="return confirm('Deseja realmente excluir a categoria \'<?= htmlspecialchars(addslashes($cat['name'])) ?>\'?');">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="acao" value="excluir">
                                            <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;" title="Excluir categoria">
                                                Excluir
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($temTeto): ?>
                                <div style="margin-top:2px; background:rgba(0,0,0,0.2); border-radius:var(--radius-sm); padding:8px 10px; border:1px solid rgba(255,255,255,0.05);">
                                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px; flex-wrap:wrap; gap:4px;">
                                        <span style="color:var(--color-text-secondary);">Gasto neste mês: <strong style="color:var(--color-text);">R$ <?= number_format($gastoMes, 2, ',', '.') ?></strong></span>
                                        <span style="color:var(--color-text-secondary);">Teto mensal: <strong style="color:var(--color-gold);">R$ <?= number_format($limite, 2, ',', '.') ?></strong></span>
                                    </div>
                                    <div style="width:100%; height:6px; background:rgba(255,255,255,0.08); border-radius:999px; overflow:hidden;">
                                        <div style="width:<?= $barWidth ?>%; height:100%; background:<?= $statusCor ?>; border-radius:999px; transition:width 0.4s ease;"></div>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; font-size:11px; margin-top:4px; flex-wrap:wrap; gap:4px;">
                                        <span style="color:<?= $statusCor ?>; font-weight:600;">
                                            <?php if ($pct > 100): ?>
                                                Ultrapassou o teto em R$ <?= number_format($gastoMes - $limite, 2, ',', '.') ?>
                                            <?php else: ?>
                                                R$ <?= number_format($limite - $gastoMes, 2, ',', '.') ?> ainda disponíveis
                                            <?php endif; ?>
                                        </span>
                                        <span style="color:var(--color-text-secondary);"><?= $pct ?>% utilizado</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="cabecalho-secao" style="margin-top:28px; margin-bottom:16px;">
                <h2 style="margin:0;">Categorias de Receitas</h2>
                <span style="font-size:12px; color:var(--color-text-secondary);"><?= count($receitas) ?> de <?= MAX_INCOME_CATEGORIES ?> cadastradas (mín. 1)</span>
            </div>

            <div style="display:flex; flex-direction:column; gap:8px;">
                <?php if (empty($receitas)): ?>
                    <p class="vazio">Nenhuma categoria de receita cadastrada.</p>
                <?php else: ?>
                    <?php foreach ($receitas as $cat): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; background:var(--color-surface-secondary); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
                            <div style="min-width:0; flex:1;">
                                <strong style="color:var(--color-text); font-size:14px;"><?= htmlspecialchars($cat['name']) ?></strong>
                                <span style="display:block; font-size:11px; color:var(--color-text-secondary); margin-top:2px;">
                                    <?= $cat['total_lancamentos'] ?> lançamento(s) · R$ <?= number_format($cat['total_movimentado'], 2, ',', '.') ?>
                                </span>
                            </div>
                            <div style="display:flex; gap:6px; flex-shrink:0;">
                                <button type="button" class="btn-acao-lancamento" onclick="iniciarEdicaoCategoria(<?= htmlspecialchars(json_encode($cat), ENT_QUOTES, 'UTF-8') ?>)">
                                    Editar
                                </button>
                                <?php if (count($receitas) <= MIN_CATEGORIES_PER_TYPE): ?>
                                    <button type="button" class="btn-acao-lancamento btn-acao-excluir" style="border:none; opacity:0.4; cursor:not-allowed;" title="O sistema exige no mínimo 1 categoria de receita ativa." disabled>
                                        Excluir
                                    </button>
                                <?php else: ?>
                                    <form action="categories" method="POST" style="margin:0;" onsubmit="return confirm('Deseja realmente excluir a categoria \'<?= htmlspecialchars(addslashes($cat['name'])) ?>\'?');">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;" title="Excluir categoria">
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
                    <input type="text" name="name" id="catNome" placeholder="Ex: Farmácia, Freelance, Assinaturas..." maxlength="100" required>
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
}

// Oculta campo de teto automaticamente se o usuário selecionar "Receita"
const selectTipo = document.getElementById('catTipo');
if (selectTipo) {
    selectTipo.addEventListener('change', function() {
        const campoBudget = document.getElementById('campoBudgetContainer');
        if (this.value === 'expense') {
            campoBudget.style.display = 'block';
        } else {
            campoBudget.style.display = 'none';
            document.getElementById('catBudget').value = '';
        }
    });
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


