<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();
$id = $_GET['id'] ?? $_POST['id'] ?? 0;

if (!ctype_digit((string)$id) || (int)$id <= 0) {
    header("Location: ./");
    exit;
}

$id = (int)$id;

// Busca o lançamento garantindo que pertença ao usuário logado
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$lancamento = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lancamento) {
    header("Location: ./");
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type        = $_POST['type'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $amountRaw   = trim($_POST['amount'] ?? '');
    if (is_string($amountRaw)) {
        $amountRaw = str_replace(['R$', ' '], '', $amountRaw);
        if (str_contains($amountRaw, ',') && str_contains($amountRaw, '.')) {
            $amountRaw = str_replace('.', '', $amountRaw);
            $amountRaw = str_replace(',', '.', $amountRaw);
        } elseif (str_contains($amountRaw, ',')) {
            $amountRaw = str_replace(',', '.', $amountRaw);
        }
    }
    $amount      = is_numeric($amountRaw) ? (float)$amountRaw : 0;
    $accountId   = $_POST['account_id'] ?? '';
    $categoryId  = $_POST['category_id'] ?? '';
    $creditCardId = !empty($_POST['credit_card_id']) && is_numeric($_POST['credit_card_id']) ? (int)$_POST['credit_card_id'] : null;
    $investmentId = !empty($_POST['investment_id']) && is_numeric($_POST['investment_id']) ? (int)$_POST['investment_id'] : null;
    $date        = $_POST['date'] ?? '';
    $dueDate     = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
    $status      = $_POST['status'] ?? 'pending';
    $urgency     = $_POST['urgency'] ?? 'media';

    // Urgência não se aplica a entradas; nunca aceite um valor enviado pelo navegador.
    if ($type === 'income') {
        $urgency = 'media';
        $creditCardId = null; // Entradas não são associadas a cartão de crédito
    }

    if ($investmentId !== null && !Security::validateInvestmentOwnership($pdo, $investmentId, $userId)) {
        $investmentId = null;
    }

    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } elseif (!in_array($type, ['income', 'expense'], true)
        || !is_numeric($amount) || $amount <= 0
        || !ctype_digit((string)$accountId)
        || !ctype_digit((string)$categoryId)
        || empty($date)
        || !in_array($status, ['pending', 'completed', 'cancelled'], true)
        || !in_array($urgency, ['baixa', 'media', 'alta'], true)
    ) {
        $erro = 'Por favor, preencha todos os campos obrigatórios com valores válidos.';
    } elseif (!Security::validateAccountOwnership($pdo, (int)$accountId, $userId)) {
        $erro = 'Conta selecionada inválida ou não autorizada.';
    } elseif (!Security::validateCategoryOwnership($pdo, (int)$categoryId, $userId, $type)) {
        $erro = 'Categoria selecionada inválida ou incompatível com o tipo.';
    } elseif ($creditCardId !== null && !Security::validateCreditCardOwnership($pdo, $creditCardId, $userId)) {
        $erro = 'Cartão de crédito selecionado inválido ou não autorizado.';
    } else {
        try {
            $upd = $pdo->prepare(
                "UPDATE transactions
                 SET account_id = ?, category_id = ?, credit_card_id = ?, investment_id = ?, type = ?, amount = ?,
                     description = ?, date = ?, due_date = ?, status = ?, urgency = ?
                 WHERE id = ? AND user_id = ?"
            );
            $upd->execute([
                $accountId, $categoryId, $creditCardId, $investmentId, $type, $amount,
                $description, $date, $dueDate, $status, $urgency,
                $id, $userId
            ]);

            $origem = $_POST['origem'] ?? $_GET['origem'] ?? '';
            if ($origem === 'calendario') {
                $refDate = $dueDate ?: $date;
                $m = date('n', strtotime($refDate));
                $y = date('Y', strtotime($refDate));
                header("Location: calendar?mes=$m&ano=$y&sucesso=" . urlencode("Lançamento atualizado com sucesso!"));
            } elseif ($origem === 'extrato') {
                header("Location: statement?sucesso=" . urlencode("Lançamento atualizado com sucesso!"));
            } else {
                header("Location: ./?sucesso=" . urlencode("Lançamento atualizado com sucesso!"));
            }
            exit;
        } catch (PDOException $e) {
            error_log("Erro ao atualizar lançamento: " . $e->getMessage());
            $erro = 'Erro ao salvar as alterações no banco de dados.';
        }
    }

    // Mantém os dados submetidos para o formulário
    $lancamento['type']        = $type;
    $lancamento['description'] = $description;
    $lancamento['amount']      = $amount;
    $lancamento['account_id']  = $accountId;
    $lancamento['category_id'] = $categoryId;
    $lancamento['date']        = $date;
    $lancamento['due_date']    = $dueDate;
    $lancamento['status']      = $status;
    $lancamento['urgency']     = $urgency;
}

// Carrega contas do usuário
$contasStmt = $pdo->prepare("SELECT id, name FROM accounts WHERE user_id = ?");
$contasStmt->execute([$userId]);
$contas = $contasStmt->fetchAll(PDO::FETCH_ASSOC);

// Carrega cartões de crédito do usuário
$cardStmt = $pdo->prepare("SELECT id, name FROM credit_cards WHERE user_id = ? AND active = 1 ORDER BY name ASC");
$cardStmt->execute([$userId]);
$cartoes = $cardStmt->fetchAll(PDO::FETCH_ASSOC);

