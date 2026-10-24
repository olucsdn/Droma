<?php
/**
 * Módulo de Importação de Extratos Bancários (CSV e OFX) — Droma
 */
declare(strict_types=1);

require_once "config/database.php";
require_once "src/Security.php";
require_once "src/ExtratoParser.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = '';
$linhasExtrato = [];
$contaSelecionada = 0;

// Carrega contas do usuário
$stmtContas = $pdo->prepare("SELECT id, name FROM accounts WHERE user_id = ? ORDER BY name ASC");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

// Carrega categorias do usuário
$stmtCats = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? ORDER BY name ASC");
$stmtCats->execute([$userId]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// -------------------------------------------------------------------------
// PASSO 2: Confirmação e Gravação em Lote
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_importacao') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada. Tente novamente.';
    } else {
        $contaId = !empty($_POST['account_id']) && is_numeric($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
        $selecionados = $_POST['importar'] ?? [];
        $dadosLinhas = $_POST['linhas'] ?? [];

        if (!Security::validateAccountOwnership($pdo, $contaId, $userId)) {
            $erro = 'A conta bancária selecionada não pertence ao usuário.';
        } elseif (empty($selecionados) || !is_array($selecionados)) {
            $erro = 'Nenhum lançamento foi selecionado para importação.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtInsert = $pdo->prepare("
                    INSERT INTO transactions 
                        (user_id, account_id, category_id, type, amount, description, date, status, urgency)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'media')
                ");

                $importadosTotal = 0;
                foreach ($selecionados as $idx => $val) {
                    if (!isset($dadosLinhas[$idx])) continue;
                    $item = $dadosLinhas[$idx];

                    $desc   = trim($item['description'] ?? 'Transação importada');
                    $tipo   = in_array($item['type'] ?? '', ['income', 'expense'], true) ? $item['type'] : 'expense';
                    $valor  = abs((float)($item['amount'] ?? 0));
                    $data   = trim($item['date'] ?? date('Y-m-d'));
                    $catId  = !empty($item['category_id']) && is_numeric($item['category_id']) ? (int)$item['category_id'] : null;

                    if ($valor <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                        continue;
                    }

                    if ($catId !== null && !Security::validateCategoryOwnership($pdo, $catId, $userId, $tipo)) {
                        $catId = null;
                    }

                    if (!$catId) {
                        $s = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = ? LIMIT 1");
                        $s->execute([$userId, $tipo]);
                        $catId = (int)$s->fetchColumn();
                        if (!$catId) {
                            $insCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Geral', ?)");
                            $insCat->execute([$userId, $tipo]);
                            $catId = (int)$pdo->lastInsertId();
                        }
                    }

                    $stmtInsert->execute([
                        $userId, $contaId, $catId, $tipo, $valor, $desc, $data
                    ]);
                    $importadosTotal++;
                }

                $pdo->commit();
                $sucesso = "Sucesso! {$importadosTotal} movimentações foram importadas com êxito.";
                header("Location: extrato.php?sucesso=" . urlencode($sucesso));
                exit;

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Erro na importação de extrato: " . $e->getMessage());
                $erro = "Erro ao gravar movimentações importadas no banco de dados.";
            }
        }
    }
}

