<?php
require_once "config/database.php";
require_once "src/Security.php";
require_once "src/PlanningService.php";
require_once "src/CategoryService.php";

$userId = Security::requireAuth();
CategoryService::ensureColumns($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add_expense");
    exit;
}

$origem = $_POST['origem'] ?? '';
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
       || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

$responderErro = static function(string $mensagem, int $statusCode = 422) use ($isAjax, $origem) {
    if ($isAjax) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'message' => $mensagem]);
        exit;
    }
    $destino = match($origem) {
        'calendario' => 'calendar',
        'extrato'    => 'statement',
        'cartoes'    => 'cards',
        default      => 'add_expense',
    };
    header("Location: {$destino}?erro=" . urlencode($mensagem));
    exit;
};

$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCsrf($csrfToken)) {
    $responderErro("Ação não autorizada ou sessão expirada. Por favor, atualize a página.", 403);
}
if (!Security::claimFormNonce($pdo, $userId, $_POST['form_nonce'] ?? null)) {
    $destino = match($_POST['origem'] ?? '') {
        'calendario' => 'calendar',
        'extrato'    => 'statement',
        'cartoes'    => 'cards',
        default      => './',
    };
    Security::setFlash('sucesso', 'Essa operação já tinha sido registrada.');
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'redirect_url' => $destino]);
        exit;
    }
    header("Location: {$destino}");
    exit;
}

$clientId = trim((string)($_POST['client_id'] ?? ''));
if (!preg_match('/^off_[A-Za-z0-9_]{1,60}$/', $clientId) || !Security::ensureClientSyncColumn($pdo)) {
    $clientId = null;
}
$jaGravado = false;
if ($clientId !== null) {
    $chk = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND client_id = ? LIMIT 1");
    $chk->execute([$userId, $clientId]);
    $jaGravado = (bool)$chk->fetchColumn();
}

$type           = $_POST['type'] ?? '';
$description    = trim($_POST['description'] ?? '');
$amountRaw      = trim($_POST['amount'] ?? '');
if (is_string($amountRaw)) {
    $amountRaw = str_replace(['R$', ' '], '', $amountRaw);
    if (str_contains($amountRaw, ',') && str_contains($amountRaw, '.')) {
        $amountRaw = str_replace('.', '', $amountRaw);
        $amountRaw = str_replace(',', '.', $amountRaw);
    } elseif (str_contains($amountRaw, ',')) {
        $amountRaw = str_replace(',', '.', $amountRaw);
    }
}
$accountId      = $_POST['account_id'] ?? '';
$categoryId     = $_POST['category_id'] ?? '';
$date           = Security::sanitizeDate($_POST['date'] ?? null, date('Y-m-d'));
$dueDate        = Security::sanitizeDate($_POST['due_date'] ?? null, null);
$status         = $_POST['status'] ?? 'pending';
$urgency        = $_POST['urgency'] ?? 'media';
$meioPagamento  = $_POST['meio_pagamento'] ?? 'conta';
$creditCardId   = !empty($_POST['credit_card_id']) ? (int)$_POST['credit_card_id'] : null;
$investmentId   = !empty($_POST['investment_id']) ? (int)$_POST['investment_id'] : null;
$installments   = max(1, min(24, (int)($_POST['installments'] ?? 1)));

if ($investmentId !== null && !Security::validateInvestmentOwnership($pdo, $investmentId, $userId)) {
    $investmentId = null;
}

// Urgência não se aplica a entradas; nunca aceite um valor divergente
if ($type === 'income') {
    $urgency = 'media';
    $meioPagamento = 'conta';
    $creditCardId = null;
    $installments = 1;
}

if (!in_array($type, ['income', 'expense'], true)
    || !is_numeric($amountRaw) || (float)$amountRaw <= 0
    || !ctype_digit((string)$categoryId)
    || empty($date)
    || !in_array($status, ['pending', 'completed', 'cancelled'], true)
    || !in_array($urgency, ['baixa', 'media', 'alta'], true)
) {
    $responderErro("Dados inválidos. Por favor, verifique os campos obrigatórios.");
}

$amount = (float) $amountRaw;
$categoryId = (int) $categoryId;

// Se for pagamento via Cartão de Crédito
$usaCartao = ($meioPagamento === 'cartao' && $type === 'expense' && $creditCardId !== null);

if ($usaCartao) {
    if (!Security::validateCreditCardOwnership($pdo, $creditCardId, $userId)) {
        $responderErro("Cartão de crédito selecionado inválido ou não autorizado.", 403);
    }

    // Se account_id não for informado, usa a primeira conta ativa do usuário como referência
    if (empty($accountId) || !ctype_digit((string)$accountId)) {
        $stmtFirstAcc = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $stmtFirstAcc->execute([$userId]);
        $accountId = (int)$stmtFirstAcc->fetchColumn();
    } else {
        $accountId = (int)$accountId;
    }
} else {
    $creditCardId = null;
    $installments = 1;
    $accountId = (int) $accountId;
}

// Validação estrita de propriedade (IDOR prevention)
if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
    $responderErro("Conta inválida ou não autorizada.", 403);
}

if (!Security::validateCategoryOwnership($pdo, $categoryId, $userId, $type)) {
    $responderErro("Categoria inválida, incompatível ou não autorizada.", 403);
}

