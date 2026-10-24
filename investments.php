<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

$tiposInvestimento = [
    'renda_fixa' => 'Renda Fixa (CDB, Tesouro, LCI/LCA)',
    'acoes'      => 'Ações (Bolsa de Valores)',
    'fiis'       => 'Fundos Imobiliários (FIIs)',
    'cripto'     => 'Criptoativos',
    'fundos'     => 'Fundos de Investimento',
    'outros'     => 'Outros Ativos'
];

// Helper para obter ou criar categoria financeira
if (!function_exists('obterOuCriarCategoria')) {
    function obterOuCriarCategoria(PDO $pdo, int $userId, string $nome, string $tipo): int {
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ? LIMIT 1");
        $stmt->execute([$userId, $nome, $tipo]);
        $catId = $stmt->fetchColumn();
        if (!$catId) {
            $ins = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)");
            $ins->execute([$userId, $nome, $tipo]);
            $catId = (int)$pdo->lastInsertId();
        }
        return (int)$catId;
    }
}

// Carrega as contas do usuário para custódia e movimentações
$stmtContas = $pdo->prepare("SELECT id, name, type FROM accounts WHERE user_id = ? ORDER BY name ASC");
$stmtContas->execute([$userId]);
$contas = $stmtContas->fetchAll(PDO::FETCH_ASSOC);