// -------------------------------------------------------------------------
// PASSO 1: Upload e Parsing do Arquivo (CSV ou OFX)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'processar_arquivo') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada.';
    } else {
        $contaSelecionada = !empty($_POST['account_id']) && is_numeric($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
        if (!Security::validateAccountOwnership($pdo, $contaSelecionada, $userId)) {
            $erro = 'Selecione uma conta válida de sua propriedade.';
        } elseif (empty($_FILES['arquivo_extrato']['tmp_name']) || !is_uploaded_file($_FILES['arquivo_extrato']['tmp_name'])) {
            $erro = 'Por favor, selecione um arquivo de extrato para envio.';
        } elseif (($_FILES['arquivo_extrato']['size'] ?? 0) > 5 * 1024 * 1024) {
            $erro = 'O arquivo enviado excede o limite máximo permitido de 5MB.';
        } else {
            $origName = mb_strtolower($_FILES['arquivo_extrato']['name'] ?? '');
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            if (!in_array($ext, ['csv', 'ofx', 'txt'], true)) {
                $erro = 'Formato de arquivo inválido. Por favor, envie apenas arquivos .csv ou .ofx.';
            } else {
                $tmpPath = $_FILES['arquivo_extrato']['tmp_name'];
                $conteudo = file_get_contents($tmpPath);

                // Parser OFX ou CSV via ExtratoParser
                if (str_ends_with($origName, '.ofx') || str_contains($conteudo, '<OFX>')) {
                    $linhasExtrato = ExtratoParser::parseOfx($conteudo, $categorias);
                } else {
                    $linhasExtrato = ExtratoParser::parseCsv($conteudo, $categorias);
                }

                if (empty($linhasExtrato)) {
                    $erro = "Não foi possível identificar lançamentos no arquivo enviado. Verifique se o formato é CSV ou OFX.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - Importação de Extratos</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
    <style>
        .tabela-importacao {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 16px;
        }
        .tabela-importacao th {
            padding: 10px 12px;
            color: var(--color-text-secondary);
            border-bottom: 1px solid var(--color-border);
            text-align: left;
        }
        .tabela-importacao td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--color-border);
            color: var(--color-text);
        }
        .tabela-importacao tr:hover td {
            background: var(--color-surface-secondary);
        }
        .tabela-importacao input[type="text"],
        .tabela-importacao input[type="date"],
        .tabela-importacao input[type="number"],
        .tabela-importacao select {
            background: var(--color-surface-secondary);
            border: 1px solid var(--color-border);
            color: var(--color-text);
            padding: 6px 8px;
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'extrato';
$tituloPagina = 'Importação de Extratos';
require_once "includes/layout_header.php";
?>

<main class="painel" style="max-width: 1180px; margin: 0 auto; padding: 24px 20px;">

    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 26px; font-weight: 800; color: var(--color-text); margin-bottom: 4px;">
            Importação Inteligente de Extratos
        </h1>
        <p style="color: var(--color-text-secondary); font-size: 14px;">
            Suba seu extrato bancário em formato <strong>OFX</strong> ou <strong>CSV</strong> (Nubank, Itaú, Bradesco, Inter, Santander, etc.) para importar movimentações em lote.
        </p>
    </div>

    <?php if (!empty($erro)): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 14px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($linhasExtrato)): ?>
        <!-- PASSO 1: Formulário de Envio do Arquivo -->
        <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 28px; max-width: 640px; margin: 0 auto; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <h2 style="font-size: 18px; color: var(--color-gold-light); margin-bottom: 16px;">
                1. Selecione a Conta e o Arquivo
            </h2>

            <form method="POST" action="importar.php" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="processar_arquivo">

                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label for="account_id" style="font-size:13px; font-weight:600; color:var(--color-text-secondary);">Conta de Destino:</label>
                    <select name="account_id" id="account_id" required style="background:var(--color-surface-secondary); color:var(--color-text); padding:10px 14px; border:1px solid var(--color-border); border-radius:var(--radius-md); font-size:14px;">
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label for="arquivo_extrato" style="font-size:13px; font-weight:600; color:var(--color-text-secondary);">Arquivo do Extrato (.OFX ou .CSV):</label>
                    <input type="file" name="arquivo_extrato" id="arquivo_extrato" accept=".ofx,.csv,text/csv" required style="background:var(--color-surface-secondary); color:var(--color-text); padding:12px; border:1px dashed var(--color-gold); border-radius:var(--radius-md); font-size:13px; cursor:pointer;">
                </div>

                <div style="display:flex; gap:12px; margin-top:12px;">
                    <button type="submit" class="btn-header-primary" style="flex:1; justify-content:center; height:42px;">
                        📄 Analisar Extrato
                    </button>
                    <a href="extrato.php" class="sair" style="padding:10px 18px; text-decoration:none;">Cancelar</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- PASSO 2: Conferência e Seleção dos Lançamentos -->
        <div style="background: var(--color-surface); border: 1px solid var(--color-border); border-radius: var(--radius-lg); padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                <div>
                    <h2 style="font-size: 18px; color: var(--color-gold-light); margin:0;">
                        2. Conferência de Lançamentos Encontrados
                    </h2>
                    <span style="font-size:13px; color:var(--color-text-secondary);">
                        Total identificado: <?= count($linhasExtrato) ?> movimentações. Desmarque ou altere o que desejar antes de gravar.
                    </span>
                </div>

                <div style="display:flex; gap:8px;">
                    <button type="button" class="sair" style="font-size:12px; padding:6px 12px; cursor:pointer;" onclick="document.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=true);">Marcar Todos</button>
                    <button type="button" class="sair" style="font-size:12px; padding:6px 12px; cursor:pointer;" onclick="document.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=false);">Desmarcar Todos</button>
                </div>
            </div>

            <form method="POST" action="importar.php">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="salvar_importacao">
                <input type="hidden" name="account_id" value="<?= $contaSelecionada ?>">

                <div style="overflow-x:auto;">
                    <table class="tabela-importacao">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;">Importar</th>
                                <th style="width:130px;">Data</th>
                                <th>Descrição</th>
                                <th style="width:110px;">Tipo</th>
                                <th style="width:140px;">Valor (R$)</th>
                                <th style="width:180px;">Categoria Sugerida</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linhasExtrato as $idx => $lin): ?>
                                <tr>
                                    <td style="text-align:center;">
                                        <input type="checkbox" name="importar[<?= $idx ?>]" value="1" checked>
                                    </td>
                                    <td>
                                        <input type="date" name="linhas[<?= $idx ?>][date]" value="<?= htmlspecialchars($lin['date']) ?>" required style="width:125px;">
                                    </td>
                                    <td>
                                        <input type="text" name="linhas[<?= $idx ?>][description]" value="<?= htmlspecialchars($lin['description']) ?>" required style="width:100%;">
                                    </td>
                                    <td>
                                        <select name="linhas[<?= $idx ?>][type]" style="width:100%;">
                                            <option value="expense" <?= $lin['type'] === 'expense' ? 'selected' : '' ?>>Despesa</option>
                                            <option value="income" <?= $lin['type'] === 'income' ? 'selected' : '' ?>>Receita</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" min="0.01" name="linhas[<?= $idx ?>][amount]" value="<?= number_format($lin['amount'], 2, '.', '') ?>" required style="width:120px; font-weight:700; color: <?= $lin['type'] === 'income' ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                                    </td>
                                    <td>
                                        <select name="linhas[<?= $idx ?>][category_id]" style="width:100%;">
                                            <option value="">Sem categoria</option>
                                            <?php foreach ($categorias as $c): ?>
                                                <option value="<?= $c['id'] ?>" <?= ($lin['category_id'] == $c['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['name']) ?> (<?= $c['type'] === 'income' ? 'rec' : 'desp' ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; padding-top:16px; border-top:1px solid var(--color-border);">
                    <a href="importar.php" class="sair" style="text-decoration:none; padding:10px 18px;">Voltar / Cancelar</a>
                    <button type="submit" class="btn-header-primary" style="height:42px; padding:0 24px; font-size:14px;">
                        ✓ Confirmar e Importar Lançamentos
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</main>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
