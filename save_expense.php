<?php
require_once "config/database.php";
require_once "src/Security.php";

$userId = Security::requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add_expense");
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCsrf($csrfToken)) {
    die("Ação não autorizada ou sessão expirada. Por favor, atualize a página.");
}

$type           = $_POST['type'] ?? '';
$description    = trim($_POST['description'] ?? '');
$amountRaw      = str_replace(',', '.', trim($_POST['amount'] ?? ''));
$accountId      = $_POST['account_id'] ?? '';
$categoryId     = $_POST['category_id'] ?? '';
$date           = $_POST['date'] ?? '';
$dueDate        = $_POST['due_date'] ?? null;
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
    die("Dados inválidos. Por favor, verifique os campos obrigatórios.");
}

$amount = (float) $amountRaw;
$categoryId = (int) $categoryId;

// Se for pagamento via Cartão de Crédito
$usaCartao = ($meioPagamento === 'cartao' && $type === 'expense' && $creditCardId !== null);

if ($usaCartao) {
    if (!Security::validateCreditCardOwnership($pdo, $creditCardId, $userId)) {
        die("Cartão de crédito selecionado inválido ou não autorizado.");
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
    die("Conta inválida ou não autorizada.");
}

if (!Security::validateCategoryOwnership($pdo, $categoryId, $userId, $type)) {
    die("Categoria inválida, incompatível ou não autorizada.");
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
    if ($usaCartao) {
        // Busca dias de fechamento e vencimento do cartão
        $stmtCard = $pdo->prepare("SELECT closing_day, due_day FROM credit_cards WHERE id = ?");
        $stmtCard->execute([$creditCardId]);
        $cardInfo = $stmtCard->fetch(PDO::FETCH_ASSOC);
        $closingDay = (int)($cardInfo['closing_day'] ?? 1);
        $dueDay     = (int)($cardInfo['due_day'] ?? 10);

        $pdo->beginTransaction();

        $vencPrimeiraFatura = calcularVencimentoFatura($date, $closingDay, $dueDay, 0);

        if ($installments <= 1) {
            // Compra à vista no cartão
            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                    (user_id, account_id, credit_card_id, category_id, type, amount, description, date, due_date, status, urgency)
                 VALUES (?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?)"
            );
            $stmt->execute([
                $userId, $accountId, $creditCardId, $categoryId, $amount,
                $description, $date, $vencPrimeiraFatura, $urgency
            ]);
        } else {
            // Compra parcelada no cartão
            $descPrincipal = !empty($description) ? "{$description} (Parcelado em {$installments}x)" : "Compra parcelada em {$installments}x";
            
            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                    (user_id, account_id, credit_card_id, investment_id, category_id, type, amount, description, date, due_date, status, urgency)
                 VALUES (?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?)"
            );
            $stmt->execute([
                $userId, $accountId, $creditCardId, $investmentId, $categoryId, $amount,
                $descPrincipal, $date, $vencPrimeiraFatura, $urgency
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

        $stmt = $pdo->prepare(
            "INSERT INTO transactions
                (user_id, account_id, credit_card_id, investment_id, category_id, type, amount, description, date, due_date, status, urgency)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $userId, $accountId, $investmentId, $categoryId, $type, $amount,
            $description, $date, $dueDate, $status, $urgency
        ]);
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Erro ao salvar lançamento: " . $e->getMessage());
    die("Não foi possível salvar o lançamento.");
}

$origem = $_POST['origem'] ?? '';
if ($origem === 'calendario') {
    $refDate = $dueDate ?: $date;
    $m = date('n', strtotime($refDate));
    $y = date('Y', strtotime($refDate));
    header("Location: calendar?mes=$m&ano=$y&sucesso=" . urlencode("Lançamento salvo com sucesso!"));
} elseif ($origem === 'extrato') {
    header("Location: statement?sucesso=" . urlencode("Lançamento salvo com sucesso!"));
} elseif ($origem === 'cartoes' || $usaCartao) {
    header("Location: cards?sucesso=" . urlencode("Compra no cartão registrada com sucesso!"));
} else {
    header("Location: ./?sucesso=" . urlencode("Lançamento salvo com sucesso!"));
}
exit;


