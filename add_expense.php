<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

// Carrega contas do usuário
$contasStmt = $pdo->prepare("SELECT id, name FROM accounts WHERE user_id = ?");
$contasStmt->execute([$userId]);
$contas = $contasStmt->fetchAll(PDO::FETCH_ASSOC);

// Garante ao menos uma conta se o usuário não possuir
if (empty($contas)) {
    $ins = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Carteira Principal', 'checking', 0.00)");
    $ins->execute([$userId]);
    $contas = [['id' => $pdo->lastInsertId(), 'name' => 'Carteira Principal']];
}

// Carrega categorias do usuário
$catStmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? ORDER BY name ASC");
$catStmt->execute([$userId]);
$categorias = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Carrega cartões de crédito ativos do usuário
$cardStmt = $pdo->prepare("SELECT id, name, closing_day, due_day FROM credit_cards WHERE user_id = ? AND active = 1 ORDER BY name ASC");
$cardStmt->execute([$userId]);
$cartoes = $cardStmt->fetchAll(PDO::FETCH_ASSOC);

// Carrega investimentos ativos do usuário
$invStmt = $pdo->prepare("SELECT id, name, type FROM investments WHERE user_id = ? AND status = 'active' ORDER BY name ASC");
$invStmt->execute([$userId]);
$investimentos = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Garante categorias padrão caso não existam
if (empty($categorias)) {
    $defaults = [
        ['Alimentação', 'expense'],
        ['Moradia', 'expense'],
        ['Transporte', 'expense'],
        ['Lazer', 'expense'],
        ['Saúde', 'expense'],
        ['Salário', 'income'],
        ['Investimentos', 'income'],
        ['Outros', 'expense']
    ];
    $insCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)");
    foreach ($defaults as [$cName, $cType]) {
        $insCat->execute([$userId, $cName, $cType]);
    }
    $catStmt->execute([$userId]);
    $categorias = $catStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Data pré-selecionada (caso venha do clique em um dia específico do calendário)
