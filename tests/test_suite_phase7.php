<?php
/**
 * Suíte de Testes de Integração Automatizados - Fase 7 (Investimentos & Hierarquia Conta-Cartão)
 * Metodologia SDD (Spec-Driven Development) — Projeto Wert
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';

$testUserEmail = 'sdd_phase7_' . time() . '@wert.test';
$passHash = password_hash('SenhaSegura123!', PASSWORD_DEFAULT);

echo "====================================================\n";
echo "TESTES DE INTEGRAÇÃO - FASE 7 (INVESTIMENTOS & HIERARQUIA CONTA-CARTÃO)\n";
echo "====================================================\n\n";

$passou = 0;
$falhas = 0;

function assertTest(bool $condition, string $description, ?string $detail = null): void {
    global $passou, $falhas;
    if ($condition) {
        $passou++;
        echo " [OK] {$description}\n";
    } else {
        $falhas++;
        echo " [FALHA] {$description}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

try {
    // Cria usuário de teste
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES ('Tester Phase 7', ?, ?)");
    $stmt->execute([$testUserEmail, $passHash]);
    $userId = (int) $pdo->lastInsertId();

    // Cria 2 contas bancárias para o teste
    $stmtAcc1 = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Corrente Alpha', 'checking', 20000.00)");
    $stmtAcc1->execute([$userId]);
    $acc1Id = (int) $pdo->lastInsertId();

    $stmtAcc2 = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Poupança Beta', 'savings', 5000.00)");
    $stmtAcc2->execute([$userId]);
    $acc2Id = (int) $pdo->lastInsertId();

    // 1. Testa existência das estruturas no banco
    $colCardAcc = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'credit_cards' AND column_name = 'account_id'")->fetchColumn();
    assertTest($colCardAcc === 1, "Coluna credit_cards.account_id existe no banco");

    $tbInvest = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'investments'")->fetchColumn();
    assertTest($tbInvest === 1, "Tabela investments existe no banco");

    $colTxInv = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'investment_id'")->fetchColumn();
    assertTest($colTxInv === 1, "Coluna transactions.investment_id existe no banco");

    // 2. Testa criação de cartão vinculado à Conta Alpha (Classe-Subclasse)
    assertTest(Security::validateAccountHasNoCard($pdo, $acc1Id), "Conta Alpha inicialmente não possui cartão vinculado");

    $stmtCard = $pdo->prepare("
        INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day, color)
        VALUES (?, ?, 'Cartão Alpha Black', 15000.00, 5, 15, '#D4AF6C')
    ");
    $stmtCard->execute([$userId, $acc1Id]);
    $cardId = (int)$pdo->lastInsertId();

    assertTest($cardId > 0, "Cartão de crédito criado e vinculado com sucesso à Conta Alpha");
    assertTest(!Security::validateAccountHasNoCard($pdo, $acc1Id), "Security::validateAccountHasNoCard detecta que a Conta Alpha agora possui cartão");

    // 3. Testa regra de negócio: 1 cartão de crédito por conta bancária (rejeição de duplicidade)
    $bloqueouDuplicidade = false;
    try {
        $stmtCardDup = $pdo->prepare("
            INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day)
            VALUES (?, ?, 'Cartão Alpha Segundo', 5000.00, 1, 10)
        ");
        $stmtCardDup->execute([$userId, $acc1Id]);
    } catch (PDOException $e) {
        $bloqueouDuplicidade = true;
    }
    assertTest($bloqueouDuplicidade, "Regra de Unicidade: Banco rejeita cadastrar um segundo cartão na mesma conta bancária");

    // 4. Testa regra de negócio: Bloqueio de exclusão de conta com cartão associado (integridade de subclasse)
    $bloqueouExclusaoConta = false;
    try {
        $delAcc = $pdo->prepare("DELETE FROM accounts WHERE id = ? AND user_id = ?");
        $delAcc->execute([$acc1Id, $userId]);
    } catch (PDOException $e) {
        $bloqueouExclusaoConta = true;
    }
    assertTest($bloqueouExclusaoConta, "Integridade Referencial: Bloqueia exclusão de conta pai que possui cartão vinculado como subclasse");

    // 5. Testa criação de ativo de investimento (Renda Fixa com aporte inicial)
    $stmtInv = $pdo->prepare("
        INSERT INTO investments (user_id, account_id, name, type, initial_amount, start_date, due_date, status)
        VALUES (?, ?, 'CDB 110% CDI Banco Inter', 'renda_fixa', 10000.00, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active')
    ");
    $stmtInv->execute([$userId, $acc1Id]);
    $invId = (int)$pdo->lastInsertId();

    assertTest($invId > 0, "Ativo de investimento cadastrado com sucesso");
    assertTest(Security::validateInvestmentOwnership($pdo, $invId, $userId), "Security::validateInvestmentOwnership autoriza proprietário do ativo");

    // Garante categoria para testes
    $stmtCatAporte = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Aporte Teste', 'expense')");
    $stmtCatAporte->execute([$userId]);
    $catAporteId = (int)$pdo->lastInsertId();

    $stmtCatRend = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Rendimento Teste', 'income')");
    $stmtCatRend->execute([$userId]);
    $catRendId = (int)$pdo->lastInsertId();

    // 6. Testa registro de Aporte Inicial e Adicional (Saídas vinculadas ao investimento)
    $stmtTx1 = $pdo->prepare("
        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status)
        VALUES (?, ?, ?, ?, 'expense', 10000.00, 'Aporte Inicial CDB', CURDATE(), 'completed')
    ");
    $stmtTx1->execute([$userId, $acc1Id, $invId, $catAporteId]);

    $stmtTx2 = $pdo->prepare("
        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status)
        VALUES (?, ?, ?, ?, 'expense', 5000.00, 'Segundo Aporte CDB', CURDATE(), 'completed')
    ");
    $stmtTx2->execute([$userId, $acc1Id, $invId, $catAporteId]);

    // 7. Testa registro de Rendimentos e Proventos (Entrada vinculada)
    $stmtTx3 = $pdo->prepare("
        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status)
        VALUES (?, ?, ?, ?, 'income', 1200.00, 'Rendimentos de Juros do CDB', CURDATE(), 'completed')
    ");
    $stmtTx3->execute([$userId, $acc1Id, $invId, $catRendId]);

    // 8. Testa registro de Resgate Parcial (Entrada vinculada)
    $stmtTx4 = $pdo->prepare("
        INSERT INTO transactions (user_id, account_id, investment_id, category_id, type, amount, description, date, status)
        VALUES (?, ?, ?, ?, 'income', 6000.00, 'Resgate Parcial do CDB', CURDATE(), 'completed')
    ");
    $stmtTx4->execute([$userId, $acc1Id, $invId, $catRendId]);

    // 9. Validação do cálculo de Aportes, Retornos, Lucro Líquido e ROI
    $calcStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type = 'expense' AND status = 'completed' THEN amount ELSE 0 END), 0) AS total_aportado,
            COALESCE(SUM(CASE WHEN type = 'income' AND status = 'completed' THEN amount ELSE 0 END), 0) AS total_retornado
        FROM transactions
        WHERE investment_id = ? AND user_id = ?
    ");
    $calcStmt->execute([$invId, $userId]);
    $totais = $calcStmt->fetch(PDO::FETCH_ASSOC);

    $totalAportado = (float)$totais['total_aportado'];
    $totalRetornado = (float)$totais['total_retornado'];
    $lucroLiquido = $totalRetornado - $totalAportado;
    $roi = $totalAportado > 0 ? round(($lucroLiquido / $totalAportado) * 100, 2) : 0.0;

    assertTest($totalAportado === 15000.00, "Cálculo exato de total aportado no ativo: R$ 15.000,00");
    assertTest($totalRetornado === 7200.00, "Cálculo exato de total retornado (rendimentos + resgate): R$ 7.200,00");
    assertTest($lucroLiquido === -7800.00, "Posição líquida calculada com exatidão (- R$ 7.800,00 ainda investidos)");

    // 10. Testa proteção contra IDOR em investimentos
    $outroUserId = 999999;
    assertTest(!Security::validateInvestmentOwnership($pdo, $invId, $outroUserId), "Security::validateInvestmentOwnership bloqueia tentativa de acesso IDOR de outro usuário");

    // Limpeza do ambiente de teste
    $pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM credit_cards WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM investments WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

    assertTest(true, "Ambiente e registros temporários isolados da Fase 7 limpos com sucesso");

} catch (Exception $e) {
    echo "ERRO FATAL NA EXECUÇÃO DOS TESTES: " . $e->getMessage() . "\n";
    $falhas++;
}

echo "\n----------------------------------------------------\n";
echo "RESULTADO DOS TESTES:\n";
echo "Sucessos: {$passou} | Falhas: {$falhas}\n";
echo "----------------------------------------------------\n";

if ($falhas === 0) {
    echo "STATUS: TODOS OS TESTES DA FASE 7 PASSARAM COM SUCESSO!\n";
    exit(0);
} else {
    echo "STATUS: ALGUNS TESTES FALHARAM.\n";
    exit(1);
}
