<?php
declare(strict_types=1);
/**
 * Módulo de Backup & Restauração de Dados Pessoais — Droma
 */

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

    // 5.1 Parcelas de compras em cartão
    $stmtInst = $pdo->prepare("
        SELECT i.id, i.transaction_id, i.credit_card_id, i.installment_number, i.total_installments, i.due_date, i.amount, i.status 
        FROM installments i 
        INNER JOIN transactions t ON i.transaction_id = t.id 
        WHERE t.user_id = ?
    ");
    $stmtInst->execute([$userId]);
    $installments = $stmtInst->fetchAll(PDO::FETCH_ASSOC);

    // Contagem de transações para summary sem carregar tudo na memória
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
    $stmtCount->execute([$userId]);
    $transactionsCount = (int)$stmtCount->fetchColumn();

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'droma-backup-' . date('Y-m-d-His') . '.json';
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Streaming unbuffered para php://output mitigando estouro de RAM (OOM)
    $out = fopen('php://output', 'wb');

    $headerData = [
        'app'            => 'DromaFinancy',
        'schema_version' => '1.1',
        'exported_at'    => date('Y-m-d H:i:s'),
        'user_id'        => $userId,
        'summary'        => [
            'accounts_count'     => count($accounts),
            'categories_count'   => count($categories),
            'credit_cards_count' => count($creditCards),
            'investments_count'  => count($investments),
            'transactions_count' => $transactionsCount,
            'goals_count'        => count($goals),
            'recurring_count'    => count($recurring),
            'installments_count' => count($installments),
        ],
        'data'           => [
            'accounts'               => $accounts,
            'categories'             => $categories,
            'credit_cards'           => $creditCards,
            'investments'            => $investments,
            'goals'                  => $goals,
            'recurring_transactions' => $recurring,
            'installments'           => $installments,
        ]
    ];

    $jsonPrefix = json_encode($headerData, JSON_UNESCAPED_UNICODE);
    // Remove o fechamento de "data": { ... } para injetar "transactions" em streaming
    $jsonPrefixTrimmed = rtrim($jsonPrefix, " \t\n\r}");
    fwrite($out, $jsonPrefixTrimmed . ",\n    \"transactions\": [\n");

    $stmtTx = $pdo->prepare("SELECT id, account_id, category_id, category_name, credit_card_id, transfer_id, investment_id, goal_id, type, amount, description, date, due_date, status, urgency, visivel, created_at FROM transactions WHERE user_id = ? ORDER BY id ASC");
    $stmtTx->execute([$userId]);

    $firstTx = true;
    while ($txRow = $stmtTx->fetch(PDO::FETCH_ASSOC)) {
        if (!$firstTx) {
            fwrite($out, ",\n");
        }
        fwrite($out, "      " . json_encode($txRow, JSON_UNESCAPED_UNICODE));
        $firstTx = false;
    }

    fwrite($out, "\n    ]\n  }\n}\n");
    fclose($out);
    exit;
}