// Processamento de Ações POST
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Security::validateCsrf($token)) {
        $erro = 'Ação não autorizada ou sessão expirada. Por favor, atualize a página.';
    } else {
        $acao = $_POST['acao'] ?? '';

        if ($acao === 'criar') {
            $nome       = trim($_POST['name'] ?? '');
            $tipo       = $_POST['type'] ?? 'renda_fixa';
            $accountId  = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
            $valorRaw   = str_replace(',', '.', trim($_POST['initial_amount'] ?? '0'));
            $dataInicio = trim($_POST['start_date'] ?? date('Y-m-d'));
            $dataVenc   = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
            $notas      = trim($_POST['notes'] ?? '');

            if (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome válido para o investimento de até 100 caracteres.';
            } elseif (!array_key_exists($tipo, $tiposInvestimento)) {
                $erro = 'Tipo de investimento inválido.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw < 0) {
                $erro = 'Informe um valor inicial válido.';
            } elseif ($accountId !== null && !Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Conta de custódia inválida ou não autorizada.';
            } else {
                $valorInicial = (float)$valorRaw;
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("
                        INSERT INTO investments (user_id, account_id, name, type, initial_amount, start_date, due_date, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
                    ");
                    $stmt->execute([$userId, $accountId, $nome, $tipo, $valorInicial, $dataInicio, $dataVenc, $notas]);
                    $invId = (int)$pdo->lastInsertId();

                    // Se houver valor inicial e conta selecionada, cria a movimentação de saída (Aporte Inicial)
                    if ($valorInicial > 0 && $accountId !== null) {
                        $catId = obterOuCriarCategoria($pdo, $userId, 'Aporte de Investimento', 'expense');
                        $desc = "Aporte Inicial - " . $nome;
                        $stmtTx = $pdo->prepare("
                            INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status, urgency)
                            VALUES (?, ?, ?, ?, 'expense', ?, ?, ?, 'completed', 'media')
                        ");
                        $stmtTx->execute([$userId, $accountId, $invId, $catId, $valorInicial, $desc, $dataInicio]);
                    }

                    $pdo->commit();
                    header("Location: investments?sucesso=" . urlencode("Investimento '{$nome}' cadastrado com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Erro ao criar investimento: " . $e->getMessage());
                    $erro = 'Erro ao cadastrar investimento no banco de dados.';
                }
            }
        } elseif ($acao === 'editar') {
            $invId      = (int)($_POST['investment_id'] ?? 0);
            $nome       = trim($_POST['name'] ?? '');
            $tipo       = $_POST['type'] ?? 'renda_fixa';
            $accountId  = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
            $dataVenc   = !empty($_POST['due_date']) ? trim($_POST['due_date']) : null;
            $status     = in_array($_POST['status'] ?? '', ['active', 'redeemed', 'cancelled']) ? $_POST['status'] : 'active';
            $notas      = trim($_POST['notes'] ?? '');

            if (!Security::validateInvestmentOwnership($pdo, $invId, $userId)) {
                $erro = 'Investimento não encontrado ou não autorizado.';
            } elseif (empty($nome) || mb_strlen($nome) > 100) {
                $erro = 'Informe um nome válido para o investimento.';
            } elseif (!array_key_exists($tipo, $tiposInvestimento)) {
                $erro = 'Tipo de investimento inválido.';
            } elseif ($accountId !== null && !Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Conta de custódia inválida.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE investments 
                        SET name = ?, type = ?, account_id = ?, due_date = ?, status = ?, notes = ?
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$nome, $tipo, $accountId, $dataVenc, $status, $notas, $invId, $userId]);
                    header("Location: investments?sucesso=" . urlencode("Investimento '{$nome}' atualizado com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao atualizar investimento: " . $e->getMessage());
                    $erro = 'Erro ao atualizar o investimento.';
                }
            }
        } elseif ($acao === 'novo_aporte') {
            $invId      = (int)($_POST['investment_id'] ?? 0);
            $accountId  = (int)($_POST['account_id'] ?? 0);
            $valorRaw   = str_replace(',', '.', trim($_POST['amount'] ?? '0'));
            $data       = trim($_POST['date'] ?? date('Y-m-d'));
            $desc       = trim($_POST['description'] ?? '');

            if (!Security::validateInvestmentOwnership($pdo, $invId, $userId)) {
                $erro = 'Investimento não encontrado.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Selecione a conta de onde sairá o dinheiro para o aporte.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
                $erro = 'Informe um valor válido maior que zero.';
            } else {
                $valor = (float)$valorRaw;
                try {
                    $stmtInv = $pdo->prepare("SELECT name FROM investments WHERE id = ?");
                    $stmtInv->execute([$invId]);
                    $invNome = $stmtInv->fetchColumn();

                    $catId = obterOuCriarCategoria($pdo, $userId, 'Aporte de Investimento', 'expense');
                    $descFinal = !empty($desc) ? $desc : "Novo Aporte - " . $invNome;

                    $stmtTx = $pdo->prepare("
                        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status, urgency)
                        VALUES (?, ?, ?, ?, 'expense', ?, ?, ?, 'completed', 'media')
                    ");
                    $stmtTx->execute([$userId, $accountId, $invId, $catId, $valor, $descFinal, $data]);

                    header("Location: investments?sucesso=" . urlencode("Aporte de R$ " . number_format($valor, 2, ',', '.') . " realizado com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao registrar aporte: " . $e->getMessage());
                    $erro = 'Erro ao registrar aporte financeiro.';
                }
            }
        } elseif ($acao === 'novo_rendimento') {
            $invId      = (int)($_POST['investment_id'] ?? 0);
            $accountId  = (int)($_POST['account_id'] ?? 0);
            $valorRaw   = str_replace(',', '.', trim($_POST['amount'] ?? '0'));
            $data       = trim($_POST['date'] ?? date('Y-m-d'));
            $desc       = trim($_POST['description'] ?? '');

            if (!Security::validateInvestmentOwnership($pdo, $invId, $userId)) {
                $erro = 'Investimento não encontrado.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Selecione a conta de destino para receber o rendimento.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
                $erro = 'Informe um valor válido maior que zero.';
            } else {
                $valor = (float)$valorRaw;
                try {
                    $stmtInv = $pdo->prepare("SELECT name FROM investments WHERE id = ?");
                    $stmtInv->execute([$invId]);
                    $invNome = $stmtInv->fetchColumn();

                    $catId = obterOuCriarCategoria($pdo, $userId, 'Rendimentos e Proventos', 'income');
                    $descFinal = !empty($desc) ? $desc : "Rendimento / Dividendo - " . $invNome;

                    $stmtTx = $pdo->prepare("
                        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status, urgency)
                        VALUES (?, ?, ?, ?, 'income', ?, ?, ?, 'completed', 'baixa')
                    ");
                    $stmtTx->execute([$userId, $accountId, $invId, $catId, $valor, $descFinal, $data]);

                    header("Location: investments?sucesso=" . urlencode("Rendimento de R$ " . number_format($valor, 2, ',', '.') . " creditado com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao registrar rendimento: " . $e->getMessage());
                    $erro = 'Erro ao creditar rendimento.';
                }
            }
        } elseif ($acao === 'novo_resgate') {
            $invId          = (int)($_POST['investment_id'] ?? 0);
            $accountId      = (int)($_POST['account_id'] ?? 0);
            $valorRaw       = str_replace(',', '.', trim($_POST['amount'] ?? '0'));
            $data           = trim($_POST['date'] ?? date('Y-m-d'));
            $desc           = trim($_POST['description'] ?? '');
            $finalizarAtivo = !empty($_POST['finalizar_ativo']);

            if (!Security::validateInvestmentOwnership($pdo, $invId, $userId)) {
                $erro = 'Investimento não encontrado.';
            } elseif (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
                $erro = 'Selecione a conta de destino para receber o resgate.';
            } elseif (!is_numeric($valorRaw) || (float)$valorRaw <= 0) {
                $erro = 'Informe um valor válido de resgate maior que zero.';
            } else {
                $valor = (float)$valorRaw;
                try {
                    $pdo->beginTransaction();

                    $stmtInv = $pdo->prepare("SELECT name FROM investments WHERE id = ?");
                    $stmtInv->execute([$invId]);
                    $invNome = $stmtInv->fetchColumn();

                    $catId = obterOuCriarCategoria($pdo, $userId, 'Resgate de Investimento', 'income');
                    $descFinal = !empty($desc) ? $desc : "Resgate de Capital - " . $invNome;

                    $stmtTx = $pdo->prepare("
                        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status, urgency)
                        VALUES (?, ?, ?, ?, 'income', ?, ?, ?, 'completed', 'media')
                    ");
                    $stmtTx->execute([$userId, $accountId, $invId, $catId, $valor, $descFinal, $data]);

                    if ($finalizarAtivo) {
                        $updStatus = $pdo->prepare("UPDATE investments SET status = 'redeemed' WHERE id = ? AND user_id = ?");
                        $updStatus->execute([$invId, $userId]);
                    }

                    $pdo->commit();
                    header("Location: investments?sucesso=" . urlencode("Resgate de R$ " . number_format($valor, 2, ',', '.') . " creditado na conta com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log("Erro ao registrar resgate: " . $e->getMessage());
                    $erro = 'Erro ao processar o resgate do investimento.';
                }
            }
        } elseif ($acao === 'excluir') {
            $invId = (int)($_POST['investment_id'] ?? 0);
            if (!Security::validateInvestmentOwnership($pdo, $invId, $userId)) {
                $erro = 'Investimento inválido ou não autorizado.';
            } else {
                try {
                    $delStmt = $pdo->prepare("DELETE FROM investments WHERE id = ? AND user_id = ?");
                    $delStmt->execute([$invId, $userId]);
                    header("Location: investments?sucesso=" . urlencode("Investimento excluído com sucesso!"));
                    exit;
                } catch (PDOException $e) {
                    error_log("Erro ao excluir investimento: " . $e->getMessage());
                    $erro = 'Erro ao excluir o investimento.';
                }
            }
        }
    }
}

// Carrega os investimentos do usuário com cálculos atômicos de aportes e retornos
$stmtInvest = $pdo->prepare("
    SELECT 
        i.*,
        a.name AS account_name,
        COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_aportes,
        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_retornos,
        COUNT(t.id) AS qtd_movimentacoes
    FROM investments i
    LEFT JOIN accounts a ON a.id = i.account_id
    LEFT JOIN transactions t ON t.investment_id = i.id AND t.user_id = i.user_id
    WHERE i.user_id = ?
    GROUP BY i.id, i.user_id, i.account_id, i.name, i.type, i.initial_amount, i.start_date, i.due_date, i.status, i.notes, i.created_at, i.updated_at, a.name
    ORDER BY i.status ASC, i.start_date DESC
");
$stmtInvest->execute([$userId]);
$investimentos = $stmtInvest->fetchAll(PDO::FETCH_ASSOC);

// Totais e Rentabilidade Geral da Carteira
$totalAportesGeral = 0.00;
$totalRetornosGeral = 0.00;

foreach ($investimentos as &$inv) {
    // Se o valor inicial não foi transacionado (ex: sem conta no momento do cadastro), consideramos no aporte
    $aportesAtivo = (float)$inv['total_aportes'];
    if ($aportesAtivo == 0 && (float)$inv['initial_amount'] > 0) {
        $aportesAtivo = (float)$inv['initial_amount'];
    }
    $retornosAtivo = (float)$inv['total_retornos'];
    
    $inv['aportes_calculados'] = $aportesAtivo;
    $inv['retornos_calculados'] = $retornosAtivo;
    $inv['lucro_liquido'] = $retornosAtivo - $aportesAtivo;
    $inv['roi_pct'] = $aportesAtivo > 0 ? round((($retornosAtivo - $aportesAtivo) / $aportesAtivo) * 100, 2) : 0.0;

    $totalAportesGeral += $aportesAtivo;
    $totalRetornosGeral += $retornosAtivo;
}
unset($inv);

$lucroLiquidoGeral = $totalRetornosGeral - $totalAportesGeral;
$roiGeral = $totalAportesGeral > 0 ? round(($lucroLiquidoGeral / $totalAportesGeral) * 100, 2) : 0.0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Gestão de Investimentos & ROI</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#070A0E">
    <style>
        .badge-tipo-inv {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: var(--radius-pill);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .tipo-renda_fixa { background: rgba(59, 130, 246, 0.15); color: #60A5FA; border: 1px solid rgba(59, 130, 246, 0.3); }
        .tipo-acoes      { background: rgba(16, 185, 129, 0.15); color: #34D399; border: 1px solid rgba(16, 185, 129, 0.3); }
        .tipo-fiis       { background: rgba(212, 175, 108, 0.15); color: var(--color-gold); border: 1px solid rgba(212, 175, 108, 0.3); }
        .tipo-cripto     { background: rgba(236, 72, 153, 0.15); color: #F472B6; border: 1px solid rgba(236, 72, 153, 0.3); }
        .tipo-fundos     { background: rgba(139, 92, 246, 0.15); color: #A78BFA; border: 1px solid rgba(139, 92, 246, 0.3); }
        .tipo-outros     { background: rgba(148, 163, 184, 0.15); color: #CBD5E1; border: 1px solid rgba(148, 163, 184, 0.3); }
        
        .inv-card {
            background: var(--color-surface-secondary);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 16px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s, border-color 0.2s;
            min-width: 0;
            width: 100%;
            box-sizing: border-box;
        }
        .inv-card:hover {
            transform: translateY(-2px);
            border-color: rgba(212, 175, 108, 0.3);
        }
        .inv-metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            padding: 5px 0;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            gap: 8px;
            flex-wrap: wrap;
            min-width: 0;
        }
        .inv-metric-row:last-child {
            border-bottom: none;
        }
        .inv-metric-row span,
        .inv-metric-row strong {
            min-width: 0;
            word-break: break-word;
        }
        .btn-acao-inv {
            padding: 5px 9px;
            font-size: 11px;
            font-weight: 600;
            border-radius: 4px;
            border: 1px solid var(--color-border);
            background: var(--color-surface);
            color: var(--color-text);
            cursor: pointer;
            transition: all 0.15s;
        }
        .btn-acao-inv:hover {
            background: var(--color-surface-secondary);
            border-color: var(--color-gold);
            color: var(--color-gold-light);
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'investimentos';
$tituloPagina = 'Gestão de Investimentos';
require_once "includes/layout_header.php";
?>

<main class="painel">

    <?php if (!empty($erro)): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 12px 16px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 13px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div style="background: rgba(16, 185, 129, 0.12); border: 1px solid rgba(16, 185, 129, 0.35); color: #6EE7B7; padding: 12px 16px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 13px;">
            <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>

    <!-- Resumo da Carteira de Investimentos -->
    <section class="cartoes">
        <div class="cartao">
            <span class="rotulo">Total Aportado (Saídas)</span>
            <span class="valor" style="color:var(--color-warning);">R$ <?= number_format($totalAportesGeral, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Capital alocado nos ativos
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Total Recebido (Entradas)</span>
            <span class="valor positivo">+ R$ <?= number_format($totalRetornosGeral, 2, ',', '.') ?></span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Proventos, juros e resgates
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Resultado Líquido</span>
            <span class="valor <?= $lucroLiquidoGeral >= 0 ? 'positivo' : 'negativo' ?>">
                <?= $lucroLiquidoGeral >= 0 ? '+ ' : '' ?>R$ <?= number_format($lucroLiquidoGeral, 2, ',', '.') ?>
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                Lucro real obtido até o momento
            </span>
        </div>

        <div class="cartao">
            <span class="rotulo">Retorno sobre Investimento (ROI)</span>
            <span class="valor" style="color: <?= $roiGeral >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                <?= $roiGeral >= 0 ? '+' : '' ?><?= number_format($roiGeral, 2, ',', '.') ?>%
            </span>
            <span style="font-size:12px; color:var(--color-text-secondary); margin-top:4px;">
                <?= count($investimentos) ?> ativo(s) cadastrado(s)
            </span>
        </div>
    </section>

    <!-- Grade: Portfólio de Ativos e Formulário -->
    <section class="grade">

        <!-- Lista de Investimentos -->
        <div class="bloco">
            <div class="cabecalho-secao" style="margin-bottom:16px;">
                <h2 style="margin:0;">Seu Portfólio de Ativos</h2>
                <span style="font-size:12px; color:var(--color-text-secondary);"><?= count($investimentos) ?> ativo(s)</span>
            </div>

            <?php if (empty($investimentos)): ?>
                <p class="vazio">Nenhum investimento cadastrado. Utilize o formulário ao lado para cadastrar suas aplicações (Renda Fixa, Ações, FIIs, etc.) e vincular aportes e rendimentos!</p>
            <?php else: ?>
                <div class="investimentos-grid">
                    <?php foreach ($investimentos as $inv): 
                        $tipoKey = $inv['type'] ?? 'outros';
                        $statusLabel = $inv['status'] === 'redeemed' ? 'Resgatado' : ($inv['status'] === 'cancelled' ? 'Cancelado' : 'Ativo');
                    ?>
                        <div class="inv-card">
                            <div>
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; gap:8px;">
                                    <div style="flex:1; min-width:0;">
                                        <strong style="font-size:16px; color:var(--color-text); display:block; word-break:break-word;">
                                            <?= htmlspecialchars($inv['name']) ?>
                                        </strong>
                                        <span style="font-size:11px; color:var(--color-text-secondary); word-break:break-word;">
                                            Início: <?= date('d/m/Y', strtotime($inv['start_date'])) ?>
                                            <?= !empty($inv['due_date']) ? ' • Venc: ' . date('d/m/Y', strtotime($inv['due_date'])) : '' ?>
                                        </span>
                                    </div>
                                    <div style="text-align:right;">
                                        <span class="badge-tipo-inv tipo-<?= $tipoKey ?>">
                                            <?= $tiposInvestimento[$tipoKey] ?? $tipoKey ?>
                                        </span>
                                        <div style="font-size:10px; color:<?= $inv['status'] === 'active' ? 'var(--color-emerald)' : 'var(--color-text-secondary)' ?>; margin-top:3px; font-weight:700;">
                                            ● <?= $statusLabel ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if (!empty($inv['account_name'])): ?>
                                    <div style="font-size:11px; color:var(--color-gold-light); margin-bottom:10px; background:rgba(212, 175, 108, 0.08); padding:4px 8px; border-radius:4px;">
                                        🏦 Custodiado em: <strong><?= htmlspecialchars($inv['account_name']) ?></strong>
                                    </div>
                                <?php endif; ?>

                                <!-- Métricas Financeiras Relacionadas -->
                                <div style="background:var(--color-surface); padding:10px; border-radius:6px; margin:10px 0; border:1px solid var(--color-border);">
                                    <div class="inv-metric-row">
                                        <span style="color:var(--color-text-secondary);">Total Aportado:</span>
                                        <strong style="color:var(--color-warning);">R$ <?= number_format($inv['aportes_calculados'], 2, ',', '.') ?></strong>
                                    </div>
                                    <div class="inv-metric-row">
                                        <span style="color:var(--color-text-secondary);">Rendimentos / Resgates:</span>
                                        <strong style="color:var(--color-emerald);">+ R$ <?= number_format($inv['retornos_calculados'], 2, ',', '.') ?></strong>
                                    </div>
                                    <div class="inv-metric-row" style="font-weight:700;">
                                        <span style="color:var(--color-text);">Lucro Líquido Real:</span>
                                        <span style="color:<?= $inv['lucro_liquido'] >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>;">
                                            <?= $inv['lucro_liquido'] >= 0 ? '+' : '' ?>R$ <?= number_format($inv['lucro_liquido'], 2, ',', '.') ?>
                                        </span>
                                    </div>
                                    <div class="inv-metric-row" style="font-size:11px;">
                                        <span style="color:var(--color-text-secondary);">Rentabilidade (ROI):</span>
                                        <span style="color:<?= $inv['roi_pct'] >= 0 ? 'var(--color-emerald)' : 'var(--color-danger)' ?>; font-weight:800;">
                                            <?= $inv['roi_pct'] >= 0 ? '+' : '' ?><?= number_format($inv['roi_pct'], 2, ',', '.') ?>%
                                        </span>
                                    </div>
                                </div>

                                <?php if (!empty($inv['notes'])): ?>
                                    <p style="font-size:11px; color:var(--color-text-secondary); margin:6px 0; font-style:italic;">
                                        <?= htmlspecialchars($inv['notes']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Barra de Ações Rápidas: Aporte, Rendimento, Resgate, Extrato -->
                            <div style="margin-top:14px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.08);">
                                <div style="display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px;">
                                    <?php if ($inv['status'] === 'active'): ?>
                                        <button type="button" class="btn-acao-inv" style="border-color:var(--color-warning); color:#FCD34D;" onclick="abrirModalAporte(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES, 'UTF-8') ?>)">
                                            + Aporte
                                        </button>
                                        <button type="button" class="btn-acao-inv" style="border-color:var(--color-emerald); color:#6EE7B7;" onclick="abrirModalRendimento(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES, 'UTF-8') ?>)">
                                            + Rendimento
                                        </button>
                                        <button type="button" class="btn-acao-inv" style="border-color:var(--color-gold); color:var(--color-gold);" onclick="abrirModalResgate(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES, 'UTF-8') ?>)">
                                            ⇄ Resgate
                                        </button>
                                    <?php endif; ?>
                                    <a href="statement?busca=<?= urlencode($inv['name']) ?>" class="btn-acao-inv" style="text-decoration:none;" title="Ver movimentações no extrato">
                                        Extrato (<?= $inv['qtd_movimentacoes'] ?>)
                                    </a>
                                </div>

                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <button type="button" class="btn-acao-lancamento" onclick="iniciarEdicaoInvestimento(<?= htmlspecialchars(json_encode($inv), ENT_QUOTES, 'UTF-8') ?>)">
                                        Editar
                                    </button>
                                    <form action="investments" method="POST" style="margin:0;" onsubmit="return confirm('Deseja realmente excluir este investimento? As transações vinculadas permanecerão registradas.');">
                                        <?= Security::csrfField() ?>
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="investment_id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="btn-acao-lancamento btn-acao-excluir" style="border:none; cursor:pointer;">
                                            Excluir
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Formulário para Criar ou Editar Investimento -->
        <div class="bloco" style="height:fit-content;">
            <h2 id="formTituloInvestimento">+ Novo Ativo / Investimento</h2>
            <form id="formInvestimento" action="investments" method="POST" class="form-lancamento" style="max-width:100%; padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" id="invAcao" value="criar">
                <input type="hidden" name="investment_id" id="invId" value="">

                <label>Nome do Ativo
                    <input type="text" name="name" id="invNome" placeholder="Ex: Tesouro Selic 2029, CDB Inter 110%, MXRF11..." maxlength="100" required>
                </label>

                <label>Tipo de Investimento
                    <select name="type" id="invTipo" required>
                        <?php foreach ($tiposInvestimento as $k => $rotulo): ?>
                            <option value="<?= $k ?>"><?= htmlspecialchars($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Conta / Corretora de Custódia (Opcional)
                    <select name="account_id" id="invAccountId">
                        <option value="">Nenhuma / Outra</option>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div id="campoValorInicial">
                    <label>Aporte Inicial (R$)
                        <input type="number" step="0.01" min="0" name="initial_amount" id="invValorInicial" placeholder="0,00" value="0.00">
                        <span style="font-size:11px; color:var(--color-text-secondary); display:block; margin-top:2px;">
                            ✦ Se informado com uma conta selecionada, gera o débito de saída automaticamente.
                        </span>
                    </label>
                </div>

                <div class="form-grid-datas">
                    <label>Data de Início
                        <input type="date" name="start_date" id="invDataInicio" value="<?= date('Y-m-d') ?>" required>
                    </label>

                    <label>Vencimento (Opcional)
                        <input type="date" name="due_date" id="invDataVenc">
                    </label>
                </div>

                <div id="campoStatusEdit" style="display:none;">
                    <label>Situação do Ativo
                        <select name="status" id="invStatus">
                            <option value="active">Ativo (Em andamento)</option>
                            <option value="redeemed">Resgatado (Encerrado)</option>
                            <option value="cancelled">Cancelado</option>
                        </select>
                    </label>
                </div>

                <label>Anotações e Estratégia
                    <textarea name="notes" id="invNotas" placeholder="Ex: Indexado ao CDI, objetivo para aposentadoria, etc." rows="2"></textarea>
                </label>

                <div style="display:flex; gap:10px; margin-top:8px;">
                    <button type="submit" id="btnSalvarInv" style="flex:1;">Cadastrar Ativo</button>
                    <button type="button" id="btnCancelarInv" class="sair" style="display:none;" onclick="cancelarEdicaoInv()">Cancelar</button>
                </div>
            </form>
        </div>

    </section>

    <!-- Modal Rápido: Novo Aporte -->
    <div id="modalAporte" onclick="if(event.target===this) fecharModais();" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-card-investimento">
            <h2 id="tituloModalAporte" style="margin-top:0; color:var(--color-warning);">+ Novo Aporte</h2>
            <p id="descModalAporte" style="font-size:13px; color:var(--color-text-secondary);"></p>

            <form action="investments" method="POST" class="form-lancamento" style="padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="novo_aporte">
                <input type="hidden" name="investment_id" id="aporteInvId" value="">

                <label>Conta de Origem (Saída do Dinheiro)
                    <select name="account_id" id="aporteAccountId" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Valor do Aporte (R$)
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="0,00" required>
                </label>

                <label>Data do Aporte
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </label>

                <label>Descrição (Opcional)
                    <input type="text" name="description" placeholder="Ex: Aporte mensal de Outubro">
                </label>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" style="flex:1;">Confirmar Aporte</button>
                    <button type="button" class="sair" onclick="fecharModais()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Rápido: Novo Rendimento / Dividendo -->
    <div id="modalRendimento" onclick="if(event.target===this) fecharModais();" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-card-investimento">
            <h2 id="tituloModalRendimento" style="margin-top:0; color:var(--color-emerald);">+ Novo Rendimento / Dividendo</h2>
            <p id="descModalRendimento" style="font-size:13px; color:var(--color-text-secondary);"></p>

            <form action="investments" method="POST" class="form-lancamento" style="padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="novo_rendimento">
                <input type="hidden" name="investment_id" id="rendimentoInvId" value="">

                <label>Conta de Destino (Entrada do Dinheiro)
                    <select name="account_id" id="rendimentoAccountId" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Valor dos Proventos / Rendimentos (R$)
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="0,00" required>
                </label>

                <label>Data do Crédito
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </label>

                <label>Descrição (Opcional)
                    <input type="text" name="description" placeholder="Ex: Dividendos Mensais, JCP...">
                </label>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" style="flex:1;">Creditar Rendimento</button>
                    <button type="button" class="sair" onclick="fecharModais()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Rápido: Novo Resgate -->
    <div id="modalResgate" onclick="if(event.target===this) fecharModais();" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center;">
        <div class="modal-card-investimento">
            <h2 id="tituloModalResgate" style="margin-top:0; color:var(--color-gold);">⇄ Resgate de Investimento</h2>
            <p id="descModalResgate" style="font-size:13px; color:var(--color-text-secondary);"></p>

            <form action="investments" method="POST" class="form-lancamento" style="padding:0; background:transparent; border:none; box-shadow:none;">
                <?= Security::csrfField() ?>
                <input type="hidden" name="acao" value="novo_resgate">
                <input type="hidden" name="investment_id" id="resgateInvId" value="">

                <label>Conta de Destino (Receber o Dinheiro)
                    <select name="account_id" id="resgateAccountId" required>
                        <?php foreach ($contas as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['type']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Valor a Resgatar (R$)
                    <input type="number" step="0.01" min="0.01" name="amount" placeholder="0,00" required>
                </label>

                <label>Data do Resgate
                    <input type="date" name="date" value="<?= date('Y-m-d') ?>" required>
                </label>

                <label>Descrição (Opcional)
                    <input type="text" name="description" placeholder="Ex: Resgate parcial / total com lucros">
                </label>

                <div style="margin:12px 0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="finalizar_ativo" value="1">
                        <span style="font-size:13px; color:var(--color-text);">Marcar investimento como <strong>Resgatado (Encerrado)</strong></span>
                    </label>
                </div>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" style="flex:1;">Confirmar Resgate</button>
                    <button type="button" class="sair" onclick="fecharModais()">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

</main>

<script>
function iniciarEdicaoInvestimento(inv) {
    document.getElementById('formTituloInvestimento').textContent = 'Editar: ' + inv.name;
    document.getElementById('invAcao').value = 'editar';
    document.getElementById('invId').value = inv.id;
    document.getElementById('invNome').value = inv.name;
    document.getElementById('invTipo').value = inv.type;
    document.getElementById('invAccountId').value = inv.account_id || '';
    document.getElementById('invDataInicio').value = inv.start_date;
    document.getElementById('invDataVenc').value = inv.due_date || '';
    document.getElementById('invStatus').value = inv.status;
    document.getElementById('invNotas').value = inv.notes || '';
    
    document.getElementById('campoValorInicial').style.display = 'none';
    document.getElementById('campoStatusEdit').style.display = 'block';
    document.getElementById('btnSalvarInv').textContent = 'Salvar Alterações';
    document.getElementById('btnCancelarInv').style.display = 'inline-block';
    document.getElementById('formInvestimento').scrollIntoView({ behavior: 'smooth' });
}

function cancelarEdicaoInv() {
    document.getElementById('formTituloInvestimento').textContent = '+ Novo Ativo / Investimento';
    document.getElementById('invAcao').value = 'criar';
    document.getElementById('invId').value = '';
    document.getElementById('invNome').value = '';
    document.getElementById('invTipo').value = 'renda_fixa';
    document.getElementById('invAccountId').value = '';
    document.getElementById('invValorInicial').value = '0.00';
    document.getElementById('invDataInicio').value = '<?= date('Y-m-d') ?>';
    document.getElementById('invDataVenc').value = '';
    document.getElementById('invStatus').value = 'active';
    document.getElementById('invNotas').value = '';
    
    document.getElementById('campoValorInicial').style.display = 'block';
    document.getElementById('campoStatusEdit').style.display = 'none';
    document.getElementById('btnSalvarInv').textContent = 'Cadastrar Ativo';
    document.getElementById('btnCancelarInv').style.display = 'none';
}

function abrirModalAporte(inv) {
    document.getElementById('aporteInvId').value = inv.id;
    document.getElementById('tituloModalAporte').textContent = '+ Novo Aporte: ' + inv.name;
    document.getElementById('descModalAporte').textContent = 'O valor será debitado da conta de origem selecionada e vinculado a este ativo.';
    if (inv.account_id) {
        document.getElementById('aporteAccountId').value = inv.account_id;
    }
    document.getElementById('modalAporte').style.display = 'flex';
}

function abrirModalRendimento(inv) {
    document.getElementById('rendimentoInvId').value = inv.id;
    document.getElementById('tituloModalRendimento').textContent = '+ Proventos / Rendimentos: ' + inv.name;
    document.getElementById('descModalRendimento').textContent = 'O valor será creditado na conta de destino e somado à rentabilidade deste ativo.';
    if (inv.account_id) {
        document.getElementById('rendimentoAccountId').value = inv.account_id;
    }
    document.getElementById('modalRendimento').style.display = 'flex';
}

function abrirModalResgate(inv) {
    document.getElementById('resgateInvId').value = inv.id;
    document.getElementById('tituloModalResgate').textContent = '⇄ Resgate de Capital: ' + inv.name;
    document.getElementById('descModalResgate').textContent = 'O valor sairá do investimento e entrará como saldo disponível na conta de destino selecionada.';
    if (inv.account_id) {
        document.getElementById('resgateAccountId').value = inv.account_id;
    }
    document.getElementById('modalResgate').style.display = 'flex';
}

function fecharModais() {
    document.getElementById('modalAporte').style.display = 'none';
    document.getElementById('modalRendimento').style.display = 'none';
    document.getElementById('modalResgate').style.display = 'none';
}
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


