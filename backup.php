<?php
/**
 * Módulo de Backup & Restauração de Dados Pessoais — Droma
 */
declare(strict_types=1);

require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

$erro = '';
$sucesso = '';

// -------------------------------------------------------------------------
// 1. DOWNLOAD DE BACKUP (JSON)
// -------------------------------------------------------------------------
if (isset($_GET['download']) && $_GET['download'] === 'json') {
    // 1. Contas
    $stmt = $pdo->prepare("SELECT id, name, type, initial_balance, created_at FROM accounts WHERE user_id = ?");
    $stmt->execute([$userId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Categorias
    $stmt = $pdo->prepare("SELECT id, name, type, budget_limit, created_at FROM categories WHERE user_id = ?");
    $stmt->execute([$userId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Cartões de crédito
    $stmt = $pdo->prepare("SELECT id, account_id, name, limit_amount, closing_day, due_day, color, active FROM credit_cards WHERE user_id = ?");
    $stmt->execute([$userId]);
    $creditCards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Metas
    $stmt = $pdo->prepare("SELECT id, name, target_amount, current_amount, deadline, created_at FROM goals WHERE user_id = ?");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4.1 Investimentos
    $stmt = $pdo->prepare("SELECT id, account_id, name, type, initial_amount, start_date, due_date, status, notes, created_at FROM investments WHERE user_id = ?");
    $stmt->execute([$userId]);
    $investments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Recorrências
    $stmt = $pdo->prepare("SELECT id, account_id, category_id, type, amount, description, frequency, next_date, active FROM recurring_transactions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $recurring = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Transações
    $stmt = $pdo->prepare("SELECT id, account_id, category_id, credit_card_id, investment_id, type, amount, description, date, due_date, status, urgency, created_at FROM transactions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $backupData = [
        'app'         => 'DromaFinancy',
        'schema_version' => '1.1',
        'exported_at' => date('Y-m-d H:i:s'),
        'user_id'     => $userId,
        'summary'     => [
            'accounts_count'     => count($accounts),
            'categories_count'   => count($categories),
            'credit_cards_count' => count($creditCards),
            'investments_count'  => count($investments),
            'transactions_count' => count($transactions),
            'goals_count'        => count($goals),
            'recurring_count'    => count($recurring)
        ],
        'data'        => [
            'accounts'               => $accounts,
            'categories'             => $categories,
            'credit_cards'           => $creditCards,
            'investments'            => $investments,
            'goals'                  => $goals,
            'recurring_transactions' => $recurring,
            'transactions'           => $transactions
        ]
    ];

    $filename = 'droma-backup-' . date('Y-m-d-His') . '.json';
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// -------------------------------------------------------------------------
// 2. RESTAURAÇÃO DE BACKUP A PARTIR DE ARQUIVO JSON
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'restaurar') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada. Tente novamente.';
    } elseif (empty($_FILES['arquivo_backup']['tmp_name']) || !is_uploaded_file($_FILES['arquivo_backup']['tmp_name'])) {
        $erro = 'Por favor, selecione um arquivo JSON de backup válido.';
    } elseif (($_FILES['arquivo_backup']['size'] ?? 0) > 15 * 1024 * 1024) {
        $erro = 'O arquivo de backup excede o limite máximo permitido de 15MB.';
    } else {
        $origName = mb_strtolower($_FILES['arquivo_backup']['name'] ?? '');
        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        if ($ext !== 'json') {
            $erro = 'O arquivo de backup deve ser estritamente no formato .json gerado pelo Droma.';
        } else {
            $jsonStr = file_get_contents($_FILES['arquivo_backup']['tmp_name']);
            $payload = json_decode((string)$jsonStr, true);

            if (!is_array($payload) || !in_array($payload['app'] ?? '', ['DromaFinancy', 'WertFinancy'], true) || !isset($payload['data'])) {
                $erro = 'O arquivo selecionado não é um backup válido gerado pelo Droma.';
            } else {
            $data = $payload['data'];
            $mapAccounts = [];
            $mapCategories = [];
            $mapCards = [];

            try {
                $pdo->beginTransaction();

                // 1. Restaura Contas
                if (!empty($data['accounts']) && is_array($data['accounts'])) {
                    $stmtAcc = $pdo->prepare("
                        INSERT INTO accounts (user_id, name, type, initial_balance)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($data['accounts'] as $acc) {
                        $oldId = (int)$acc['id'];
                        $name = trim($acc['name'] ?? 'Conta Restaurada');
                        $type = in_array($acc['type'] ?? '', ['checking','savings','cash','investment'], true) ? $acc['type'] : 'checking';
                        $ini  = (float)($acc['initial_balance'] ?? 0);

                        $stmtAcc->execute([$userId, $name, $type, $ini]);
                        $mapAccounts[$oldId] = (int)$pdo->lastInsertId();
                    }
                }

                // 2. Restaura Categorias
                if (!empty($data['categories']) && is_array($data['categories'])) {
                    $stmtCat = $pdo->prepare("
                        INSERT INTO categories (user_id, name, type, budget_limit)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE budget_limit=VALUES(budget_limit), id=LAST_INSERT_ID(id)
                    ");
                    foreach ($data['categories'] as $cat) {
                        $oldId = (int)$cat['id'];
                        $name = trim($cat['name'] ?? 'Outros');
                        $type = in_array($cat['type'] ?? '', ['income','expense'], true) ? $cat['type'] : 'expense';
                        $budgetLimit = !empty($cat['budget_limit']) && is_numeric($cat['budget_limit']) && (float)$cat['budget_limit'] > 0 ? (float)$cat['budget_limit'] : null;

                        $stmtCat->execute([$userId, $name, $type, $budgetLimit]);
                        $newId = (int)$pdo->lastInsertId();
                        // Se não retornou via lastInsertId, busca pelo nome
                        if ($newId === 0) {
                            $s = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ?");
                            $s->execute([$userId, $name, $type]);
                            $newId = (int)$s->fetchColumn();
                        }
                        $mapCategories[$oldId] = $newId;
                    }
                }

                // 3. Restaura Cartões de Crédito (vinculados à conta - regra 1 cartão por conta)
                if (!empty($data['credit_cards']) && is_array($data['credit_cards'])) {
                    $stmtCard = $pdo->prepare("
                        INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day, color, active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $assignedAccounts = [];
                    foreach ($data['credit_cards'] as $card) {
                        $oldId = (int)$card['id'];
                        $oldAccId = (int)($card['account_id'] ?? 0);
                        $targetAccId = $mapAccounts[$oldAccId] ?? null;

                        // Se a conta já recebeu outro cartão ou não foi mapeada, busca a próxima conta livre do usuário
                        if (!$targetAccId || in_array($targetAccId, $assignedAccounts, true)) {
                            $freeAccStmt = $pdo->prepare("
                                SELECT a.id FROM accounts a 
                                LEFT JOIN credit_cards c ON c.account_id = a.id 
                                WHERE a.user_id = ? AND c.id IS NULL 
                                LIMIT 1
                            ");
                            $freeAccStmt->execute([$userId]);
                            $targetAccId = $freeAccStmt->fetchColumn() ?: null;
                        }

                        if ($targetAccId) {
                            $stmtCard->execute([
                                $userId,
                                (int)$targetAccId,
                                trim($card['name'] ?? 'Cartão'),
                                (float)($card['limit_amount'] ?? 1000.00),
                                (int)($card['closing_day'] ?? 1),
                                (int)($card['due_day'] ?? 10),
                                $card['color'] ?? '#D4AF6C',
                                (int)($card['active'] ?? 1)
                            ]);
                            $mapCards[$oldId] = (int)$pdo->lastInsertId();
                            $assignedAccounts[] = (int)$targetAccId;
                        }
                    }
                }

                // 4. Restaura Metas
                if (!empty($data['goals']) && is_array($data['goals'])) {
                    $stmtGoal = $pdo->prepare("
                        INSERT INTO goals (user_id, name, target_amount, current_amount, deadline)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($data['goals'] as $g) {
                        $goalName = trim($g['name'] ?? ($g['title'] ?? 'Meta Restaurada'));
                        $stmtGoal->execute([
                            $userId,
                            $goalName,
                            (float)($g['target_amount'] ?? 1000),
                            (float)($g['current_amount'] ?? 0),
                            !empty($g['deadline']) ? $g['deadline'] : null
                        ]);
                    }
                }

                // 4.1 Restaura Investimentos
                $mapInvestments = [];
                if (!empty($data['investments']) && is_array($data['investments'])) {
                    $stmtInv = $pdo->prepare("
                        INSERT INTO investments (user_id, account_id, name, type, initial_amount, start_date, due_date, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($data['investments'] as $inv) {
                        $oldId = (int)$inv['id'];
                        $oldAccId = !empty($inv['account_id']) ? (int)$inv['account_id'] : null;
                        $targetAccId = $oldAccId ? ($mapAccounts[$oldAccId] ?? null) : null;

                        $stmtInv->execute([
                            $userId,
                            $targetAccId,
                            trim($inv['name'] ?? 'Investimento'),
                            $inv['type'] ?? 'renda_fixa',
                            (float)($inv['initial_amount'] ?? 0),
                            !empty($inv['start_date']) ? $inv['start_date'] : date('Y-m-d'),
                            !empty($inv['due_date']) ? $inv['due_date'] : null,
                            $inv['status'] ?? 'active',
                            $inv['notes'] ?? null
                        ]);
                        $mapInvestments[$oldId] = (int)$pdo->lastInsertId();
                    }
                }

                // 5. Restaura Transações
                $txCount = 0;
                if (!empty($data['transactions']) && is_array($data['transactions'])) {
                    $stmtTx = $pdo->prepare("
                        INSERT INTO transactions 
                            (user_id, account_id, category_id, credit_card_id, investment_id, type, amount, description, date, due_date, status, urgency)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($data['transactions'] as $tx) {
                        $oldAccId = (int)($tx['account_id'] ?? 0);
                        $oldCatId = !empty($tx['category_id']) ? (int)$tx['category_id'] : null;
                        $oldCardId = !empty($tx['credit_card_id']) ? (int)$tx['credit_card_id'] : null;
                        $oldInvId = !empty($tx['investment_id']) ? (int)$tx['investment_id'] : null;

                        $accId = $mapAccounts[$oldAccId] ?? array_values($mapAccounts)[0] ?? null;
                        if (!$accId) {
                            // Se não mapeou conta nova, usa a primeira conta existente do usuário
                            $s = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? LIMIT 1");
                            $s->execute([$userId]);
                            $accId = (int)$s->fetchColumn();
                        }

                        $catId = $oldCatId ? ($mapCategories[$oldCatId] ?? null) : null;
                        if (!$catId) {
                            $tipoTx = in_array($tx['type'] ?? '', ['income', 'expense'], true) ? $tx['type'] : 'expense';
                            $s = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = ? LIMIT 1");
                            $s->execute([$userId, $tipoTx]);
                            $catId = (int)$s->fetchColumn();
                            if (!$catId) {
                                $insCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Geral', ?)");
                                $insCat->execute([$userId, $tipoTx]);
                                $catId = (int)$pdo->lastInsertId();
                            }
                        }
                        $cardId = $oldCardId ? ($mapCards[$oldCardId] ?? null) : null;
                        $invId = $oldInvId ? ($mapInvestments[$oldInvId] ?? null) : null;

                        $stmtTx->execute([
                            $userId,
                            $accId,
                            $catId,
                            $cardId,
                            $invId,
                            $tx['type'] ?? 'expense',
                            (float)($tx['amount'] ?? 0),
                            $tx['description'] ?? 'Lançamento restaurado',
                            $tx['date'] ?? date('Y-m-d'),
                            !empty($tx['due_date']) ? $tx['due_date'] : null,
                            $tx['status'] ?? 'completed',
                            $tx['urgency'] ?? 'media'
                        ]);
                        $txCount++;
                    }
                }

                $pdo->commit();
                $sucesso = "Backup restaurado com sucesso! {$txCount} movimentações e cadastros foram recuperados.";

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Erro ao restaurar backup: " . $e->getMessage());
                $erro = "Falha ao restaurar dados do arquivo: " . $e->getMessage();
            }
        }
    }
}
}

// Estatísticas atuais para exibir no painel de backup
$totaisAtuais = [];
$totaisAtuais['contas'] = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE user_id = {$userId}")->fetchColumn();
$totaisAtuais['categorias'] = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE user_id = {$userId}")->fetchColumn();
$totaisAtuais['transacoes'] = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$userId}")->fetchColumn();
$totaisAtuais['cartoes'] = (int)$pdo->query("SELECT COUNT(*) FROM credit_cards WHERE user_id = {$userId}")->fetchColumn();
$totaisAtuais['metas'] = (int)$pdo->query("SELECT COUNT(*) FROM goals WHERE user_id = {$userId}")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Droma - Backup e Restauração</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
    <style>
        .backup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr));
            gap: 20px;
            margin-top: 20px;
            width: 100%;
            min-width: 0;
        }
        @media (max-width: 640px) {
            .backup-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }
        .backup-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-badge {
            background: var(--color-surface-secondary);
            border: 1px solid var(--color-border);
            padding: 8px 12px;
            border-radius: var(--radius-md);
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

<?php
$paginaAtiva = 'backup';
$tituloPagina = 'Backup & Soberania dos Dados';
require_once "includes/layout_header.php";
?>

<main class="painel" style="max-width: 1040px; margin: 0 auto; padding: 24px 20px;">

    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 26px; font-weight: 800; color: var(--color-text); margin-bottom: 4px;">
            Backup & Soberania dos Dados
        </h1>
        <p style="color: var(--color-text-secondary); font-size: 14px;">
            Exporte todos os seus dados para cópia de segurança pessoal ou restaure informações a partir de um backup anterior.
        </p>
    </div>

    <?php if (!empty($erro)): ?>
        <div style="background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); color: #FCA5A5; padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px; font-size: 14px;">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div class="alerta-dashboard" style="margin-bottom: 20px;">
            <span><?= htmlspecialchars($sucesso) ?></span>
            <span style="cursor:pointer; font-weight:bold; font-size:16px;" onclick="this.parentElement.remove();">&times;</span>
        </div>
    <?php endif; ?>

    <div class="backup-grid">
        <!-- Card 1: Exportar Backup -->
        <div class="backup-card">
            <div>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:rgba(0, 200, 150, 0.15); display:flex; align-items:center; justify-content:center; color:var(--color-emerald); font-size:18px;">
                        📥
                    </div>
                    <h2 style="font-size: 18px; color: var(--color-gold-light); margin:0;">
                        Exportar Dados (Backup)
                    </h2>
                </div>
                <p style="color: var(--color-text-secondary); font-size: 13px; line-height:1.6; margin-bottom:16px;">
                    Baixa um arquivo JSON seguro e legível contendo todas as suas contas, categorias, lançamentos, cartões e metas.
                </p>

                <div style="margin-bottom:18px;">
                    <div class="stat-badge">
                        <span>Contas Cadastradas:</span>
                        <strong style="color:var(--color-text);"><?= $totaisAtuais['contas'] ?></strong>
                    </div>
                    <div class="stat-badge">
                        <span>Categorias:</span>
                        <strong style="color:var(--color-text);"><?= $totaisAtuais['categorias'] ?></strong>
                    </div>
                    <div class="stat-badge">
                        <span>Transações Registradas:</span>
                        <strong style="color:var(--color-emerald);"><?= $totaisAtuais['transacoes'] ?></strong>
                    </div>
                    <div class="stat-badge">
                        <span>Cartões de Crédito:</span>
                        <strong style="color:var(--color-gold);"><?= $totaisAtuais['cartoes'] ?></strong>
                    </div>
                </div>
            </div>

            <a href="backup.php?download=json" class="btn-header-primary" style="justify-content:center; height:42px; text-decoration:none;">
                ⬇ Baixar Arquivo de Backup (.JSON)
            </a>
        </div>

        <!-- Card 2: Restaurar Backup -->
        <div class="backup-card">
            <div>
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:rgba(212, 175, 108, 0.15); display:flex; align-items:center; justify-content:center; color:var(--color-gold); font-size:18px;">
                        📤
                    </div>
                    <h2 style="font-size: 18px; color: var(--color-gold-light); margin:0;">
                        Restaurar Backup
                    </h2>
                </div>
                <p style="color: var(--color-text-secondary); font-size: 13px; line-height:1.6; margin-bottom:16px;">
                    Envie um arquivo <code>.json</code> gerado pelo Droma para recuperar seus lançamentos e cadastros com integridade relacional.
                </p>

                <form method="POST" action="backup.php" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:14px;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="acao" value="restaurar">

                    <label for="arquivo_backup" style="font-size:13px; font-weight:600; color:var(--color-text-secondary);">
                        Arquivo de Backup (.json):
                    </label>
                    <input type="file" name="arquivo_backup" id="arquivo_backup" accept=".json,application/json" required style="background:var(--color-surface-secondary); color:var(--color-text); padding:12px; border:1px dashed var(--color-border); border-radius:var(--radius-md); font-size:13px; cursor:pointer;">

                    <div style="font-size:11px; color:var(--color-text-secondary); line-height:1.5;">
                        🛡️ As transações do arquivo serão associadas ao seu usuário autenticado sem sobrescrever registros pré-existentes.
                    </div>

                    <button type="submit" class="sair" style="height:42px; cursor:pointer; font-size:14px; margin-top:8px;" onclick="return confirm('Deseja iniciar a restauração dos dados do arquivo selecionado?')">
                        🔄 Iniciar Restauração
                    </button>
                </form>
            </div>
        </div>
    </div>

</main>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>
