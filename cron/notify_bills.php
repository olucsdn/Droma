<?php
/**
 * Cron Job: Notificação Diária de Contas a Pagar por E-mail
 * Droma Financy — Rotina Automática
 * 
 * Execução:
 * 1. Via CLI (linha de comando / cron no servidor):
 *    php cron/notify_bills.php
 * 
 * 2. Via Webhook HTTP (cron-job.org / Render Webhook):
 *    GET https://seu-app.onrender.com/cron/notify_bills.php?token=SEU_TOKEN_SECRETO
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Mailer.php';

// 1. Verificação de Autenticação e Segurança
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    
    $secretConfigurado = getenv('CRON_SECRET');
    if ($secretConfigurado === false || $secretConfigurado === '') {
        $secretConfigurado = $_ENV['CRON_SECRET'] ?? '';
    }
    $secretConfigurado = is_string($secretConfigurado) ? trim($secretConfigurado) : '';

    if ($secretConfigurado === '') {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Cron secret not configured'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokenRecebido = (string)($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');

    if (!hash_equals($secretConfigurado, $tokenRecebido)) {
        http_response_code(403);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Acesso negado. Token de execução inválido ou não informado.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 2. Garante a tabela de idempotência para nunca enviar e-mails duplicados no mesmo dia
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `daily_notifications` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(50) NOT NULL,
            `date_sent` DATE NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_user_type_date` (`user_id`, `type`, `date_sent`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Throwable $e) {
    error_log("[Cron Notify Bills] Erro ao criar tabela daily_notifications: " . $e->getMessage());
}

$hoje = date('Y-m-d');
$usuariosNotificados = 0;
$totalContasHoje = 0;
$logs = [];

$forceTest = isset($_GET['force']) && in_array((string)$_GET['force'], ['1', 'true'], true);
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// 2.5 Processa recorrências automáticas vencidas (gera pendente na next_date e avança)
try {
    $stmtRecIds = $pdo->query("SELECT id FROM recurring_transactions WHERE active = 1 AND next_date <= CURDATE()");
    foreach ($stmtRecIds->fetchAll(PDO::FETCH_COLUMN) as $recId) {
        try {
            $pdo->beginTransaction();
            $stR = $pdo->prepare("SELECT * FROM recurring_transactions WHERE id = ? AND active = 1 AND next_date <= CURDATE() FOR UPDATE");
            $stR->execute([(int)$recId]);
            $rec = $stR->fetch(PDO::FETCH_ASSOC);
            if (!$rec) {
                $pdo->rollBack();
                continue;
            }
            $stCat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $stCat->execute([(int)$rec['category_id']]);
            $catSnap = (string)($stCat->fetchColumn() ?: '');

            $pdo->prepare(
                "INSERT INTO transactions
                    (user_id, account_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, visivel)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'media', 1)"
            )->execute([
                (int)$rec['user_id'], (int)$rec['account_id'], (int)$rec['category_id'], $catSnap,
                $rec['type'], $rec['amount'], $rec['description'] . ' (Recorrente)',
                $rec['next_date'], $rec['next_date']
            ]);

            if ($rec['frequency'] === 'monthly') {
                $base = new DateTime($rec['next_date']);
                $base->modify('first day of next month');
                $diaAlvo = (int)($rec['day_of_month'] ?? (int)(new DateTime($rec['next_date']))->format('j'));
                $dia = min(max(1, $diaAlvo), (int)$base->format('t'));
                $novoNext = $base->format('Y-m-') . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
            } elseif ($rec['frequency'] === 'weekly') {
                $novoNext = date('Y-m-d', strtotime('+1 week', strtotime($rec['next_date'])));
            } else {
                $novoNext = date('Y-m-d', strtotime('+1 year', strtotime($rec['next_date'])));
            }

            $upd = $pdo->prepare("UPDATE recurring_transactions SET next_date = ? WHERE id = ? AND next_date = ?");
            $upd->execute([$novoNext, (int)$rec['id'], $rec['next_date']]);
            if ($upd->rowCount() === 1) {
                $pdo->commit();
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $eRec) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("[Cron Recurring Error] ID {$recId}: " . $eRec->getMessage());
        }
    }
} catch (Throwable $eRecOuter) {
    error_log("[Cron Recurring Outer] " . $eRecOuter->getMessage());
}

// 3. Busca usuários com e-mail cadastrado
if ($targetUserId > 0) {
    $stmtUsers = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND email IS NOT NULL AND email != ''");
    $stmtUsers->execute([$targetUserId]);
} else {
    $stmtUsers = $pdo->query("SELECT id, name, email FROM users WHERE email IS NOT NULL AND email != '' ORDER BY id ASC");
}
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

foreach ($usuarios as $user) {
    $uId = (int)$user['id'];
    $uName = $user['name'] ?: 'Usuário';
    $uEmail = $user['email'];

    // Verifica se já enviamos notificação para este usuário na data de hoje (Idempotência)
    if (!$forceTest) {
        $stmtCheck = $pdo->prepare("SELECT id FROM daily_notifications WHERE user_id = ? AND type = 'bills_due' AND date_sent = ?");
        $stmtCheck->execute([$uId, $hoje]);
        if ($stmtCheck->fetch()) {
            continue;
        }
    }

    // 4. Busca despesas à vista pendentes vencendo hoje
    $stmtTxHoje = $pdo->prepare("
        SELECT t.id, t.description, t.amount, t.due_date, 
               COALESCE(c.name, t.category_name, 'Despesas') AS categoria, 
               COALESCE(a.name, 'Conta Bancária') AS conta
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
        LEFT JOIN accounts a ON a.id = t.account_id
        WHERE t.user_id = ? AND t.type = 'expense' AND t.status = 'pending' AND t.visivel = 1
          AND t.id NOT IN (SELECT transaction_id FROM installments)
          AND t.due_date = CURDATE()
    ");
    $stmtTxHoje->execute([$uId]);
    $contasAvistaHoje = $stmtTxHoje->fetchAll(PDO::FETCH_ASSOC);

    // 5. Busca parcelas de cartão de crédito pendentes vencendo hoje
    $stmtParcHoje = $pdo->prepare("
        SELECT i.id, 
               CONCAT(COALESCE(t.description, 'Compra'), ' (', i.installment_number, '/', i.total_installments, 'x)') AS description,
               i.amount, i.due_date, 
               'Cartão de Crédito' AS categoria, 
               COALESCE(c.name, 'Cartão') AS conta
        FROM installments i
        JOIN transactions t ON t.id = i.transaction_id
        JOIN credit_cards c ON c.id = i.credit_card_id
        WHERE c.user_id = ? AND i.status = 'pending' AND t.visivel = 1
          AND i.due_date = CURDATE()
    ");
    $stmtParcHoje->execute([$uId]);
    $parcelasHoje = $stmtParcHoje->fetchAll(PDO::FETCH_ASSOC);

    $contasHoje = array_merge($contasAvistaHoje, $parcelasHoje);

    // Se não há contas vencendo hoje, não incomoda o usuário
    if (empty($contasHoje)) {
        if ($forceTest) {
            $logs[] = "Usuário ID {$uId} ({$uEmail}): Nenhuma conta pendente vencendo hoje ({$hoje}). Crie uma despesa com vencimento hoje e status 'Pendente' para receber o alerta.";
        }
        continue;
    }

    // 6. Busca contas em atraso recente (últimos 30 dias) para alerta complementar
    $stmtTxAtraso = $pdo->prepare("
        SELECT t.id, t.description, t.amount, t.due_date, 
               COALESCE(c.name, t.category_name, 'Despesas') AS categoria, 
               COALESCE(a.name, 'Conta Bancária') AS conta
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id AND c.visivel = 1
        LEFT JOIN accounts a ON a.id = t.account_id
        WHERE t.user_id = ? AND t.type = 'expense' AND t.status = 'pending' AND t.visivel = 1
          AND t.id NOT IN (SELECT transaction_id FROM installments)
          AND t.due_date < CURDATE() AND t.due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $stmtTxAtraso->execute([$uId]);
    $contasAvistaAtraso = $stmtTxAtraso->fetchAll(PDO::FETCH_ASSOC);

    $stmtParcAtraso = $pdo->prepare("
        SELECT i.id, 
               CONCAT(COALESCE(t.description, 'Compra'), ' (', i.installment_number, '/', i.total_installments, 'x)') AS description,
               i.amount, i.due_date, 
               'Cartão de Crédito' AS categoria, 
               COALESCE(c.name, 'Cartão') AS conta
        FROM installments i
        JOIN transactions t ON t.id = i.transaction_id
        JOIN credit_cards c ON c.id = i.credit_card_id
        WHERE c.user_id = ? AND i.status = 'pending' AND t.visivel = 1
          AND i.due_date < CURDATE() AND i.due_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ");
    $stmtParcAtraso->execute([$uId]);
    $parcelasAtraso = $stmtParcAtraso->fetchAll(PDO::FETCH_ASSOC);

    $contasAtrasadas = array_merge($contasAvistaAtraso, $parcelasAtraso);

    // 7. Envia o e-mail de lembrete diário
    $enviado = Mailer::sendBillDueReminder($uEmail, $uName, $contasHoje, $contasAtrasadas);

    if ($enviado) {
        if (!$forceTest) {
            // Registra o envio para evitar disparos repetidos hoje apenas na rotina automática
            $stmtInsLog = $pdo->prepare("INSERT IGNORE INTO daily_notifications (user_id, type, date_sent) VALUES (?, 'bills_due', ?)");
            $stmtInsLog->execute([$uId, $hoje]);
        }

        $usuariosNotificados++;
        $totalContasHoje += count($contasHoje);
        $logs[] = "Usuário ID {$uId} ({$uEmail}): " . count($contasHoje) . " conta(s) enviadas com sucesso." . ($forceTest ? " [Modo Teste - Forçado]" : "");
    } else {
        $logs[] = "Usuário ID {$uId} ({$uEmail}): Falha no disparo de e-mail via Mailer.";
    }

    // 7.5 Alerta de orçamento por categoria (80% e 100%)
    try {
        $stChkBudget = $pdo->prepare("SELECT id FROM daily_notifications WHERE user_id = ? AND type = 'budget_alert' AND date_sent = ?");
        $stChkBudget->execute([$uId, $hoje]);
        if ($forceTest || !$stChkBudget->fetch()) {
            $stBud = $pdo->prepare(
                "SELECT c.name, c.budget_limit,
                        COALESCE(SUM(CASE WHEN t.visivel = 1 AND t.status = 'completed' AND t.transfer_id IS NULL
                                           AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
                                          THEN t.amount ELSE 0 END), 0) AS gasto
                 FROM categories c
                 LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = c.user_id AND t.type = 'expense'
                 WHERE c.user_id = ? AND c.visivel = 1 AND c.type = 'expense' AND c.budget_limit IS NOT NULL AND c.budget_limit > 0
                 GROUP BY c.id, c.name, c.budget_limit
                 HAVING gasto >= (c.budget_limit * 0.8)"
            );
            $stBud->execute([$uId]);
            $alertasBud = $stBud->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($alertasBud)) {
                $itensHtml = '';
                foreach ($alertasBud as $ab) {
                    $pct = round(((float)$ab['gasto'] / (float)$ab['budget_limit']) * 100, 1);
                    $itensHtml .= '<li><strong>' . htmlspecialchars($ab['name']) . '</strong>: R$ '
                        . number_format((float)$ab['gasto'], 2, ',', '.') . ' de R$ '
                        . number_format((float)$ab['budget_limit'], 2, ',', '.') . " ({$pct}%)</li>";
                }
                $htmlBud = "<p>Olá, " . htmlspecialchars($uName) . ".</p><p>As seguintes categorias atingiram 80% ou mais do orçamento mensal:</p><ul>{$itensHtml}</ul>";
                if (Mailer::send($uEmail, 'Droma — Alerta de Orçamento Mensal', $htmlBud) && !$forceTest) {
                    $pdo->prepare("INSERT IGNORE INTO daily_notifications (user_id, type, date_sent) VALUES (?, 'budget_alert', ?)")
                        ->execute([$uId, $hoje]);
                }
            }
        }
    } catch (Throwable $eBud) {
        error_log("[Cron Budget Alert Error] User {$uId}: " . $eBud->getMessage());
    }

    // 7.6 Relatório mensal por e-mail (enviado no dia 1 referente ao mês anterior)
    if (date('j') === '1' || $forceTest) {
        try {
            $stChkRep = $pdo->prepare("SELECT id FROM daily_notifications WHERE user_id = ? AND type = 'monthly_report' AND date_sent = ?");
            $stChkRep->execute([$uId, $hoje]);
            if ($forceTest || !$stChkRep->fetch()) {
                $inicioMesAnt = date('Y-m-01', strtotime('first day of last month'));
                $fimMesAnt    = date('Y-m-t', strtotime('last day of last month'));
                $stRes = $pdo->prepare(
                    "SELECT
                        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS receitas,
                        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS despesas
                     FROM transactions
                     WHERE user_id = ? AND visivel = 1 AND status = 'completed' AND transfer_id IS NULL
                       AND date BETWEEN ? AND ?"
                );
                $stRes->execute([$uId, $inicioMesAnt, $fimMesAnt]);
                $resMes = $stRes->fetch(PDO::FETCH_ASSOC) ?: ['receitas' => 0, 'despesas' => 0];
                $recAnt = (float)$resMes['receitas'];
                $despAnt = (float)$resMes['despesas'];
                if ($recAnt > 0 || $despAnt > 0) {
                    $saldoAnt = $recAnt - $despAnt;
                    $mesLabel = date('m/Y', strtotime($inicioMesAnt));
                    $htmlRep = "<p>Olá, " . htmlspecialchars($uName) . ".</p>"
                        . "<p>Resumo financeiro de <strong>{$mesLabel}</strong>:</p>"
                        . "<ul>"
                        . "<li>Entradas: <strong>R$ " . number_format($recAnt, 2, ',', '.') . "</strong></li>"
                        . "<li>Saídas: <strong>R$ " . number_format($despAnt, 2, ',', '.') . "</strong></li>"
                        . "<li>Resultado do mês: <strong>R$ " . number_format($saldoAnt, 2, ',', '.') . "</strong></li>"
                        . "</ul>";
                    if (Mailer::send($uEmail, "Droma — Resumo Mensal ({$mesLabel})", $htmlRep) && !$forceTest) {
                        $pdo->prepare("INSERT IGNORE INTO daily_notifications (user_id, type, date_sent) VALUES (?, 'monthly_report', ?)")
                            ->execute([$uId, $hoje]);
                    }
                }
            }
        } catch (Throwable $eRep) {
            error_log("[Cron Monthly Report Error] User {$uId}: " . $eRep->getMessage());
        }
    }
}

// Limpa nonces de formulário expirados (> 2 dias)
try {
    $pdo->exec("DELETE FROM form_nonces WHERE created_at < NOW() - INTERVAL 2 DAY");
} catch (Throwable $eNonce) {
    // Tabela pode ainda não existir antes do primeiro POST autenticado
}

// 8. Resposta / Relatório da Execução
// Proteção de Privacidade (LGPD): Em HTTP, omite 'details' para impedir vazamento de IDs e e-mails de usuários
$resultado = [
    'status'              => 'success',
    'date'                => $hoje,
    'force_mode'          => $forceTest,
    'users_checked'       => count($usuarios),
    'notifications_sent'  => $usuariosNotificados,
    'total_bills_notified'=> $totalContasHoje
];

if ($isCli) {
    echo "=== Droma Financy: Relatório de Notificações Diárias ===\n";
    echo "Data: {$hoje}\n";
    echo "Usuários verificados: " . count($usuarios) . "\n";
    echo "E-mails enviados: {$usuariosNotificados}\n";
    echo "Total de contas notificadas: {$totalContasHoje}\n";
    foreach ($logs as $l) {
        echo "- {$l}\n";
    }
    echo "=========================================================\n";
} else {
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
