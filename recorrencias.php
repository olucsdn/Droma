<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

// Carrega contas e categorias do usuário
$stmtContas = $pdo->prepare("SELECT id, name FROM accounts WHERE user_id = ? ORDER BY name ASC");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

$stmtCats = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? ORDER BY type DESC, name ASC");
$stmtCats->execute([$userId]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $tipo        = $_POST['type'] ?? 'expense';
            $descricao   = trim($_POST['description'] ?? '');
            $valorRaw    = str_replace(',', '.', trim($_POST['amount'] ?? '0'));
            $accountId   = (int)($_POST['account_id'] ?? 0);
            $categoryId  = (int)($_POST['category_id'] ?? 0);
            $frequencia  = $_POST['frequency'] ?? 'monthly';
            $diaDoMes    = max(1, min(31, (int)($_POST['day_of_month'] ?? 1)));

            if (!in_array($tipo, ['income', 'expense'], true)) {
                $erro = 'Tipo de movimentação inválido.';
            } elseif (empty($descricao) || mb_strlen($descricao) > 150) {
                $erro = 'Informe uma descrição para a recorrência de até 150 caracteres.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
                $erro = 'Informe um valor válido maior que zero.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Conta bancária inválida ou não autorizada.';
            } elseif (!Security::validateCategoryOwnership($pdo, $categoryId, $userId, $tipo)) {
                $erro = 'Categoria inválida ou incompatível com o tipo.';
            } elseif (!in_array($frequencia, ['weekly', 'monthly', 'yearly'], true)) {
                $erro = 'Frequência de repetição inválida.';
            } else {
                // Calcula a primeira data de ocorrência
                $mesAtual = (int)date('n');
                $anoAtual = (int)date('Y');
                $diaHoje = (int)date('j');

                if ($frequencia === 'monthly') {
                    $mesRef = ($diaHoje > $diaDoMes) ? $mesAtual + 1 : $mesAtual;
                    $tsNext = mktime(0, 0, 0, $mesRef, min($diaDoMes, (int)date('t', mktime(0, 0, 0, $mesRef, 1, $anoAtual))), $anoAtual);
                } elseif ($frequencia === 'weekly') {
                    $tsNext = strtotime('+1 week');
                } else {
                    $tsNext = strtotime('+1 year');
                }
                $nextDate = date('Y-m-d', $tsNext);

                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO recurring_transactions
                            (user_id, account_id, category_id, type, amount, description, frequency, day_of_month, next_date, active)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
                    );
                    $stmt->execute([
                        $userId, $accountId, $categoryId, $tipo, (float)$valorRaw,
                        $descricao, $frequencia, $diaDoMes, $nextDate
                    ]);
                    header("Location: recorrencias.php?sucesso=" . urlencode("Recorrência '{$descricao}' configurada com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao salvar recorrência: " . $e->getMessage());
                    $erro = 'Erro ao cadastrar recorrência no banco de dados.';
                }
            }
        } elseif ($acao === 'toggle_active') {
            $recId = (int)($_POST['id'] ?? 0);
            try {
                $stmt = $pdo->prepare("UPDATE recurring_transactions SET active = NOT active WHERE id = ? AND user_id = ?");
                $stmt->execute([$recId, $userId]);
                header("Location: recorrencias.php?sucesso=" . urlencode("Status da recorrência atualizado!"));
                exit;
            } catch (PDOException $e) {
                error_log("Erro ao alterar status da recorrência: " . $e->getMessage());
                $erro = 'Erro ao atualizar o status.';
            }
        } elseif ($acao === 'lancar_agora') {
            $recId = (int)($_POST['id'] ?? 0);
            
            // Busca dados da recorrência
            $stmt = $pdo->prepare("SELECT * FROM recurring_transactions WHERE id = ? AND user_id = ?");
            $stmt->execute([$recId, $userId]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {
                $erro = 'Recorrência não encontrada.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // Cria o lançamento em transactions
                    $insTx = $pdo->prepare(
                        "INSERT INTO transactions
                            (user_id, account_id, category_id, type, amount, description, date, status, urgency)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'media')"
                    );
                    $insTx->execute([
                        $userId, $rec['account_id'], $rec['category_id'], $rec['type'],
                        $rec['amount'], $rec['description'] . ' (Recorrente)', $rec['next_date']
                    ]);

                    // Avança a próxima data da recorrência
                    if ($rec['frequency'] === 'monthly') {
                        $proxTs = strtotime("+1 month", strtotime($rec['next_date']));
                    } elseif ($rec['frequency'] === 'weekly') {
                        $proxTs = strtotime("+1 week", strtotime($rec['next_date']));
                    } else {
                        $proxTs = strtotime("+1 year", strtotime($rec['next_date']));
                    }
                    $novoNextDate = date('Y-m-d', $proxTs);

                    $updRec = $pdo->prepare("UPDATE recurring_transactions SET next_date = ? WHERE id = ?");
                    $updRec->execute([$novoNextDate, $recId]);

                    $pdo->commit();

                    $msg = "Lançamento recorrente de R$ " . number_format($rec['amount'], 2, ',', '.') . " lançado com sucesso! Próxima data: " . date('d/m/Y', $proxTs);
                    header("Location: recorrencias.php?sucesso=" . urlencode($msg));
                    exit;

                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("Erro ao lançar recorrência: " . $e->getMessage());
                    $erro = 'Erro ao efetivar o lançamento no banco de dados.';
                }
            }
        } elseif ($acao === 'excluir') {
            $recId = (int)($_POST['id'] ?? 0);
            try {
                $del = $pdo->prepare("DELETE FROM recurring_transactions WHERE id = ? AND user_id = ?");
                $del->execute([$recId, $userId]);
                header("Location: recorrencias.php?sucesso=" . urlencode("Recorrência removida com sucesso!"));
                exit;
            } catch (PDOException $e) {
                error_log("Erro ao excluir recorrência: " . $e->getMessage());
                $erro = 'Erro ao excluir a recorrência.';
            }
        }
    }
}