$dataDefault = (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Novo lançamento - Droma</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'adicionar';
$tituloPagina = 'Novo Lançamento';
require_once "includes/layout_header.php";
?>

<main class="painel">
<form class="form-lancamento" action="save_expense.php" method="POST">
    <?= Security::csrfField() ?>
    <?php if (!empty($_GET['origem'])): ?>
        <input type="hidden" name="origem" value="<?= htmlspecialchars($_GET['origem']) ?>">
    <?php endif; ?>

    <label>Tipo
        <select name="type" id="tipoSelect" required>
            <option value="income">Entrada</option>
            <option value="expense" selected>Saída</option>
        </select>
    </label>

    <label>Descrição (opcional)
        <input type="text" name="description" maxlength="255" placeholder="Ex: Supermercado, Salário...">
    </label>

    <label>Valor (R$)
        <input type="number" step="0.01" min="0.01" name="amount" placeholder="0,00" required autofocus>
    </label>

    <label>Forma de Pagamento
        <select name="meio_pagamento" id="meioPagamentoSelect">
            <option value="conta" selected>Conta Bancária / Dinheiro</option>
            <?php if (!empty($cartoes)): ?>
                <option value="cartao">Cartão de Crédito</option>
            <?php endif; ?>
        </select>
    </label>

    <label id="campoConta">Conta Bancária
        <select name="account_id" id="accountSelect">
            <?php foreach ($contas as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <?php if (!empty($cartoes)): ?>
        <label id="campoCartao" style="display:none;">Cartão de Crédito
            <select name="credit_card_id" id="cardSelect">
                <?php foreach ($cartoes as $card): ?>
                    <option value="<?= $card['id'] ?>">
                        <?= htmlspecialchars($card['name']) ?> (Fecha dia <?= $card['closing_day'] ?> / Vence dia <?= $card['due_day'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label id="campoParcelas" style="display:none;">Parcelamento
            <select name="installments" id="installmentsSelect">
                <option value="1" selected>1x (À vista no cartão)</option>
                <?php for ($i = 2; $i <= 24; $i++): ?>
                    <option value="<?= $i ?>"><?= $i ?>x parcelas</option>
                <?php endfor; ?>
            </select>
        </label>
    <?php endif; ?>

    <?php if (!empty($investimentos)): ?>
        <label>Vincular a Investimento (Opcional)
            <select name="investment_id">
                <option value="">Nenhum (Lançamento comum)</option>
                <?php foreach ($investimentos as $inv): ?>
                    <option value="<?= $inv['id'] ?>"><?= htmlspecialchars($inv['name']) ?> (<?= htmlspecialchars($inv['type']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <span style="font-size:11px; color:var(--color-text-secondary); display:block; margin-top:2px;">
                ✦ Relaciona este lançamento (Aporte ou Rendimento) ao cálculo de ROI do ativo.
            </span>
        </label>
    <?php endif; ?>

    <label>Categoria
        <select name="category_id" id="categoriaSelect" required>
            <?php foreach ($categorias as $c): ?>
                <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>">
                    <?= htmlspecialchars($c['name']) ?> (<?= $c['type'] === 'income' ? 'receita' : 'despesa' ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Data do lançamento
        <input type="date" name="date" value="<?= htmlspecialchars($dataDefault) ?>" required>
    </label>

    <label>Data de vencimento / pagamento
        <input type="date" name="due_date" value="<?= htmlspecialchars($dataDefault) ?>">
    </label>

    <label>Status
        <select name="status" required>
            <option value="completed" selected>Pago / Recebido</option>
            <option value="pending">Pendente (Não pago)</option>
        </select>
    </label>

    <label id="urgenciaCampo">Urgência
        <select name="urgency" required>
            <option value="baixa">Baixa</option>
            <option value="media" selected>Média</option>
            <option value="alta">Alta</option>
        </select>
    </label>

    <button type="submit">Salvar Lançamento</button>
</form>
</main>

<script>
// Filtra dinamicamente as categorias de acordo com o tipo selecionado (despesa ou receita)
const tipoSelect = document.getElementById('tipoSelect');
const catSelect = document.getElementById('categoriaSelect');

function filtrarCategorias() {
    const tipo = tipoSelect.value;
    let primeiroVisivel = null;

    Array.from(catSelect.options).forEach(opt => {
        const optType = opt.getAttribute('data-type');
        if (optType === tipo) {
            opt.style.display = '';
            opt.disabled = false;
            if (!primeiroVisivel) primeiroVisivel = opt;
        } else {
            opt.style.display = 'none';
            opt.disabled = true;
        }
    });

    if (primeiroVisivel && catSelect.selectedOptions[0]?.disabled) {
        catSelect.value = primeiroVisivel.value;
    }
}

function atualizarUrgencia() {
    const campoUrgencia = document.getElementById('urgenciaCampo');
    const selectUrgencia = campoUrgencia.querySelector('select');
    const ehEntrada = tipoSelect.value === 'income';

    campoUrgencia.hidden = ehEntrada;
    selectUrgencia.disabled = ehEntrada;
    selectUrgencia.required = !ehEntrada;
}

// Alterna entre Conta Bancária e Cartão de Crédito
const meioSelect = document.getElementById('meioPagamentoSelect');
const campoConta = document.getElementById('campoConta');
const campoCartao = document.getElementById('campoCartao');
const campoParcelas = document.getElementById('campoParcelas');

function atualizarMeioPagamento() {
    const ehEntrada = tipoSelect.value === 'income';
    
    // Se for receita, desabilita a opção de cartão
    if (meioSelect) {
        const optCartao = meioSelect.querySelector('option[value="cartao"]');
        if (optCartao) {
            optCartao.disabled = ehEntrada;
            if (ehEntrada && meioSelect.value === 'cartao') {
                meioSelect.value = 'conta';
            }
        }
    }

    const usaCartao = meioSelect && meioSelect.value === 'cartao' && !ehEntrada;

    if (campoConta) campoConta.style.display = usaCartao ? 'none' : 'block';
    if (campoCartao) campoCartao.style.display = usaCartao ? 'block' : 'none';
    if (campoParcelas) campoParcelas.style.display = usaCartao ? 'block' : 'none';
}

if (meioSelect) {
    meioSelect.addEventListener('change', atualizarMeioPagamento);
}

tipoSelect.addEventListener('change', () => {
    filtrarCategorias();
    atualizarUrgencia();
    atualizarMeioPagamento();
});
filtrarCategorias();
atualizarUrgencia();
atualizarMeioPagamento();
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>

