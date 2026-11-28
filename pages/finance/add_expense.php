<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);

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

// Carrega categorias ativas do usuário
$catStmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? AND visivel = 1 ORDER BY name ASC");
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
$erro = trim((string)($_GET['erro'] ?? ''));

// Histórico recente para sugestão automática de categoria pela descrição
$stmtHistCat = $pdo->prepare("
    SELECT LOWER(TRIM(description)) AS desc_norm, category_id
    FROM transactions
    WHERE user_id = ? AND visivel = 1 AND description IS NOT NULL AND description <> '' AND category_id IS NOT NULL
    ORDER BY id DESC
    LIMIT 150
");
$stmtHistCat->execute([$userId]);
$historicoCategorias = [];
foreach ($stmtHistCat->fetchAll(PDO::FETCH_ASSOC) as $hRow) {
    if (!isset($historicoCategorias[$hRow['desc_norm']])) {
        $historicoCategorias[$hRow['desc_norm']] = (int)$hRow['category_id'];
    }
}

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

// Última receita e última despesa adicionadas para sugestão automática de nome e categoria
$ultimosPorTipo = [
    'income'  => ['description' => '', 'category_id' => null],
    'expense' => ['description' => '', 'category_id' => null],
];
$stmtUltimos = $pdo->prepare("
    SELECT t.type, t.description, t.category_id
    FROM transactions t
    WHERE t.user_id = ? AND t.visivel = 1
      AND t.transfer_id IS NULL
      AND (t.category_name IS NULL OR t.category_name != 'Transferência')
      AND t.type IN ('income', 'expense')
    ORDER BY t.id DESC
    LIMIT 30
");
$stmtUltimos->execute([$userId]);
foreach ($stmtUltimos->fetchAll(PDO::FETCH_ASSOC) as $uRow) {
    $uTipo = $uRow['type'];
    if ($ultimosPorTipo[$uTipo]['category_id'] === null && !empty($uRow['category_id'])) {
        $ultimosPorTipo[$uTipo]['category_id'] = (int)$uRow['category_id'];
    }
    if ($ultimosPorTipo[$uTipo]['description'] === '' && trim((string)$uRow['description']) !== '') {
        $ultimosPorTipo[$uTipo]['description'] = trim((string)$uRow['description']);
    }
    if ($ultimosPorTipo['income']['category_id'] !== null && $ultimosPorTipo['income']['description'] !== ''
        && $ultimosPorTipo['expense']['category_id'] !== null && $ultimosPorTipo['expense']['description'] !== '') {
        break;
    }
}
if ($ultimosPorTipo[$tipoDefault]['category_id'] !== null) {
    foreach ($categorias as $c) {
        if ((int)$c['id'] === $ultimosPorTipo[$tipoDefault]['category_id'] && $c['type'] === $tipoDefault) {
            $primeiraCatTipo = $c['id'];
            break;
        }
    }
}
$placeholderInicial = $ultimosPorTipo[$tipoDefault]['description'] !== ''
    ? 'Ex: ' . $ultimosPorTipo[$tipoDefault]['description']
    : ($tipoDefault === 'income' ? 'Ex: Salário, Freelance...' : 'Ex: Supermercado, Aluguel...');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title><?= $tipoDefault === 'income' ? 'Nova Receita' : ($tipoParam ? 'Nova Despesa' : 'Novo Lançamento') ?> - Droma</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
    <!-- CSS Crítico Embarcado para Garantia de Renderização 100% Estilizada Mesmo Offline -->
    <style>
        .form-lancamento {
            max-width: 480px;
            margin: 0 auto;
            background: var(--color-surface, #0F1722);
            border: 1px solid var(--color-border, #1F2937);
            border-radius: var(--radius-lg, 18px);
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: var(--shadow-card, 0 10px 30px rgba(0, 0, 0, 0.35));
        }
        .form-lancamento label {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: var(--color-text-secondary, #94A3B8);
        }
        .form-lancamento label[hidden] {
            display: none !important;
        }
        .form-lancamento input,
        .form-lancamento select,
        .form-lancamento textarea {
            padding: 0 14px;
            border: 1px solid var(--color-border, #1F2937);
            border-radius: var(--radius-md, 10px);
            background: var(--color-bg, #070A0E);
            color: var(--color-text, #F1F5F9);
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
            width: 100%;
            height: 46px;
        }
        .form-lancamento button[type="submit"] {
            height: 46px;
            border: 1px solid rgba(212, 175, 108, 0.4);
            border-radius: var(--radius-md, 10px);
            background: var(--gold-gradient, linear-gradient(135deg, #D4AF6C, #8B7345));
            color: #070A0E;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            box-shadow: var(--shadow-gold-subtle, 0 4px 14px rgba(212, 175, 108, 0.2));
            transition: all 0.2s ease;
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'adicionar';
$tituloPagina = ($tipoDefault === 'income') ? 'Nova Receita' : ($tipoParam ? 'Nova Despesa' : 'Novo Lançamento');
require_once "includes/layout_header.php";
?>

<main class="painel">
<?php if (!empty($erro)): ?>
    <div style="max-width: 480px; margin: 0 auto 16px auto; padding: 12px 16px; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); border-radius: 10px; color: #FCA5A5; font-size: 14px; display: flex; align-items: center; gap: 10px;">
        <span style="font-size: 18px;">⚠️</span>
        <div><?= htmlspecialchars($erro) ?></div>
    </div>
<?php endif; ?>
<form class="form-lancamento" action="save_expense" method="POST">
    <?= Security::csrfField() ?>
    <?php if (!empty($_GET['origem'])): ?>
        <input type="hidden" name="origem" value="<?= htmlspecialchars($_GET['origem']) ?>">
    <?php endif; ?>

    <div class="droma-box-inset droma-mb-md" style="margin-top:0;">
        <label style="margin-bottom:0; font-size:12px;">⚡ Preenchimento rápido por texto
            <div class="droma-flex-row" style="margin-top:6px;">
                <input type="text" id="quickTextInput" placeholder="Ex: Gastei 45,90 no mercado ontem" style="flex:1;">
                <button type="button" id="btnQuickParse" class="btn-acao-lancamento">Preencher</button>
            </div>
        </label>
    </div>

    <div style="margin-bottom: 14px;">
        <span style="display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:var(--color-text-secondary);">Tipo</span>
        <input type="hidden" name="type" id="tipoSelect" value="<?= htmlspecialchars($tipoDefault) ?>">
        <div class="droma-segmented" role="group" aria-label="Tipo de lançamento">
            <button type="button" class="droma-segmented-btn <?= $tipoDefault === 'expense' ? 'is-active' : '' ?>" data-type="expense">↗ Saída (Despesa)</button>
            <button type="button" class="droma-segmented-btn <?= $tipoDefault === 'income' ? 'is-active' : '' ?>" data-type="income">↙ Entrada (Receita)</button>
        </div>
    </div>

    <label>Descrição (opcional)
        <input type="text" name="description" id="descInput" list="sugestaoDescList" maxlength="255" placeholder="<?= htmlspecialchars($placeholderInicial) ?>">
        <datalist id="sugestaoDescList"></datalist>
    </label>

    <label>Valor
        <input type="text" id="amountDisplay" class="money-mask" data-target-name="amount" inputmode="decimal" placeholder="R$ 0,00" autofocus>
        <input type="hidden" name="amount" id="amountValue" value="0.00" required>
    </label>

    <label>Categoria
        <select name="category_id" id="categoriaSelect" required>
            <?php foreach ($categorias as $c): ?>
                <option value="<?= $c['id'] ?>" data-type="<?= $c['type'] ?>" <?= ($primeiraCatTipo === $c['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?> (<?= $c['type'] === 'income' ? 'receita' : 'despesa' ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <details id="maisOpcoesBox" style="margin: 8px 0 14px; padding: 10px 14px; border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface-secondary);">
        <summary style="cursor: pointer; font-size: 13px; font-weight: 600; color: var(--color-gold); user-select: none;">
            ⚙ Mais opções (conta, cartão, data e status)
        </summary>
        <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 10px;">
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

    <label>Data do lançamento
        <input type="date" name="date" value="<?= htmlspecialchars($dataDefault) ?>" required>
    </label>

    <label>Data de vencimento / pagamento
        <input type="date" name="due_date" value="<?= htmlspecialchars($dataDefault) ?>">
    </label>

    <label>Tags (opcional, separadas por vírgula)
        <input type="text" name="tags" placeholder="Ex: viagem, reembolsavel, carro" maxlength="120">
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
        </div>
    </details>

    <button type="submit" id="btnSalvar"><?= $tipoDefault === 'income' ? 'Salvar Receita' : 'Salvar Despesa' ?></button>
</form>
</main>

<script>
// Filtra dinamicamente as categorias de acordo com o tipo selecionado (despesa ou receita)
const tipoSelect = document.getElementById('tipoSelect');
const catSelect = document.getElementById('categoriaSelect');
const btnSalvar = document.getElementById('btnSalvar');
const descInputSugestao = document.getElementById('descInput');
const sugestaoDescList = document.getElementById('sugestaoDescList');
const ultimosPorTipo = <?= json_encode($ultimosPorTipo, JSON_UNESCAPED_UNICODE) ?>;

// Sincroniza com parâmetros de URL também no front-end (garante estado mesmo com back/forward cache)
const urlParams = new URLSearchParams(window.location.search);
const paramTipo = (urlParams.get('tipo') || urlParams.get('type') || '').toLowerCase();
if (['income', 'receita', 'entrada'].includes(paramTipo)) {
    tipoSelect.value = 'income';
} else if (['expense', 'despesa', 'saida', 'saída'].includes(paramTipo)) {
    tipoSelect.value = 'expense';
}

const segBtns = Array.from(document.querySelectorAll('.droma-segmented-btn'));
function syncSegmented() {
    segBtns.forEach(b => b.classList.toggle('is-active', b.dataset.type === tipoSelect.value));
}
syncSegmented();
segBtns.forEach(b => {
    b.addEventListener('click', () => {
        if (tipoSelect.value === b.dataset.type) return;
        tipoSelect.value = b.dataset.type;
        syncSegmented();
        tipoSelect.dispatchEvent(new Event('change'));
    });
});

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

    const ultimaCatId = ultimosPorTipo[tipo]?.category_id ? String(ultimosPorTipo[tipo].category_id) : null;
    const optUltima = ultimaCatId
        ? Array.from(catSelect.options).find(o => o.value === ultimaCatId && !o.disabled && o.getAttribute('data-type') === tipo)
        : null;

    if (optUltima) {
        catSelect.value = optUltima.value;
    } else if (primeiroVisivel && (catSelect.selectedOptions.length === 0 || catSelect.selectedOptions[0]?.disabled || catSelect.selectedOptions[0]?.getAttribute('data-type') !== tipo)) {
        catSelect.value = primeiroVisivel.value;
    }

    const ultimaDesc = (ultimosPorTipo[tipo]?.description || '').trim();
    if (descInputSugestao) {
        descInputSugestao.placeholder = ultimaDesc !== ''
            ? 'Ex: ' + ultimaDesc
            : (tipo === 'income' ? 'Ex: Salário, Freelance...' : 'Ex: Supermercado, Aluguel...');
    }
    if (sugestaoDescList) {
        sugestaoDescList.innerHTML = '';
        if (ultimaDesc !== '') {
            const opt = document.createElement('option');
            opt.value = ultimaDesc;
            sugestaoDescList.appendChild(opt);
        }
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
    function salvarMetadadosOffline() {
        try {
            const contas = Array.from(document.querySelectorAll('#accountSelect option')).map(o => ({ id: o.value, name: o.textContent.trim() })).filter(c => c.id);
            const categorias = Array.from(document.querySelectorAll('#categoriaSelect option')).map(o => ({ id: o.value, name: o.textContent.trim(), type: o.getAttribute('data-type') })).filter(c => c.id);
            const cartoes = Array.from(document.querySelectorAll('#cardSelect option')).map(o => ({ id: o.value, name: o.textContent.trim() })).filter(c => c.id);
            if (window.DromaOffline?.cacheMetadata) {
                window.DromaOffline.cacheMetadata({ contas, categorias, cartoes });
            } else {
                localStorage.setItem('droma_cached_meta', JSON.stringify({ contas, categorias, cartoes, cached_at: Date.now() }));
            }
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', salvarMetadadosOffline);
    } else {
        salvarMetadadosOffline();
    }

    function limparFormularioAposOffline() {
        const amountDisplay = formLancamento.querySelector('#amountDisplay') || formLancamento.querySelector('.money-mask');
        if (amountDisplay && amountDisplay.resetMoneyMask) {
            amountDisplay.resetMoneyMask();
            amountDisplay.focus();
        }
        const descInput = formLancamento.querySelector('input[name="description"]');
        if (descInput) descInput.value = '';
        clientIdAtual = novoClientId();
    }

    const novoClientId = () => 'off_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
    let clientIdAtual = novoClientId();

    async function persistirLocalmente(tx) {
        if (window.DromaOffline?.savePendingTransaction) {
            return await window.DromaOffline.savePendingTransaction(tx);
        }
        const cur = JSON.parse(localStorage.getItem('droma_fallback_pending') || '[]');
        cur.push({ ...tx, created_at_local: new Date().toISOString() });
        localStorage.setItem('droma_fallback_pending', JSON.stringify(cur));
        return tx.client_id;
    }

    // Restaura última conta/meio de pagamento lembrados + sugestão de categoria pela descrição
    const histCategorias = <?= json_encode($historicoCategorias, JSON_UNESCAPED_UNICODE) ?>;
    const descInputEl = document.getElementById('descInput');
    const meioEl = document.getElementById('meioPagamentoSelect');
    const accEl = document.getElementById('accountSelect');
    const cardEl = document.getElementById('cardSelect');
    let catManual = false;

    catSelect?.addEventListener('change', () => { catManual = true; });

    try {
        const savedAcc = localStorage.getItem('droma_last_account');
        if (savedAcc && accEl && accEl.querySelector(`option[value="${savedAcc}"]`)) accEl.value = savedAcc;
        const savedCard = localStorage.getItem('droma_last_card');
        if (savedCard && cardEl && cardEl.querySelector(`option[value="${savedCard}"]`)) cardEl.value = savedCard;
        const savedMeio = localStorage.getItem('droma_last_meio');
        if (savedMeio && meioEl && meioEl.querySelector(`option[value="${savedMeio}"]:not(:disabled)`)) {
            meioEl.value = savedMeio;
            meioEl.dispatchEvent(new Event('change'));
        }
    } catch (e) {}

    descInputEl?.addEventListener('input', () => {
        if (catManual) return;
        const txt = descInputEl.value.trim().toLowerCase();
        if (txt.length < 2) return;
        if (histCategorias[txt]) {
            const opt = catSelect.querySelector(`option[value="${histCategorias[txt]}"]:not(:disabled)`);
            if (opt) { catSelect.value = String(histCategorias[txt]); return; }
        }
        const regras = [
            [/mercado|supermercado|padaria|restaurante|ifood|lanche|pizza|cafe/, 'alimenta'],
            [/uber|99|gasolina|combust|onibus|metro|estacionamento|pedagio/, 'transport'],
            [/aluguel|condominio|energia|luz|agua|internet|iptu/, 'morad'],
            [/farmacia|remedio|medico|consulta|plano de saude|exame/, 'sa'],
            [/cinema|netflix|spotify|jogo|viagem|show|lazer/, 'laz'],
            [/salario|pagamento|freela|bonus|pix recebido/, 'sal']
        ];
        for (const [rx, prefix] of regras) {
            if (rx.test(txt)) {
                const matchOpt = Array.from(catSelect.options).find(o => !o.disabled && o.textContent.toLowerCase().includes(prefix));
                if (matchOpt) { catSelect.value = matchOpt.value; break; }
            }
        }
    });

    formLancamento.addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(formLancamento);
        formData.append('client_id', clientIdAtual);
        const txData = {
            client_id: clientIdAtual,
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

        // Validação flexível e robusta de valor monetário
        let numVal = parseFloat(String(txData.amount).replace(',', '.'));
        if (isNaN(numVal) || numVal <= 0) {
            const displayVal = formLancamento.querySelector('#amountDisplay')?.value || '';
            const cleanDigits = displayVal.replace(/\D/g, '');
            if (cleanDigits && parseInt(cleanDigits, 10) > 0) {
                numVal = parseInt(cleanDigits, 10) / 100;
                txData.amount = numVal.toFixed(2);
            }
        }

        if (isNaN(numVal) || numVal <= 0) {
            if (window.DromaOffline?.showToast) {
                window.DromaOffline.showToast('Por favor, informe um valor maior que R$ 0,00.', 'warning');
            } else {
                alert('Por favor, informe um valor maior que R$ 0,00.');
            }
            return;
        }

        // Cenário 1: Se estiver offline comprovadamente
        if (!navigator.onLine) {
            if (btnSalvar) {
                btnSalvar.disabled = true;
                btnSalvar.style.opacity = '0.7';
            }
            try {
                await persistirLocalmente(txData);
                limparFormularioAposOffline();
            } finally {
                if (btnSalvar) {
                    btnSalvar.disabled = false;
                    btnSalvar.style.opacity = '';
                    atualizarBotao();
                }
            }
            return;
        }

        // Aviso de possível duplicado (< 2 min)
        try {
            const lastSub = JSON.parse(localStorage.getItem('droma_last_submitted_tx') || 'null');
            if (lastSub && (Date.now() - lastSub.ts) < 120000) {
                if (String(lastSub.amount) === String(txData.amount) && lastSub.desc === txData.description && lastSub.date === txData.date) {
                    if (!confirm('Parece repetido (lançado há menos de 2 minutos). Salvar mesmo assim?')) {
                        return;
                    }
                }
            }
            localStorage.setItem('droma_last_submitted_tx', JSON.stringify({ amount: txData.amount, desc: txData.description, date: txData.date, ts: Date.now() }));
            if (meioEl) localStorage.setItem('droma_last_meio', meioEl.value);
            if (accEl) localStorage.setItem('droma_last_account', accEl.value);
            if (cardEl) localStorage.setItem('droma_last_card', cardEl.value);
        } catch (e) {}

        // Cenário 2: Se estiver online, tenta enviar com fallback automático caso a rede caia
        let navegando = false;
        if (btnSalvar) {
            btnSalvar.disabled = true;
            btnSalvar.style.opacity = '0.7';
            btnSalvar.textContent = 'Enviando...';
        }

        const coldStartTimer = setTimeout(() => {
            if (!navegando && window.DromaOffline?.showToast) {
                window.DromaOffline.showToast('Conectando ao servidor, isso pode levar alguns segundos...', 'info');
            }
        }, 3000);

        try {
            const response = await fetch('save_expense', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            clearTimeout(coldStartTimer);

            if (response.ok || response.redirected) {
                let destUrl = response.redirected ? response.url : null;
                try {
                    const json = await response.clone().json();
                    if (json && json.redirect_url) {
                        destUrl = json.redirect_url;
                    }
                } catch (e) {}

                if (!destUrl) {
                    destUrl = (formData.get('origem') === 'calendario') ? 'calendar' : './';
                }
                navegando = true;
                window.location.href = destUrl;
                return;
            }

            if (response.status === 401) {
                await persistirLocalmente(txData);
                limparFormularioAposOffline();
                if (window.DromaOffline?.showToast) {
                    window.DromaOffline.showToast('Sessão expirada. Lançamento salvo localmente — faça login para sincronizar.', 'warning');
                } else {
                    alert('Sessão expirada. Lançamento salvo localmente — faça login para sincronizar.');
                }
                return;
            }

            // Se for erro do cliente (ex: 400 ou 422), alerta o usuário sem registrar no offline
            if (response.status >= 400 && response.status < 500) {
                const errText = await response.text();
                throw new Error(errText || `Erro na validação (${response.status})`);
            }

            throw new Error(`Falha no servidor (HTTP ${response.status})`);
        } catch (err) {
            // Em caso de falha de conexão (offline intermitente), salva no IndexedDB
            const isConnectionFailure = !navigator.onLine || err.name === 'TypeError';
            if (isConnectionFailure) {
                console.warn('[Droma] Falha na rede ao salvar, registrando no IndexedDB local:', err);
                await persistirLocalmente(txData);
                limparFormularioAposOffline();
            } else {
                console.error('[Droma] Erro na validação do lançamento:', err);
                if (window.DromaOffline?.showToast) {
                    window.DromaOffline.showToast('Erro ao salvar: ' + (err.message || 'Verifique os dados informados.'), 'warning');
                } else {
                    alert('Erro ao salvar: ' + (err.message || 'Verifique os dados informados.'));
                }
            }
        } finally {
            if (!navegando && btnSalvar) {
                btnSalvar.disabled = false;
                btnSalvar.style.opacity = '';
                atualizarBotao();
            }
        }
    });
}

(function () {
    const qInput = document.getElementById('quickTextInput');
    const qBtn = document.getElementById('btnQuickParse');
    if (!qInput || !qBtn) return;

    function aplicarTextoRapido() {
        const raw = qInput.value.trim();
        if (!raw) return;
        const lower = raw.toLowerCase();

        const isIncome = /\b(recebi|ganhei|sal[aá]rio|freela|reembolso|dividendo|rendimento|entrada)\b/.test(lower);
        const segBtn = document.querySelector(`.droma-segmented-btn[data-type="${isIncome ? 'income' : 'expense'}"]`);
        if (segBtn) segBtn.click();

        const mAmt = lower.match(/(?:r\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{1,2}|\d+(?:[.,]\d{1,2})?)/);
        if (mAmt) {
            let numStr = mAmt[1];
            if (numStr.includes(',') && numStr.includes('.')) {
                numStr = numStr.replace(/\./g, '').replace(',', '.');
            } else if (numStr.includes(',')) {
                numStr = numStr.replace(',', '.');
            }
            const val = parseFloat(numStr);
            if (!isNaN(val) && val > 0) {
                const amtDisp = document.getElementById('amountDisplay');
                const amtVal = document.getElementById('amountValue');
                if (amtVal) amtVal.value = val.toFixed(2);
                if (amtDisp) {
                    amtDisp.value = val.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                    amtDisp.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        }

        const dateInp = document.querySelector('input[name="date"]');
        if (dateInp) {
            const d = new Date();
            if (lower.includes('ontem')) {
                d.setDate(d.getDate() - 1);
                dateInp.value = d.toISOString().slice(0, 10);
            } else if (lower.includes('anteontem')) {
                d.setDate(d.getDate() - 2);
                dateInp.value = d.toISOString().slice(0, 10);
            }
        }

        const accSel = document.getElementById('accountSelect');
        if (accSel) {
            for (const opt of accSel.options) {
                const name = opt.text.trim().toLowerCase();
                if (name && lower.includes(name)) {
                    accSel.value = opt.value;
                    break;
                }
            }
        }

        let desc = raw
            .replace(/\b(gastei|paguei|comprei|recebi|ganhei|ontem|anteontem|hoje|no|na|em|de|r\$)\b/gi, ' ')
            .replace(/\d{1,3}(?:\.\d{3})*,\d{1,2}|\d+(?:[.,]\d{1,2})?/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        const descInp = document.getElementById('descInput');
        if (descInp) {
            descInp.value = desc || raw.slice(0, 80);
            descInp.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    qBtn.addEventListener('click', aplicarTextoRapido);
    qInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            aplicarTextoRapido();
        }
    });
})();

</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