// -------------------------------------------------------------------------
// 2. RESTAURAÇÃO DE BACKUP A PARTIR DE ARQUIVO JSON
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'restaurar') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Ação não autorizada ou sessão expirada. Tente novamente.';
    } elseif (empty($_FILES['arquivo_backup'])) {
        $erro = 'Por favor, selecione um arquivo JSON de backup válido.';
    } else {
        // Validação estrita de segurança do arquivo de backup (Magic bytes, extensões, MIME, anti-injeção)
        $validation = Security::validateBackupUpload($_FILES['arquivo_backup']);
        if (!$validation['valid']) {
            $erro = $validation['error'];
        } else {
            $payload = $validation['payload'];
            $data = $payload['data'];

            try {
                $pdo->beginTransaction();

                // 1. LIMPEZA TOTAL DOS DADOS ANTERIORES DO USUÁRIO LOGADO (Substitui em vez de clonar/duplicar)
                $pdo->prepare("DELETE i FROM installments i INNER JOIN transactions t ON i.transaction_id = t.id WHERE t.user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM recurring_transactions WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM goals WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM investments WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM credit_cards WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$userId]);
                $pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userId]);

                $mapAccounts = [];
                $mapCategories = [];
                $mapCards = [];
                $mapInvestments = [];
                $mapTransactions = [];

                // 2. Restaura Contas
                $stmtAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, ?, ?, ?)");
                if (!empty($data['accounts']) && is_array($data['accounts'])) {
                    foreach ($data['accounts'] as $acc) {
                        $oldId = (int)($acc['id'] ?? 0);
                        $name = Security::sanitizeText($acc['name'] ?? '', 100, 'Conta Restaurada');
                        $type = in_array($acc['type'] ?? '', ['checking', 'savings', 'cash', 'investment'], true) ? $acc['type'] : 'checking';
                        $ini  = Security::sanitizeAmount($acc['initial_balance'] ?? 0, 0.0, true);

                        $stmtAcc->execute([$userId, $name, $type, $ini]);
                        $newId = (int)$pdo->lastInsertId();
                        if ($oldId > 0) {
                            $mapAccounts[$oldId] = $newId;
                        }
                    }
                }

                // Se o backup não trouxe contas, cria a conta padrão essencial
                if (empty($mapAccounts)) {
                    $stmtAcc->execute([$userId, 'Carteira Principal', 'checking', 0.00]);
                    $defAccId = (int)$pdo->lastInsertId();
                    $mapAccounts[0] = $defAccId;
                }

                // 3. Restaura Categorias
                $stmtCat = $pdo->prepare("INSERT INTO categories (user_id, name, type, budget_limit) VALUES (?, ?, ?, ?)");
                if (!empty($data['categories']) && is_array($data['categories'])) {
                    foreach ($data['categories'] as $cat) {
                        $oldId = (int)($cat['id'] ?? 0);
                        $name = Security::sanitizeText($cat['name'] ?? '', 100, 'Outros');
                        $type = in_array($cat['type'] ?? '', ['income', 'expense'], true) ? $cat['type'] : 'expense';
                        $budgetLimit = (!empty($cat['budget_limit']) && is_numeric($cat['budget_limit']) && (float)$cat['budget_limit'] > 0)
                            ? Security::sanitizeAmount($cat['budget_limit'], 0.0)
                            : null;

                        $stmtCat->execute([$userId, $name, $type, $budgetLimit]);
                        $newId = (int)$pdo->lastInsertId();
                        if ($oldId > 0) {
                            $mapCategories[$oldId] = $newId;
                        }
                    }
                }

                // Se não tiver categorias no arquivo, inicializa as categorias padrão
                if (empty($mapCategories)) {
                    $padroes = [
                        ['Alimentação', 'expense'],
                        ['Transporte', 'expense'],
                        ['Moradia', 'expense'],
                        ['Lazer', 'expense'],
                        ['Saúde', 'expense'],
                        ['Outros', 'expense'],
                        ['Salário', 'income'],
                        ['Rendimentos', 'income'],
                        ['Outras Receitas', 'income'],
                    ];
                    foreach ($padroes as [$pName, $pType]) {
                        $stmtCat->execute([$userId, $pName, $pType, null]);
                        $defCatId = (int)$pdo->lastInsertId();
                        $mapCategories["def_{$pName}"] = $defCatId;
                    }
                }

                // 4. Restaura Cartões de Crédito (1 cartão por conta bancária)
                if (!empty($data['credit_cards']) && is_array($data['credit_cards'])) {
                    $stmtCard = $pdo->prepare("
                        INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day, color, active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $assignedAccounts = [];
                    $allAccountIds = array_values($mapAccounts);

                    foreach ($data['credit_cards'] as $card) {
                        $oldId = (int)($card['id'] ?? 0);
                        $oldAccId = (int)($card['account_id'] ?? 0);
                        $targetAccId = $mapAccounts[$oldAccId] ?? null;

                        if (!$targetAccId || in_array($targetAccId, $assignedAccounts, true)) {
                            $targetAccId = null;
                            foreach ($allAccountIds as $candidateId) {
                                if (!in_array($candidateId, $assignedAccounts, true)) {
                                    $targetAccId = $candidateId;
                                    break;
                                }
                            }
                            if (!$targetAccId) {
                                $cardName = Security::sanitizeText($card['name'] ?? '', 40, 'Cartão');
                                $stmtAcc->execute([$userId, "Conta {$cardName}", 'checking', 0.00]);
                                $targetAccId = (int)$pdo->lastInsertId();
                                $allAccountIds[] = $targetAccId;
                            }
                        }

                        $cardName = Security::sanitizeText($card['name'] ?? '', 100, 'Cartão de Crédito');
                        $limitAmount = Security::sanitizeAmount($card['limit_amount'] ?? 1000.0, 1000.0);
                        $closingDay = (int)($card['closing_day'] ?? 1);
                        if ($closingDay < 1 || $closingDay > 31) $closingDay = 1;
                        $dueDay = (int)($card['due_day'] ?? 10);
                        if ($dueDay < 1 || $dueDay > 31) $dueDay = 10;
                        $color = (string)($card['color'] ?? '#D4AF6C');
                        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                            $color = '#D4AF6C';
                        }
                        $active = !empty($card['active']) ? 1 : 0;

                        $stmtCard->execute([$userId, $targetAccId, $cardName, $limitAmount, $closingDay, $dueDay, $color, $active]);
                        $newCardId = (int)$pdo->lastInsertId();
                        if ($oldId > 0) {
                            $mapCards[$oldId] = $newCardId;
                        }
                        $assignedAccounts[] = $targetAccId;
                    }
                }

                // 5. Restaura Metas
                $mapGoals = [];
                if (!empty($data['goals']) && is_array($data['goals'])) {
                    $stmtGoal = $pdo->prepare("
                        INSERT INTO goals (user_id, name, target_amount, current_amount, deadline)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($data['goals'] as $g) {
                        $oldGoalId = (int)($g['id'] ?? 0);
                        $goalName = Security::sanitizeText($g['name'] ?? ($g['title'] ?? ''), 100, 'Meta Restaurada');
                        $targetAmt = Security::sanitizeAmount($g['target_amount'] ?? 1000.0, 1000.0);
                        $currentAmt = Security::sanitizeAmount($g['current_amount'] ?? 0.0, 0.0);
                        $deadline = Security::sanitizeDate($g['deadline'] ?? null);

                        $stmtGoal->execute([$userId, $goalName, $targetAmt, $currentAmt, $deadline]);
                        $newGoalId = (int)$pdo->lastInsertId();
                        if ($oldGoalId > 0) {
                            $mapGoals[$oldGoalId] = $newGoalId;
                        }
                    }
                }

                // 6. Restaura Investimentos
                if (!empty($data['investments']) && is_array($data['investments'])) {
                    $stmtInv = $pdo->prepare("
                        INSERT INTO investments (user_id, account_id, name, type, initial_amount, start_date, due_date, status, notes)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($data['investments'] as $inv) {
                        $oldId = (int)($inv['id'] ?? 0);
                        $oldAccId = (int)($inv['account_id'] ?? 0);
                        $targetAccId = $mapAccounts[$oldAccId] ?? null;

                        $invName = Security::sanitizeText($inv['name'] ?? '', 100, 'Investimento Restaurado');
                        $invType = in_array($inv['type'] ?? '', ['renda_fixa', 'acoes', 'fiis', 'cripto', 'fundos', 'outros'], true) ? $inv['type'] : 'renda_fixa';
                        $initAmt = Security::sanitizeAmount($inv['initial_amount'] ?? 0.0, 0.0);
                        $startDate = Security::sanitizeDate($inv['start_date'] ?? null, date('Y-m-d'));
                        $dueDate = Security::sanitizeDate($inv['due_date'] ?? null);
                        $status = in_array($inv['status'] ?? '', ['active', 'redeemed', 'cancelled'], true) ? $inv['status'] : 'active';
                        $notes = !empty($inv['notes']) ? Security::sanitizeText($inv['notes'], 1000) : null;

                        $stmtInv->execute([$userId, $targetAccId, $invName, $invType, $initAmt, $startDate, $dueDate, $status, $notes]);
                        $newInvId = (int)$pdo->lastInsertId();
                        if ($oldId > 0) {
                            $mapInvestments[$oldId] = $newInvId;
                        }
                    }
                }

                // 7. Restaura Recorrências
                if (!empty($data['recurring_transactions']) && is_array($data['recurring_transactions'])) {
                    $stmtRec = $pdo->prepare("
                        INSERT INTO recurring_transactions (user_id, account_id, category_id, type, amount, description, frequency, day_of_month, next_date, active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $firstAccId = array_values($mapAccounts)[0];
                    $firstCatId = array_values($mapCategories)[0];

                    foreach ($data['recurring_transactions'] as $rec) {
                        $oldAccId = (int)($rec['account_id'] ?? 0);
                        $oldCatId = (int)($rec['category_id'] ?? 0);
                        $accId = $mapAccounts[$oldAccId] ?? $firstAccId;
                        $catId = $mapCategories[$oldCatId] ?? $firstCatId;

                        $rType = in_array($rec['type'] ?? '', ['income', 'expense'], true) ? $rec['type'] : 'expense';
                        $rAmt = Security::sanitizeAmount($rec['amount'] ?? 0.0, 0.0);
                        $rDesc = Security::sanitizeText($rec['description'] ?? '', 255, 'Recorrência Restaurada');
                        $rFreq = in_array($rec['frequency'] ?? '', ['weekly', 'monthly', 'yearly'], true) ? $rec['frequency'] : 'monthly';
                        $rDay = (int)($rec['day_of_month'] ?? 1);
                        if ($rDay < 1 || $rDay > 31) $rDay = 1;
                        $rNext = Security::sanitizeDate($rec['next_date'] ?? null, date('Y-m-d'));
                        $rAct = !empty($rec['active']) ? 1 : 0;

                        $stmtRec->execute([$userId, $accId, $catId, $rType, $rAmt, $rDesc, $rFreq, $rDay, $rNext, $rAct]);
                    }
                }

                // 8. Restaura Transações
                $txCount = 0;
                if (!empty($data['transactions']) && is_array($data['transactions'])) {
                    $stmtTx = $pdo->prepare("
                        INSERT INTO transactions 
                            (user_id, account_id, category_id, category_name, credit_card_id, transfer_id, investment_id, goal_id, type, amount, description, date, due_date, status, urgency, visivel)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $firstAccId = array_values($mapAccounts)[0];

                    foreach ($data['transactions'] as $tx) {
                        $oldTxId = (int)($tx['id'] ?? 0);
                        $oldAccId = (int)($tx['account_id'] ?? 0);
                        $oldCatId = (int)($tx['category_id'] ?? 0);
                        $oldCardId = (int)($tx['credit_card_id'] ?? 0);
                        $oldInvId = (int)($tx['investment_id'] ?? 0);
                        $oldGoalId = (int)($tx['goal_id'] ?? 0);

                        $accId = $mapAccounts[$oldAccId] ?? $firstAccId;
                        $tipoTx = in_array($tx['type'] ?? '', ['income', 'expense'], true) ? $tx['type'] : 'expense';

                        $catId = $mapCategories[$oldCatId] ?? null;
                        if (!$catId) {
                            foreach ($mapCategories as $cid) {
                                $catId = $cid;
                                break;
                            }
                        }

                        $cardId = $oldCardId > 0 ? ($mapCards[$oldCardId] ?? null) : null;
                        $invId = $oldInvId > 0 ? ($mapInvestments[$oldInvId] ?? null) : null;
                        $goalId = $oldGoalId > 0 ? ($mapGoals[$oldGoalId] ?? null) : null;
                        $transferId = !empty($tx['transfer_id']) ? Security::sanitizeText($tx['transfer_id'], 36) : null;
                        $catSnapshot = Security::sanitizeText($tx['category_name'] ?? '', 100);
                        $visivel = isset($tx['visivel']) ? (int)$tx['visivel'] : 1;

                        $amount = Security::sanitizeAmount($tx['amount'] ?? 0.0, 0.0);
                        $desc = Security::sanitizeText($tx['description'] ?? '', 255, 'Lançamento Restaurado');
                        $date = Security::sanitizeDate($tx['date'] ?? null, date('Y-m-d'));
                        $dueDate = Security::sanitizeDate($tx['due_date'] ?? null);
                        $status = in_array($tx['status'] ?? '', ['pending', 'completed', 'cancelled'], true) ? $tx['status'] : 'completed';
                        $urgency = in_array($tx['urgency'] ?? '', ['baixa', 'media', 'alta'], true) ? $tx['urgency'] : 'media';

                        $stmtTx->execute([
                            $userId,
                            $accId,
                            $catId,
                            $catSnapshot,
                            $cardId,
                            $transferId,
                            $invId,
                            $goalId,
                            $tipoTx,
                            $amount,
                            $desc,
                            $date,
                            $dueDate,
                            $status,
                            $urgency,
                            $visivel
                        ]);

                        $newTxId = (int)$pdo->lastInsertId();
                        if ($oldTxId > 0) {
                            $mapTransactions[$oldTxId] = $newTxId;
                        }
                        $txCount++;
                    }
                }

                // 9. Restaura Parcelas (Installments) se presentes
                if (!empty($data['installments']) && is_array($data['installments'])) {
                    $stmtInst = $pdo->prepare("
                        INSERT INTO installments (transaction_id, credit_card_id, installment_number, total_installments, due_date, amount, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($data['installments'] as $inst) {
                        $oldTxId = (int)($inst['transaction_id'] ?? 0);
                        $oldCardId = (int)($inst['credit_card_id'] ?? 0);
                        $newTxId = $mapTransactions[$oldTxId] ?? null;
                        $newCardId = $mapCards[$oldCardId] ?? null;

                        if ($newTxId && $newCardId) {
                            $instNum = max(1, (int)($inst['installment_number'] ?? 1));
                            $totInst = max($instNum, (int)($inst['total_installments'] ?? $instNum));
                            $dueDate = Security::sanitizeDate($inst['due_date'] ?? null, date('Y-m-d'));
                            $instAmt = Security::sanitizeAmount($inst['amount'] ?? 0.0, 0.0);
                            $instStatus = in_array($inst['status'] ?? '', ['pending', 'completed'], true) ? $inst['status'] : 'pending';

                            $stmtInst->execute([$newTxId, $newCardId, $instNum, $totInst, $dueDate, $instAmt, $instStatus]);
                        }
                    }
                }

                $pdo->commit();
                $sucesso = "Backup restaurado com sucesso! Seus dados anteriores foram substituídos e {$txCount} movimentações foram recuperadas.";

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Erro ao restaurar backup: " . $e->getMessage());
                $erro = "Falha ao restaurar dados do arquivo. A integridade dos seus registros anteriores foi preservada sem alterações.";
            }
        }
    }
}

// Estatísticas atuais para exibir no painel de backup
$totaisAtuais = [];
$stmtCountAcc = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
$stmtCountAcc->execute([$userId]);
$totaisAtuais['contas'] = (int)$stmtCountAcc->fetchColumn();

$stmtCountCat = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ?");
$stmtCountCat->execute([$userId]);
$totaisAtuais['categorias'] = (int)$stmtCountCat->fetchColumn();

$stmtCountTx = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
$stmtCountTx->execute([$userId]);
$totaisAtuais['transacoes'] = (int)$stmtCountTx->fetchColumn();

$stmtCountCard = $pdo->prepare("SELECT COUNT(*) FROM credit_cards WHERE user_id = ?");
$stmtCountCard->execute([$userId]);
$totaisAtuais['cartoes'] = (int)$stmtCountCard->fetchColumn();

$stmtCountGoal = $pdo->prepare("SELECT COUNT(*) FROM goals WHERE user_id = ?");
$stmtCountGoal->execute([$userId]);
$totaisAtuais['metas'] = (int)$stmtCountGoal->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <title>Droma - Backup e Restauração</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
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

            <a href="backup?download=json" download class="btn-header-primary" style="justify-content:center; height:42px; text-decoration:none;">
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
                    Envie um arquivo <code>.json</code> de backup gerado pelo Droma para restaurar suas informações. Todos os lançamentos e cadastros atuais serão substituídos pelos do arquivo.
                </p>

                <form method="POST" action="backup" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:14px;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="acao" value="restaurar">

                    <label for="arquivo_backup" style="font-size:13px; font-weight:600; color:var(--color-text-secondary);">
                        Arquivo de Backup (.json):
                    </label>
                    <input type="file" name="arquivo_backup" id="arquivo_backup" accept=".json,application/json" required style="background:var(--color-surface-secondary); color:var(--color-text); padding:12px; border:1px dashed var(--color-border); border-radius:var(--radius-md); font-size:13px; cursor:pointer;">

                    <div style="font-size:12px; color:var(--color-gold); background:rgba(212, 175, 108, 0.08); border:1px solid rgba(212, 175, 108, 0.25); padding:10px 12px; border-radius:var(--radius-md); line-height:1.5;">
                        ⚠️ <strong>Atenção:</strong> A restauração irá substituir completamente as contas, categorias, cartões, metas e transações atuais deste usuário pelas informações do arquivo de backup.
                    </div>

                    <button type="submit" class="sair" style="height:42px; cursor:pointer; font-size:14px; margin-top:8px;" data-confirm="Atenção: Esta ação substituirá todas as suas contas, categorias, cartões e lançamentos atuais pelos dados do arquivo de backup. Deseja prosseguir com a substituição?" data-confirm-title="Substituir e Restaurar Dados?" data-confirm-btn="Sim, Substituir e Restaurar" data-confirm-type="danger">
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