// Função utilitária para cálculo da data de vencimento da fatura
function calcularVencimentoFatura(string $dataCompra, int $diaFechamento, int $diaVencimento, int $mesesAdicionais = 0): string {
    $ts = strtotime($dataCompra);
    $diaCompra = (int)date('j', $ts);
    $mes = (int)date('n', $ts);
    $ano = (int)date('Y', $ts);

    if ($diaCompra >= $diaFechamento) {
        $mesesAdicionais += 1;
    }
    if ($diaVencimento < $diaFechamento) {
        $mesesAdicionais += 1;
    }

    $tsVencimento = strtotime("+{$mesesAdicionais} month", mktime(0, 0, 0, $mes, 1, $ano));
    $anoVenc = (int)date('Y', $tsVencimento);
    $mesVenc = (int)date('m', $tsVencimento);
    $diasNoMes = (int)date('t', $tsVencimento);
    $diaReal = min($diaVencimento, $diasNoMes);

    return sprintf('%04d-%02d-%02d', $anoVenc, $mesVenc, $diaReal);
}

try {
    if ($jaGravado) {
        // Já gravado anteriormente com o mesmo client_id; segue para o redirect
    } elseif ($usaCartao) {
        // Busca dias de fechamento e vencimento do cartão
        $stmtCard = $pdo->prepare("SELECT closing_day, due_day FROM credit_cards WHERE id = ?");
        $stmtCard->execute([$creditCardId]);
        $cardInfo = $stmtCard->fetch(PDO::FETCH_ASSOC);
        $closingDay = (int)($cardInfo['closing_day'] ?? 1);
        $dueDay     = (int)($cardInfo['due_day'] ?? 10);

        $pdo->beginTransaction();

        $vencPrimeiraFatura = calcularVencimentoFatura($date, $closingDay, $dueDay, 0);

        // Busca o nome da categoria para snapshot imutável contra reciclagem de slots
        $stmtCatName = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $stmtCatName->execute([$categoryId]);
        $catSnapshot = (string)($stmtCatName->fetchColumn() ?: '');

        if ($installments <= 1) {
            // Compra à vista no cartão
            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                    (user_id, account_id, credit_card_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, client_id, visivel)
                 VALUES (?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?, ?, 1)"
            );
            $stmt->execute([
                $userId, $accountId, $creditCardId, $categoryId, $catSnapshot, $amount,
                $description, $date, $vencPrimeiraFatura, $urgency, $clientId
            ]);
        } else {
            // Compra parcelada no cartão
            $descPrincipal = !empty($description) ? "{$description} (Parcelado em {$installments}x)" : "Compra parcelada em {$installments}x";
            
            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                    (user_id, account_id, credit_card_id, investment_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, client_id, visivel)
                 VALUES (?, ?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?, ?, 1)"
            );
            $stmt->execute([
                $userId, $accountId, $creditCardId, $investmentId, $categoryId, $catSnapshot, $amount,
                $descPrincipal, $date, $vencPrimeiraFatura, $urgency, $clientId
            ]);
            $transacaoId = (int)$pdo->lastInsertId();

            // Cálculo das parcelas
            $valorBase = round($amount / $installments, 2);
            $diferencaCentavos = round($amount - ($valorBase * $installments), 2);

            $stmtParc = $pdo->prepare(
                "INSERT INTO installments
                    (transaction_id, credit_card_id, installment_number, total_installments, due_date, amount, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            );

            for ($num = 1; $num <= $installments; $num++) {
                $valParc = $valorBase;
                if ($num === 1) {
                    $valParc += $diferencaCentavos;
                }
                $vencParc = calcularVencimentoFatura($date, $closingDay, $dueDay, $num - 1);
                $stmtParc->execute([$transacaoId, $creditCardId, $num, $installments, $vencParc, $valParc]);
            }
        }

        $pdo->commit();

    } else {
        // Lançamento normal em conta bancária
        if (empty($dueDate)) {
            $dueDate = null;
        }

        // Busca o nome da categoria para snapshot imutável contra reciclagem de slots
        $stmtCatName = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $stmtCatName->execute([$categoryId]);
        $catSnapshot = (string)($stmtCatName->fetchColumn() ?: '');

        $stmt = $pdo->prepare(
            "INSERT INTO transactions
                (user_id, account_id, credit_card_id, investment_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, client_id, visivel)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            $userId, $accountId, $investmentId, $categoryId, $catSnapshot, $type, $amount,
            $description, $date, $dueDate, $status, $urgency, $clientId
        ]);
        $newTxId = (int)$pdo->lastInsertId();
        if ($newTxId > 0 && !empty($_POST['tags'])) {
            PlanningService::salvarTagsESplits($pdo, $userId, $newTxId, (string)$_POST['tags']);
        }
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getCode() === '23000' && $clientId !== null) {
        $jaGravado = true; // outra requisição com o mesmo client_id venceu; segue para o redirect
    } else {
        error_log("Erro ao salvar lançamento: " . $e->getMessage());
        $responderErro("Não foi possível salvar o lançamento. Ocorreu um erro interno no banco de dados.", 500);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro ao salvar lançamento: " . $e->getMessage());
    $responderErro("Não foi possível salvar o lançamento. Ocorreu um erro interno no banco de dados.", 500);
}

$flashMsg = ($origem === 'cartoes' || $usaCartao)
    ? 'Compra no cartão registrada com sucesso!'
    : 'Lançamento salvo com sucesso!';
Security::setFlash('sucesso', $flashMsg);

if ($origem === 'calendario') {
    $refDate = $dueDate ?: $date;
    $m = date('n', strtotime($refDate));
    $y = date('Y', strtotime($refDate));
    $redirectUrl = "calendar?mes=$m&ano=$y";
} elseif ($origem === 'extrato') {
    $redirectUrl = "statement";
} elseif ($origem === 'cartoes' || $usaCartao) {
    $redirectUrl = "cards";
} else {
    $redirectUrl = "./";
}

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'      => true,
        'message'      => 'Lançamento salvo com sucesso!',
        'redirect_url' => $redirectUrl
    ]);
    exit;
}

header("Location: {$redirectUrl}");
exit;