// Carrega as recorrências com os nomes da conta e categoria
$stmt = $pdo->prepare(
    "SELECT 
        r.*,
        a.name AS conta_nome,
        c.name AS categoria_nome
     FROM recurring_transactions r
     LEFT JOIN accounts a ON a.id = r.account_id
     LEFT JOIN categories c ON c.id = r.category_id
     WHERE r.user_id = ?
     ORDER BY r.active DESC, r.next_date ASC"
);
$stmt->execute([$userId]);
$recorrencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalDespesasRecorrentes = 0.00;
$totalReceitasRecorrentes = 0.00;

foreach ($recorrencias as $r) {
    if ($r['active']) {
        if ($r['type'] === 'expense') {
            $totalDespesasRecorrentes += (float)$r['amount'];
        } else {
            $totalReceitasRecorrentes += (float)$r['amount'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wert - Lançamentos Recorrentes</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'recorrencias';
$tituloPagina = 'Lançamentos Recorrentes';
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

    <!-- Resumo dos Fixos / Recorrentes -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Despesas Fixas / Mês</span>
            <span class="valor negativo">
                - R$ <?= number_format($totalDespesasRecorrentes, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Compromissos fixos mensais</span>
        </div>

        <div class="cartao">
            <span class="rotulo">Receitas Fixas / Mês</span>
            <span class="valor positivo">
                + R$ <?= number_format($totalReceitasRecorrentes, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Salários e rendimentos fixos</span>
        </div>

        <div class="cartao">
            <span class="rotulo">Balanço das Recorrências</span>
            <?php $balancoRec = $totalReceitasRecorrentes - $totalDespesasRecorrentes; ?>
            <span class="valor <?= $balancoRec >= 0 ? 'positivo' : 'negativo' ?>">
                R$ <?= number_format($balancoRec, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Saldo fixo planejado</span>
        </div>

        <div class="cartao">
            <span class="rotulo">Recorrências Ativas</span>
            <span class="valor" style="color:var(--color-gold);"><?= count(array_filter($recorrencias, fn($r) => $r['active'])) ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">Automação de fluxo</span>
        </div>
    </section>

    <!-- Grade de Recorrências e Formulário -->
    <section class="grade">

        <!-- Lista de Recorrências -->
        <div class="bloco">
            <h2 style="margin-bottom:16px;">Compromissos Recorrentes Cadastrados</h2>

            <?php if (empty($recorrencias)): ?>
                <p class="vazio">Nenhuma despesa ou receita recorrente cadastrada ainda. Utilize o formulário ao lado para automatizar contas como aluguel, internet, academia e salário!</p>
            <?php else: ?>
                <ul class="lista-lancamentos">
                    <?php foreach ($recorrencias as $r): 
                        $rotulosFreq = ['monthly' => 'Mensal', 'weekly' => 'Semanal', 'yearly' => 'Anual'];
                    ?>
                        <li style="opacity: <?= $r['active'] ? '1' : '0.5' ?>;">
                            <div class="info">
                                <span class="descricao">
                                    <?= htmlspecialchars($r['description']) ?>
                                    <?php if (!$r['active']): ?>
                                        <span class="badge-status" style="background:rgba(120,133,146,0.2); color:#A7B0BA;">Pausada</span>
                                    <?php endif; ?>
                                </span>
                                <span class="categoria">
                                    <?= htmlspecialchars($r['categoria_nome'] ?? 'Sem categoria') ?>
                                    · <strong style="color:var(--color-text);"><?= htmlspecialchars($r['conta_nome'] ?? 'Sem conta') ?></strong>
                                    · Repete: <strong><?= $rotulosFreq[$r['frequency']] ?? $r['frequency'] ?></strong> (Dia <?= $r['day_of_month'] ?>)
                                    · Próxima: <strong><?= date('d/m/Y', strtotime($r['next_date'])) ?></strong>
                                </span>
                            </div>

                            <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                <span class="valor-item <?= $r['type'] === 'income' ? 'positivo' : 'negativo' ?>">
                                    <?= $r['type'] === 'income' ? '+' : '-' ?> R$ <?= number_format($r['amount'], 2, ',', '.') ?>
                                </span>
                                <div style="display:flex; gap:6px;">
                                    <?php if ($r['active']): ?>
                                        <form action="recorrencias.php" method="POST" style="margin:0;">
                                            <?= Security::csrfField() ?>
                                            <input type="hidden" name="acao" value="lancar_agora">
                                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="btn-aba ativo" style="padding:4px 10px; font-size:11px;" title="Registrar lançamento imediatamente">
                                                ⚡ Lançar
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form action="recorrencias.php" method="POST" style="margin:0;">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento" style="border:none; cursor:pointer;" title="Pausar ou ativar repetição">
                                            <?= $r['active'] ? 'Pausar' : 'Reativar' ?>
                                        </button>
                                    </form>

                                    <form action="recorrencias.php" method="POST" style="margin:0;" onsubmit="return confirm('Deseja realmente excluir esta recorrência?');">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;">
                                            Excluir
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Formulário para Nova Recorrência -->
        <div class="bloco" style="height:fit-content;">
            <h2>+ Nova Recorrência</h2>
            <form action="recorrencias.php" method="POST" class="form-lancamento" style="max-width:100%; padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="criar">

                <label>Tipo
                    <select name="type" id="tipoRecorrencia" required>
                        <option value="expense" selected>Despesa Fixa (Saída)</option>
                        <option value="income">Receita Fixa (Entrada)</option>
                    </select>
                </label>

                <label>Descrição
                    <input type="text" name="description" placeholder="Ex: Aluguel, Netflix, Internet, Salário..." maxlength="150" required>
                </label>

                <label>Valor (R$)
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="0,00" required>
                </label>

                <label>Conta Bancária
                    <select name="account_id" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Categoria
                    <select name="category_id" id="categoriaRecorrencia" required>
                        <?php foreach ($categorias as $cat): ?>
                            <option value="<?= $cat['id'] ?>" data-type="<?= $cat['type'] ?>">
                                <?= htmlspecialchars($cat['name']) ?> (<?= $cat['type'] === 'income' ? 'receita' : 'despesa' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <label>Frequência
                        <select name="frequency" required>
                            <option value="monthly" selected>Mensal</option>
                            <option value="weekly">Semanal</option>
                            <option value="yearly">Anual</option>
                        </select>
                    </label>

                    <label>Dia do Mês
                        <input type="number" min="1" max="31" name="day_of_month" value="5" required>
                    </label>
                </div>

                <button type="submit">Cadastrar Recorrência</button>
            </form>
        </div>

    </section>

</main>

<script>
const tipoRecSelect = document.getElementById('tipoRecorrencia');
const catRecSelect = document.getElementById('categoriaRecorrencia');

function filtrarCategoriasRec() {
    const tipo = tipoRecSelect.value;
    let primeiro = null;
    Array.from(catRecSelect.options).forEach(opt => {
        const matches = opt.getAttribute('data-type') === tipo;
        opt.style.display = matches ? '' : 'none';
        opt.disabled = !matches;
        if (matches && !primeiro) primeiro = opt;
    });
    if (primeiro && catRecSelect.selectedOptions[0]?.disabled) {
        catRecSelect.value = primeiro.value;
    }
}

tipoRecSelect.addEventListener('change', filtrarCategoriasRec);
filtrarCategoriasRec();
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