// Carrega categorias do usuário
$catStmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? ORDER BY name ASC");
$catStmt->execute([$userId]);
$categorias = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Carrega investimentos do usuário
$invStmt = $pdo->prepare("SELECT id, name, type FROM investments WHERE user_id = ? ORDER BY name ASC");
$invStmt->execute([$userId]);
$investimentos = $invStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Editar Lançamento - Droma</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'extrato';
$tituloPagina = 'Editar Lançamento';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($erro)): ?>
        <div style="max-width: 480px; margin: 0 auto 16px auto; background: rgba(239, 68, 68, 0.10); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <form class="form-lancamento" action="edit_expense" method="POST">
        <?= Security::csrfField() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <?php if (!empty($_GET['origem']) || !empty($_POST['origem'])): ?>
            <input type="hidden" name="origem" value="<?= htmlspecialchars($_POST['origem'] ?? $_GET['origem'] ?? '') ?>">
        <?php endif; ?>

        <label>Tipo
            <select name="type" id="tipoSelect" required>
                <option value="expense" <?= $lancamento['type'] === 'expense' ? 'selected' : '' ?>>Despesa</option>
                <option value="income" <?= $lancamento['type'] === 'income' ? 'selected' : '' ?>>Receita</option>
            </select>
        </label>

        <label>Descrição (opcional)
            <input type="text" name="description" maxlength="255" value="<?= htmlspecialchars($lancamento['description'] ?? '') ?>" placeholder="Ex: Supermercado, Salário...">
        </label>

        <label>Valor
            <input type="text" id="amountDisplay" class="money-mask" data-target-name="amount" data-initial-value="<?= htmlspecialchars((string)($lancamento['amount'] ?? '0.00')) ?>" placeholder="R$ 0,00" autofocus>
            <input type="hidden" name="amount" id="amountValue" value="<?= htmlspecialchars((string)($lancamento['amount'] ?? '0.00')) ?>" required>
        </label>

        <label>Conta Bancária
            <select name="account_id" required>
                <?php foreach ($contas as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $lancamento['account_id'] == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <?php if (!empty($cartoes)): ?>
            <label id="campoCartao">Cartão de Crédito (Opcional)
                <select name="credit_card_id">
                    <option value="">Nenhum (Lançamento direto em conta)</option>
                    <?php foreach ($cartoes as $card): ?>
                        <option value="<?= $card['id'] ?>" <?= ($lancamento['credit_card_id'] == $card['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($card['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <?php if (!empty($investimentos)): ?>
            <label>Vincular a Investimento (Opcional)
                <select name="investment_id">
                    <option value="">Nenhum (Lançamento comum)</option>
                    <?php foreach ($investimentos as $inv): ?>
                        <option value="<?= $inv['id'] ?>" <?= ($lancamento['investment_id'] ?? null) == $inv['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($inv['name']) ?> (<?= htmlspecialchars($inv['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label>Categoria
            <select name="category_id" id="categoriaSelect" required>
                <?php foreach ($categorias as $c): ?>
                    <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>" <?= $lancamento['category_id'] == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?> (<?= $c['type'] === 'income' ? 'receita' : 'despesa' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Data do lançamento
            <input type="date" name="date" value="<?= htmlspecialchars($lancamento['date'] ?? date('Y-m-d')) ?>" required>
        </label>

        <label>Data de vencimento / pagamento
            <input type="date" name="due_date" value="<?= htmlspecialchars($lancamento['due_date'] ?? '') ?>">
        </label>

        <label>Status
            <select name="status" required>
                <option value="completed" <?= $lancamento['status'] === 'completed' ? 'selected' : '' ?>>Pago / Recebido</option>
                <option value="pending" <?= $lancamento['status'] === 'pending' ? 'selected' : '' ?>>Pendente (Não pago)</option>
                <option value="cancelled" <?= $lancamento['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelado</option>
            </select>
        </label>

        <label id="urgenciaCampo">Urgência
            <select name="urgency" required>
                <option value="baixa" <?= $lancamento['urgency'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                <option value="media" <?= $lancamento['urgency'] === 'media' ? 'selected' : '' ?>>Média</option>
                <option value="alta" <?= $lancamento['urgency'] === 'alta' ? 'selected' : '' ?>>Alta</option>
            </select>
        </label>

        <div style="display:flex; gap:12px; margin-top:8px;">
            <button type="submit" style="flex:1;">Salvar Alterações</button>
            <a href="./" class="sair" style="text-align:center; display:flex; align-items:center; justify-content:center; text-decoration:none;">Cancelar</a>
        </div>
    </form>
</main>

<script>
const tipoSelect = document.getElementById('tipoSelect');
const catSelect = document.getElementById('categoriaSelect');

function filtrarCategorias() {
    const tipo = tipoSelect.value;
    let primeiroVisivel = null;
    let selecionadoAindaVisivel = false;

    Array.from(catSelect.options).forEach(opt => {
        const optType = opt.getAttribute('data-type');
        if (optType === tipo) {
            opt.style.display = '';
            opt.disabled = false;
            if (!primeiroVisivel) primeiroVisivel = opt;
            if (opt.selected) selecionadoAindaVisivel = true;
        } else {
            opt.style.display = 'none';
            opt.disabled = true;
        }
    });

    if (!selecionadoAindaVisivel && primeiroVisivel) {
        catSelect.value = primeiroVisivel.value;
    }
}

function atualizarUrgencia() {
    const campoUrgencia = document.getElementById('urgenciaCampo');
    const selectUrgencia = campoUrgencia.querySelector('select');
    const ehEntrada = tipoSelect.value === 'income';

    campoUrgencia.hidden = ehEntrada;
    selectUrgencia.disabled = ehEntrada;
}

tipoSelect.addEventListener('change', () => {
    filtrarCategorias();
    atualizarUrgencia();
});
filtrarCategorias();
atualizarUrgencia();
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


