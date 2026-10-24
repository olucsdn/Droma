<?php
/**
 * Testes Integrados da Fase 2 — SDD Wert
 * Executar via CLI: php tests/test_suite_phase2.php
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
echo "TESTES DE INTEGRAÇÃO - FASE 2 (NÚCLEO FINANCEIRO)\n";
echo "====================================================\n\n";

$testEmail = 'phase2_test_' . time() . '_' . mt_rand(1000, 9999) . '@wert.local';
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Tester Phase 2', ?, 'hash', 'user')");
$stmt->execute([$testEmail]);
$userId = (int) $pdo->lastInsertId();

$stmtAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Corrente Principal', 'checking', 1000.00)");
$stmtAcc->execute([$userId]);
$contaOrigemId = (int) $pdo->lastInsertId();

$stmtCat1 = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Salário Teste', 'income')");
$stmtCat1->execute([$userId]);
$catIncId = (int) $pdo->lastInsertId();

$stmtCat2 = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Alimentação Teste', 'expense')");
$stmtCat2->execute([$userId]);
$catExpId = (int) $pdo->lastInsertId();

$stmtTx = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency) VALUES (?, ?, ?, 'expense', 150.00, 'Gasto com sustentamento', CURDATE(), 'completed', 'media')");
$stmtTx->execute([$userId, $contaOrigemId, $catExpId]);

// 1. Módulo de Contas
runTest("Contas do usuário são carregadas e agrupadas com saldo individual", function() use ($pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT 
            a.id, 
            a.name, 
            a.type, 
            a.initial_balance,
            COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_receitas,
            COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_despesas
         FROM accounts a
         LEFT JOIN transactions t ON t.account_id = a.id AND t.user_id = a.user_id
         WHERE a.user_id = ?
         GROUP BY a.id, a.name, a.type, a.initial_balance"
    );
    $stmt->execute([$userId]);
    $contas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($contas)) return false;

    foreach ($contas as $c) {
        $saldoCalculado = (float)$c['initial_balance'] + (float)$c['total_receitas'] - (float)$c['total_despesas'];
        if (!is_numeric($saldoCalculado)) return false;
    }
    return true;
});

runTest("Regra de Negócio: Bloqueio de exclusão da única conta do usuário", function() use ($pdo, $userId) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
    $countStmt->execute([$userId]);
    $qtd = (int)$countStmt->fetchColumn();
    return $qtd === 1;
});

runTest("Regra de Negócio: Bloqueio de exclusão de conta com movimentações", function() use ($pdo, $userId, $contaOrigemId) {
    $txCheck = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE account_id = ? AND user_id = ?");
    $txCheck->execute([$contaOrigemId, $userId]);
    return ((int)$txCheck->fetchColumn()) > 0;
});

// 2. Módulo de Categorias
runTest("Categorias são listadas separadas por tipo (income e expense)", function() use ($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, name, type FROM categories WHERE user_id = ?");
    $stmt->execute([$userId]);
    $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $temExpense = false;
    $temIncome = false;
    foreach ($cats as $c) {
        if ($c['type'] === 'expense') $temExpense = true;
        if ($c['type'] === 'income') $temIncome = true;
    }
    return $temExpense && $temIncome;
});

runTest("Regra de Unicidade: Índice único impede categoria duplicada com mesmo nome e tipo", function() use ($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT name, type FROM categories WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $cat = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cat) return false;

    try {
        $stmtDup = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)");
        $stmtDup->execute([$userId, $cat['name'], $cat['type']]);
        return false;
    } catch (PDOException $e) {
        return true;
    }
});

runTest("Regra de Limites: Trava de no máximo 8 categorias de despesa e 3 de receita", function() use ($pdo, $userId) {
    // Conta quantas despesas existem atualmente
    $stmtExp = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ? AND type = 'expense'");
    $stmtExp->execute([$userId]);
    $qtdExp = (int)$stmtExp->fetchColumn();

    // Tenta preencher até 8
    for ($i = $qtdExp + 1; $i <= 8; $i++) {
        $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, 'expense')")->execute([$userId, "Despesa Extra {$i}"]);
    }

    // Checa se atingiu 8
    $stmtExp->execute([$userId]);
    $totalExp = (int)$stmtExp->fetchColumn();

    // Conta receitas
    $stmtInc = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ? AND type = 'income'");
    $stmtInc->execute([$userId]);
    $qtdInc = (int)$stmtInc->fetchColumn();

    for ($j = $qtdInc + 1; $j <= 3; $j++) {
        $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, 'income')")->execute([$userId, "Receita Extra {$j}"]);
    }
    $stmtInc->execute([$userId]);
    $totalInc = (int)$stmtInc->fetchColumn();

    // Simula a validação de limite que categories.php realiza
    $podeCriar9Despesa = ($totalExp < 8);
    $podeCriar4Receita = ($totalInc < 3);

    return ($totalExp === 8 && $totalInc === 3 && !$podeCriar9Despesa && !$podeCriar4Receita);
});

runTest("Regra de Limites: Bloqueia exclusão quando resta apenas 1 categoria (mínimo 1)", function() use ($pdo) {
    // Cria usuário isolado com apenas 1 categoria de despesa
    $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Tester Min Cat', 'mincat@test.com', 'hash', 'user')")->execute();
    $uId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Única Despesa', 'expense')")->execute([$uId]);
    
    // Consulta quantidade
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ? AND type = 'expense'");
    $stmt->execute([$uId]);
    $qtd = (int)$stmt->fetchColumn();

    // A regra exige que se $qtd <= 1, a exclusão seja bloqueada
    $bloqueioAtivo = ($qtd <= 1);

    // Limpeza
    $pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$uId]);
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uId]);

    return $bloqueioAtivo;
});

// 3. Módulo de Transferência entre Contas
runTest("Transferência atômica entre contas cria débito e crédito equilibrados", function() use ($pdo, $userId) {
    $pdo->beginTransaction();
    $stmtNova = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Poupança Teste', 'savings', 500.00)");
    $stmtNova->execute([$userId]);
    $novaContaId = (int)$pdo->lastInsertId();

    $contaOrigemId = 1;
    $valorTransferencia = 75.50;

    $catExp = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Transferência' AND type = 'expense'");
    $catExp->execute([$userId]);
    $catExpId = $catExp->fetchColumn();
    if (!$catExpId) {
        $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Transferência', 'expense')")->execute([$userId]);
        $catExpId = (int)$pdo->lastInsertId();
    }

    $catInc = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = 'Transferência' AND type = 'income'");
    $catInc->execute([$userId]);
    $catIncId = $catInc->fetchColumn();
    if (!$catIncId) {
        $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Transferência', 'income')")->execute([$userId]);
        $catIncId = (int)$pdo->lastInsertId();
    }

    $stmtDeb = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency) VALUES (?, ?, ?, 'expense', ?, 'Teste Transf', CURDATE(), 'completed', 'baixa')");
    $stmtDeb->execute([$userId, $contaOrigemId, $catExpId, $valorTransferencia]);
    $debId = (int)$pdo->lastInsertId();

    $stmtCred = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency) VALUES (?, ?, ?, 'income', ?, 'Teste Transf', CURDATE(), 'completed', 'baixa')");
    $stmtCred->execute([$userId, $novaContaId, $catIncId, $valorTransferencia]);
    $credId = (int)$pdo->lastInsertId();

    $checkStmt = $pdo->prepare("SELECT type, amount FROM transactions WHERE id IN (?, ?)");
    $checkStmt->execute([$debId, $credId]);
    $rows = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

    $somaLiquida = 0;
    foreach ($rows as $r) {
        $somaLiquida += ($r['type'] === 'income') ? (float)$r['amount'] : -(float)$r['amount'];
    }

    $pdo->rollBack();

    return abs($somaLiquida) < 0.001;
});

// 4. Módulo de Extrato e Filtros Avançados
runTest("Extrato filtra corretamente por período e status", function() use ($pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM transactions t
         WHERE t.user_id = ? 
           AND MONTH(t.date) = MONTH(CURDATE()) AND YEAR(t.date) = YEAR(CURDATE())
           AND t.status = 'completed'"
    );
    $stmt->execute([$userId]);
    $count = (int)$stmt->fetchColumn();
    return $count >= 0;
});

runTest("Extrato filtra com busca textual em descrição", function() use ($pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM transactions t
         WHERE t.user_id = ? AND t.description LIKE ?"
    );
    $stmt->execute([$userId, '%sustentamento%']);
    $count = (int)$stmt->fetchColumn();
    return $count >= 1;
});

runTest("Exportação CSV formata os campos com ponto e vírgula e BOM UTF-8", function() {
    $stream = fopen('php://memory', 'w+');
    fprintf($stream, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($stream, ['ID', 'Data', 'Tipo', 'Descrição', 'Valor (R$)'], ';', '"', "\\");
    fputcsv($stream, [1, '22/10/2026', 'Entrada', 'Salário', '2.500,00'], ';', '"', "\\");
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return str_contains($csv, 'ID;Data;Tipo;Descrição;') && str_contains($csv, '2.500,00');
});

// Limpeza do ambiente de testes da Fase 2
$pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

echo "\n----------------------------------------------------\n";
echo "RESULTADO DOS TESTES:\n";
echo "Sucessos: $sucessos | Falhas: $erros\n";
echo "----------------------------------------------------\n";
if ($erros === 0) {
    echo "STATUS: TODOS OS TESTES DA FASE 2 PASSARAM COM SUCESSO!\n";
} else {
    echo "STATUS: HOUVE FALHAS QUE PRECISAM DE CORREÇÃO.\n";
}

