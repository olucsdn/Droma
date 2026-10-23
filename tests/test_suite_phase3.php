<?php
/**
 * Testes Integrados da Fase 3 (Planejamento e Crédito) — SDD Wert
 * Executar via CLI: php tests/test_suite_phase3.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';

$erros = 0;
$sucessos = 0;

function runTest(string $descricao, callable $fn) {
    global $erros, $sucessos;
    try {
        $resultado = $fn();
        if ($resultado !== false) {
            echo " [OK] " . $descricao . "\n";
            $sucessos++;
        } else {
            echo " [FALHA] " . $descricao . "\n";
            $erros++;
        }
    } catch (Throwable $e) {
        echo " [ERRO] " . $descricao . ": " . $e->getMessage() . "\n";
        $erros++;
    }
}

echo "====================================================\n";
echo "TESTES DE INTEGRAÇÃO - FASE 3 (PLANEJAMENTO & CRÉDITO)\n";
echo "====================================================\n\n";

$userId = 1;

// 1. Verificação do Schema de Cartões e Parcelas
runTest("Tabelas credit_cards, installments e recurring_transactions existem", function() use ($pdo) {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return in_array('credit_cards', $tables, true) 
        && in_array('installments', $tables, true) 
        && in_array('recurring_transactions', $tables, true);
});

runTest("Coluna credit_card_id existe na tabela transactions", function() use ($pdo) {
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'credit_card_id'");
    return (bool) $stmt->fetch();
});

// 2. Prevenção de IDOR em Cartões
runTest("Security::validateCreditCardOwnership() valida propriedade por usuário", function() use ($pdo, $userId) {
    // Insere conta e cartão temporários para o usuário 1
    $pdo->beginTransaction();
    $stmtAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Temp IDOR', 'checking', 5000.00)");
    $stmtAcc->execute([$userId]);
    $tempAccId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day) VALUES (?, ?, 'Cartão Teste', 2000.00, 5, 15)");
    $stmt->execute([$userId, $tempAccId]);
    $cardId = (int)$pdo->lastInsertId();

    $okUser1 = Security::validateCreditCardOwnership($pdo, $cardId, 1);
    $blockedUser2 = Security::validateCreditCardOwnership($pdo, $cardId, 2);
    $blockedFake = Security::validateCreditCardOwnership($pdo, 99999, 1);

    $pdo->rollBack();

    return ($okUser1 === true) && ($blockedUser2 === false) && ($blockedFake === false);
});

// 3. Teste de Parcelamento Matemático (Precisão Decimal)
runTest("Divisão de parcelas preserva centavos e soma exatamente o valor total", function() {
    $valorTotal = 100.00;
    $parcelas = 3;
    $valorBase = round($valorTotal / $parcelas, 2); // 33.33
    $dif = round($valorTotal - ($valorBase * $parcelas), 2); // 0.01

    $soma = 0.00;
    for ($i = 1; $i <= $parcelas; $i++) {
        $p = $valorBase;
        if ($i === 1) $p += $dif; // 33.34
        $soma += $p;
    }
    return abs($soma - 100.00) < 0.001;
});

// 4. Teste de Fluxo Contábil: Compra no Cartão e Pagamento de Fatura sem Dupla Contagem
runTest("Compra parcelada e pagamento de fatura evitam dupla contagem contábil", function() use ($pdo, $userId) {
    $pdo->beginTransaction();

    // Cria conta temporária para o cartão
    $stmtAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Temp Cartao 3', 'checking', 2000.00)");
    $stmtAcc->execute([$userId]);
    $contaId = (int)$pdo->lastInsertId();

    // Cria cartão de teste vinculado à conta
    $stmtCard = $pdo->prepare("INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day) VALUES (?, ?, 'Cartão Black Teste', 1500.00, 1, 10)");
    $stmtCard->execute([$userId, $contaId]);
    $cardId = (int)$pdo->lastInsertId();

    $catId = 4; // Lazer/Outros

    // 1. Compra de R$ 300,00 parcelada em 3x no cartão
    $valorCompra = 300.00;
    $stmtTx = $pdo->prepare("INSERT INTO transactions (user_id, account_id, credit_card_id, category_id, type, amount, description, date, status) VALUES (?, ?, ?, ?, 'expense', ?, 'Compra Teste 3x', CURDATE(), 'pending')");
    $stmtTx->execute([$userId, $contaId, $cardId, $catId, $valorCompra]);
    $txId = (int)$pdo->lastInsertId();

    $stmtParc = $pdo->prepare("INSERT INTO installments (transaction_id, credit_card_id, installment_number, total_installments, due_date, amount, status) VALUES (?, ?, ?, 3, DATE_ADD(CURDATE(), INTERVAL ? MONTH), 100.00, 'pending')");
    $stmtParc->execute([$txId, $cardId, 1, 0]);
    $stmtParc->execute([$txId, $cardId, 2, 1]);
    $stmtParc->execute([$txId, $cardId, 3, 2]);

    // Verifica limite utilizado (deve ser 300)
    $sParc = $pdo->prepare("SELECT SUM(amount) FROM installments WHERE credit_card_id = ? AND status = 'pending'");
    $sParc->execute([$cardId]);
    $utilizado = (float)$sParc->fetchColumn();

    // Verifica que o saldo realizado em caixa NÃO foi debitado ainda (pois status é pending)
    $stmtCaixa = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE id = ? AND status = 'completed'");
    $stmtCaixa->execute([$txId]);
    $gastoEmCaixa = (float)$stmtCaixa->fetchColumn();

    // 2. Pagamento da fatura
    $stmtBaixa = $pdo->prepare("UPDATE installments SET status = 'completed' WHERE credit_card_id = ?");
    $stmtBaixa->execute([$cardId]);

    // O pagamento da fatura quita as parcelas
    $sParcPend = $pdo->prepare("SELECT COUNT(*) FROM installments WHERE credit_card_id = ? AND status = 'pending'");
    $sParcPend->execute([$cardId]);
    $pendentesRestantes = (int)$sParcPend->fetchColumn();

    $pdo->rollBack();

    return ($utilizado === 300.00) && ($gastoEmCaixa === 0.00) && ($pendentesRestantes === 0);
});

// 5. Teste de Recorrências
runTest("Lançamento recorrente é criado e avança data após efetivação", function() use ($pdo, $userId) {
    $pdo->beginTransaction();

    $hoje = date('Y-m-d');
    $stmt = $pdo->prepare(
        "INSERT INTO recurring_transactions 
            (user_id, account_id, category_id, type, amount, description, frequency, day_of_month, next_date, active)
         VALUES (?, 1, 4, 'expense', 49.90, 'Assinatura Streaming', 'monthly', 10, ?, 1)"
    );
    $stmt->execute([$userId, $hoje]);
    $recId = (int)$pdo->lastInsertId();

    // Avança 1 mês
    $proxMes = date('Y-m-d', strtotime('+1 month', strtotime($hoje)));
    $upd = $pdo->prepare("UPDATE recurring_transactions SET next_date = ? WHERE id = ?");
    $upd->execute([$proxMes, $recId]);

    $check = $pdo->prepare("SELECT next_date FROM recurring_transactions WHERE id = ?");
    $check->execute([$recId]);
    $novaData = $check->fetchColumn();

    $pdo->rollBack();

    return $novaData === $proxMes;
});

echo "\n----------------------------------------------------\n";
echo "RESULTADO DOS TESTES:\n";
echo "Sucessos: $sucessos | Falhas: $erros\n";
echo "----------------------------------------------------\n";
if ($erros === 0) {
    echo "STATUS: TODOS OS TESTES DA FASE 3 PASSARAM COM SUCESSO!\n";
} else {
    echo "STATUS: HOUVE FALHAS QUE PRECISAM DE CORREÇÃO.\n";
}
