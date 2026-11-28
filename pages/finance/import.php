<?php
declare(strict_types=1);
/**
 * Módulo de Importação de Extratos Bancários (CSV e OFX) — Droma
 */

require_once "config/database.php";
require_once "src/Security.php";
require_once "src/CategoryService.php";
require_once "src/ExtratoParser.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);

$erro = '';
$sucesso = '';
$linhasExtrato = [];
$contaSelecionada = 0;

// Carrega contas do usuário
$stmtContas = $pdo->prepare("SELECT id, name FROM accounts WHERE user_id = ? ORDER BY name ASC");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

// Carrega categorias ativas do usuário
$stmtCats = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ? AND visivel = 1 ORDER BY name ASC");
$stmtCats->execute([$userId]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// Garante categorias padrão caso o usuário ainda não possua nenhuma cadastrada
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
    $insCat = $pdo->prepare("INSERT INTO categories (user_id, name, type, visivel) VALUES (?, ?, ?, 1)");
    foreach ($defaults as [$cName, $cType]) {
        $insCat->execute([$userId, $cName, $cType]);
    }
    $stmtCats->execute([$userId]);
    $categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);
}

// -------------------------------------------------------------------------
// PASSO 2: Confirmação e Gravação em Lote
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_importacao') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada. Tente novamente.';
    } elseif (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
        Security::flashRedirect('import', 'sucesso', 'Essa operação já tinha sido registrada.');
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

                // Cache de nomes de categorias para snapshot imutável em category_name
                $catNomes = [];
                foreach ($categorias as $cat) {
                    $catNomes[(int)$cat['id']] = $cat['name'];
                }

                $hasImportHash = Security::ensureImportHashColumn($pdo);
                $stmtInsert = $pdo->prepare(
                    $hasImportHash
                        ? "INSERT INTO transactions (user_id, account_id, category_id, category_name, type, amount, description, date, status, urgency, import_hash, visivel) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', 'media', ?, 1)"
                        : "INSERT INTO transactions (user_id, account_id, category_id, category_name, type, amount, description, date, status, urgency, visivel) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', 'media', 1)"
                );

                $importadosTotal = 0;
                $ignoradosTotal  = 0;
                foreach ($selecionados as $idx => $val) {
                    if (!isset($dadosLinhas[$idx])) continue;
                    $item = $dadosLinhas[$idx];

                    $desc   = Security::sanitizeText($item['description'] ?? '', 255, 'Transação importada');
                    $tipo   = in_array($item['type'] ?? '', ['income', 'expense'], true) ? $item['type'] : 'expense';
                    $valor  = abs((float)($item['amount'] ?? 0));
                    $data   = trim($item['date'] ?? date('Y-m-d'));
                    $catId  = !empty($item['category_id']) && is_numeric($item['category_id']) ? (int)$item['category_id'] : null;
                    $impHash = trim((string)($item['import_hash'] ?? ''));
                    if (!preg_match('/^[a-f0-9]{64}$/', $impHash)) {
                        $impHash = null;
                    }

                    if ($valor <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
                        continue;
                    }

                    if ($catId !== null && !Security::validateCategoryOwnership($pdo, $catId, $userId, $tipo)) {
                        $catId = null;
                    }

                    if (!$catId) {
                        $sysCat = CategoryService::ensureSystemCategory($pdo, $userId, $tipo);
                        $catId = $sysCat['id'];
                        $catNomes[$catId] = $sysCat['name'];
                    }

                    $catNomeSnapshot = ($catId !== null && isset($catNomes[$catId])) ? $catNomes[$catId] : (($tipo === 'income') ? CategoryService::SYSTEM_INCOME_CATEGORY : CategoryService::SYSTEM_EXPENSE_CATEGORY);

                    try {
                        $params = [$userId, $contaId, $catId, $catNomeSnapshot, $tipo, $valor, $desc, $data];
                        if ($hasImportHash) {
                            $params[] = $impHash;
                        }
                        $stmtInsert->execute($params);
                        $importadosTotal++;
                    } catch (PDOException $eDup) {
                        if ($eDup->getCode() === '23000') {
                            $ignoradosTotal++;
                        } else {
                            throw $eDup;
                        }
                    }
                }

                $pdo->commit();
                $sucesso = "Sucesso! {$importadosTotal} movimentações foram importadas com êxito."
                         . ($ignoradosTotal > 0 ? " ({$ignoradosTotal} duplicadas ignoradas.)" : "");
                Security::flashRedirect('statement', 'sucesso', $sucesso);

            } catch (Throwable $e) {
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
        } elseif (!isset($_FILES['arquivo_extrato']) || !is_array($_FILES['arquivo_extrato'])) {
            $erro = 'Por favor, selecione um arquivo de extrato para envio.';
        } elseif (($_FILES['arquivo_extrato']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadErros = [
                UPLOAD_ERR_INI_SIZE   => 'O arquivo excede o limite máximo de tamanho permitido pelo servidor.',
                UPLOAD_ERR_FORM_SIZE  => 'O arquivo excede o limite estipulado no formulário.',
                UPLOAD_ERR_PARTIAL    => 'O upload do arquivo foi realizado apenas parcialmente. Tente novamente.',
                UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo foi selecionado para envio.',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário indisponível no servidor.',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao salvar o arquivo temporário no disco.',
                UPLOAD_ERR_EXTENSION  => 'O upload foi interrompido por restrição do servidor.'
            ];
            $erro = $uploadErros[$_FILES['arquivo_extrato']['error']] ?? 'Falha no upload do arquivo. Tente novamente.';
        } elseif (empty($_FILES['arquivo_extrato']['tmp_name']) || !is_uploaded_file($_FILES['arquivo_extrato']['tmp_name'])) {
            $erro = 'Arquivo temporário inválido ou inacessível.';
        } elseif ((int)@filesize($_FILES['arquivo_extrato']['tmp_name']) > 5 * 1024 * 1024) {
            $erro = 'O arquivo enviado excede o limite máximo permitido de 5MB.';
        } else {
            $origName = mb_strtolower($_FILES['arquivo_extrato']['name'] ?? '');
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['csv', 'ofx'], true)) {
                $erro = 'Formato de arquivo inválido. Por favor, envie exclusivamente arquivos com extensão .csv ou .ofx.';
            } else {
                $tmpPath = $_FILES['arquivo_extrato']['tmp_name'];

                // Validação de segurança MIME real com Fileinfo
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }

                $mimesPermitidos = [
                    'text/plain', 'text/csv', 'text/x-csv', 'application/csv', 'application/x-csv',
                    'text/comma-separated-values', 'text/x-comma-separated-values',
                    'application/ofx', 'application/x-ofx', 'text/xml', 'application/xml',
                    'application/octet-stream' // Suporte a navegadores legados que enviam OFX como octet-stream
                ];

                // Verificação de assinatura binária (impede binários, imagens ou scripts executáveis)
                $amostraInicio = (string)file_get_contents($tmpPath, false, null, 0, 1024);
                $temBytesNulos = str_contains($amostraInicio, "\0");

                if ($temBytesNulos || (!empty($mimeType) && !in_array($mimeType, $mimesPermitidos, true))) {
                    $erro = 'O arquivo enviado não é um extrato de texto autêntico (CSV ou OFX). Arquivos executáveis, imagens ou binários são rejeitados.';
                } else {
                    try {
                        // Detecta se é OFX pelo conteúdo ou pela extensão
                        $amostraUpper = strtoupper($amostraInicio);
                        $isOfx = ($ext === 'ofx') || str_contains($amostraUpper, '<OFX>') || str_contains($amostraUpper, 'OFXHEADER') || str_contains($amostraUpper, '<STMTTRN>');

                        if ($isOfx) {
                            $conteudo = (string)file_get_contents($tmpPath);
                            $linhasExtrato = ExtratoParser::parseOfx($conteudo, $categorias);
                            unset($conteudo);
                        } else {
                            $stream = fopen($tmpPath, 'rb');
                            if ($stream) {
                                $linhasExtrato = ExtratoParser::parseCsvStream($stream, $categorias);
                                fclose($stream);
                            } else {
                                $linhasExtrato = [];
                            }
                        }

                        if (empty($linhasExtrato)) {
                            $erro = 'Não foi possível encontrar movimentações válidas no extrato. Certifique-se de que o arquivo contenha lançamentos financeiros com data e valor.';
                        } else {
                            // Calcula import_hash (OFX: FITID; CSV: sha256 com ocorrência) e sinaliza já importados
                            $ocorrencias = [];
                            $hashes = [];
                            foreach ($linhasExtrato as &$lin) {
                                if (!empty($lin['fitid'])) {
                                    $h = hash('sha256', 'OFX|' . $lin['fitid']);
                                } else {
                                    $descNorm = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)$lin['description'])));
                                    $chaveBase = $lin['date'] . '|' . number_format((float)$lin['amount'], 2, '.', '') . '|' . $descNorm;
                                    $ocorrencias[$chaveBase] = ($ocorrencias[$chaveBase] ?? 0) + 1;
                                    $h = hash('sha256', $chaveBase . '|' . $ocorrencias[$chaveBase]);
                                }
                                $lin['import_hash'] = $h;
                                $lin['ja_importado'] = false;
                                $hashes[] = $h;
                            }
                            unset($lin);

                            if (!empty($hashes) && Security::ensureImportHashColumn($pdo)) {
                                $placeholders = implode(',', array_fill(0, count($hashes), '?'));
                                $stmtExist = $pdo->prepare("SELECT import_hash FROM transactions WHERE user_id = ? AND account_id = ? AND visivel = 1 AND import_hash IN ({$placeholders})");
                                $stmtExist->execute(array_merge([$userId, $contaSelecionada], $hashes));
                                $existentesMap = array_flip($stmtExist->fetchAll(PDO::FETCH_COLUMN));
                                foreach ($linhasExtrato as &$lin) {
                                    if (isset($existentesMap[$lin['import_hash']])) {
                                        $lin['ja_importado'] = true;
                                    }
                                }
                                unset($lin);
                            }
                        }
                    } catch (Throwable $e) {
                        error_log("Erro no parsing de extrato ({$origName}): " . $e->getMessage());
                        $erro = 'Ocorreu um erro ao processar os dados do arquivo de extrato.';
                    }
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
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Importação de Extratos</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
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

            <form method="POST" action="import" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
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
                    <a href="statement" class="sair" style="padding:10px 18px; text-decoration:none;">Cancelar</a>
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

            <form method="POST" action="import">
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
                                        <input type="checkbox" name="importar[<?= $idx ?>]" value="1" <?= empty($lin['ja_importado']) ? 'checked' : '' ?>>
                                        <input type="hidden" name="linhas[<?= $idx ?>][import_hash]" value="<?= htmlspecialchars($lin['import_hash'] ?? '') ?>">
                                        <?php if (!empty($lin['ja_importado'])): ?>
                                            <div style="font-size:10px; color:#f59e0b; margin-top:2px;">já importado</div>
                                        <?php endif; ?>
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
                                            <?php foreach ($categorias as $c): ?>
                                                <?php if ($c['type'] === $lin['type']): ?>
                                                    <option value="<?= $c['id'] ?>" <?= ($lin['category_id'] == $c['id']) ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($c['name']) ?>
                                                    </option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <optgroup label="Outras categorias">
                                                <?php foreach ($categorias as $c): ?>
                                                    <?php if ($c['type'] !== $lin['type']): ?>
                                                        <option value="<?= $c['id'] ?>" <?= ($lin['category_id'] == $c['id']) ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($c['name']) ?> (<?= $c['type'] === 'income' ? 'rec' : 'desp' ?>)
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <option value="" <?= empty($lin['category_id']) ? 'selected' : '' ?>>Sem categoria</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; padding-top:16px; border-top:1px solid var(--color-border);">
                    <a href="import" class="sair" style="text-decoration:none; padding:10px 18px;">Voltar / Cancelar</a>
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


