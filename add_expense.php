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

// Tipo pré-selecionado (suporte a ?tipo=income/expense, ?tipo=receita/despesa ou ?type=...)
$tipoParam = strtolower(trim($_GET['tipo'] ?? $_GET['type'] ?? ''));
if (in_array($tipoParam, ['income', 'receita', 'entrada'], true)) {
    $tipoDefault = 'income';
} else {
    $tipoDefault = 'expense';
}

// Categoria inicial compatível com o tipo selecionado
$primeiraCatTipo = null;
foreach ($categorias as $c) {
    if ($c['type'] === $tipoDefault) {
        $primeiraCatTipo = $c['id'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title><?= $tipoDefault === 'income' ? 'Nova Receita' : ($tipoParam ? 'Nova Despesa' : 'Novo Lançamento') ?> - Droma</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'adicionar';
$tituloPagina = ($tipoDefault === 'income') ? 'Nova Receita' : ($tipoParam ? 'Nova Despesa' : 'Novo Lançamento');
require_once "includes/layout_header.php";
?>

<main class="painel">
<form class="form-lancamento" action="save_expense" method="POST">
    <?= Security::csrfField() ?>
    <?php if (!empty($_GET['origem'])): ?>
        <input type="hidden" name="origem" value="<?= htmlspecialchars($_GET['origem']) ?>">
    <?php endif; ?>

    <label>Tipo
        <select name="type" id="tipoSelect" required>
            <option value="income" <?= $tipoDefault === 'income' ? 'selected' : '' ?>>Entrada (Receita)</option>
            <option value="expense" <?= $tipoDefault === 'expense' ? 'selected' : '' ?>>Saída (Despesa)</option>
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
                <option value="cartao" <?= $tipoDefault === 'income' ? 'disabled' : '' ?>>Cartão de Crédito</option>
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
                <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>" <?= ($primeiraCatTipo === $c['id']) ? 'selected' : '' ?>>
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

    <label id="urgenciaCampo" <?= $tipoDefault === 'income' ? 'hidden' : '' ?>>Urgência
        <select name="urgency" required <?= $tipoDefault === 'income' ? 'disabled' : '' ?>>
            <option value="baixa">Baixa</option>
            <option value="media" selected>Média</option>
            <option value="alta">Alta</option>
        </select>
    </label>

    <button type="submit" id="btnSalvar"><?= $tipoDefault === 'income' ? 'Salvar Receita' : 'Salvar Despesa' ?></button>
</form>
</main>

<script>
// Filtra dinamicamente as categorias de acordo com o tipo selecionado (despesa ou receita)
const tipoSelect = document.getElementById('tipoSelect');
const catSelect = document.getElementById('categoriaSelect');
const btnSalvar = document.getElementById('btnSalvar');

// Sincroniza com parâmetros de URL também no front-end (garante estado mesmo com back/forward cache)
const urlParams = new URLSearchParams(window.location.search);
const paramTipo = (urlParams.get('tipo') || urlParams.get('type') || '').toLowerCase();
if (['income', 'receita', 'entrada'].includes(paramTipo)) {
    tipoSelect.value = 'income';
} else if (['expense', 'despesa', 'saida', 'saída'].includes(paramTipo)) {
    tipoSelect.value = 'expense';
}

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

    if (primeiroVisivel && (catSelect.selectedOptions.length === 0 || catSelect.selectedOptions[0]?.disabled || catSelect.selectedOptions[0]?.getAttribute('data-type') !== tipo)) {
        catSelect.value = primeiroVisivel.value;
    }
}

function atualizarUrgencia() {
    const campoUrgencia = document.getElementById('urgenciaCampo');
    if (!campoUrgencia) return;
    const selectUrgencia = campoUrgencia.querySelector('select');
    const ehEntrada = tipoSelect.value === 'income';

    campoUrgencia.hidden = ehEntrada;
    if (selectUrgencia) {
        selectUrgencia.disabled = ehEntrada;
        selectUrgencia.required = !ehEntrada;
    }
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

function atualizarBotao() {
    if (btnSalvar) {
        btnSalvar.textContent = tipoSelect.value === 'income' ? 'Salvar Receita' : 'Salvar Despesa';
    }
}

if (meioSelect) {
    meioSelect.addEventListener('change', atualizarMeioPagamento);
}

tipoSelect.addEventListener('change', () => {
    filtrarCategorias();
    atualizarUrgencia();
    atualizarMeioPagamento();
    atualizarBotao();
});

filtrarCategorias();
atualizarUrgencia();
atualizarMeioPagamento();
atualizarBotao();

// ==========================================================================
// RESILIÊNCIA OFFLINE (IndexedDB + Sincronização em Segundo Plano)
// ==========================================================================
const formLancamento = document.querySelector('.form-lancamento');
if (formLancamento) {
    // 1. Salva metadados de contas e categorias em cache para uso offline
    try {
        const contas = Array.from(document.querySelectorAll('#accountSelect option')).map(o => ({ id: o.value, name: o.textContent.trim() }));
        const categorias = Array.from(document.querySelectorAll('#categoriaSelect option')).map(o => ({ id: o.value, name: o.textContent.trim(), type: o.getAttribute('data-type') }));
        const cartoes = Array.from(document.querySelectorAll('#cardSelect option')).map(o => ({ id: o.value, name: o.textContent.trim() }));
        window.DromaOffline?.cacheMetadata({ contas, categorias, cartoes });
    } catch (e) {}

    function limparFormularioAposOffline() {
        const amountInput = formLancamento.querySelector('input[name="amount"]');
        const descInput = formLancamento.querySelector('input[name="description"]');
        if (amountInput) {
            amountInput.value = '';
            amountInput.focus();
        }
        if (descInput) descInput.value = '';
    }

    formLancamento.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(formLancamento);
        const txData = {
            client_id: 'off_' + Date.now() + '_' + Math.random().toString(36).substr(2, 7),
            type: formData.get('type') || 'expense',
            description: (formData.get('description') || '').trim(),
            amount: (formData.get('amount') || '').trim(),
            meio_pagamento: formData.get('meio_pagamento') || 'conta',
            account_id: formData.get('account_id') || null,
            credit_card_id: formData.get('credit_card_id') || null,
            installments: formData.get('installments') || 1,
            investment_id: formData.get('investment_id') || null,
            category_id: formData.get('category_id') || null,
            date: formData.get('date') || new Date().toISOString().split('T')[0],
            due_date: formData.get('due_date') || null,
            status: formData.get('status') || 'completed',
            urgency: formData.get('urgency') || 'media'
        };

        // Validação mínima de valor
        const numVal = parseFloat(txData.amount.replace(',', '.'));
        if (isNaN(numVal) || numVal <= 0) {
            window.DromaOffline?.showToast('Por favor, informe um valor válido maior que zero.', 'warning');
            return;
        }

        // Cenário 1: Se estiver offline comprovadamente
        if (!navigator.onLine) {
            await window.DromaOffline?.savePendingTransaction(txData);
            limparFormularioAposOffline();
            return;
        }

        // Cenário 2: Se estiver online, tenta enviar com fallback automático caso a rede caia
        if (btnSalvar) {
            btnSalvar.disabled = true;
            btnSalvar.style.opacity = '0.7';
            btnSalvar.textContent = 'Enviando...';
        }

        try {
            const response = await fetch('save_expense', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.ok || response.redirected) {
                let destUrl = response.redirected ? response.url : null;
                try {
                    const json = await response.clone().json();
                    if (json && json.redirect_url) {
                        destUrl = json.redirect_url;
                    }
                } catch (e) {}

                if (!destUrl) {
                    destUrl = (formData.get('origem') === 'calendario') ? 'calendar?sucesso=Lançamento+salvo' : './?sucesso=Lançamento+salvo';
                }
                window.location.href = destUrl;
                return;
            }

            throw new Error(`Falha no servidor (HTTP ${response.status})`);
        } catch (err) {
            console.warn('[Droma] Falha na rede ao salvar, registrando no IndexedDB local:', err);
            await window.DromaOffline?.savePendingTransaction(txData);
            limparFormularioAposOffline();
        } finally {
            if (btnSalvar) {
                btnSalvar.disabled = false;
                btnSalvar.style.opacity = '';
                atualizarBotao();
            }
        }
    });
}

</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
