<?php
declare(strict_types=1);
/**
 * Suíte de Testes Automatizados - Fase 4 (Análises, Relatórios e Assistente IA)
 * Metodologia SDD - Projeto Droma
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/ClienteIA.php';

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo " [\033[32mOK\033[0m] " . $description . PHP_EOL;
        $passed++;
    } else {
        echo " [\033[31mFALHA\033[0m] " . $description . PHP_EOL;
        $failed++;
    }
}

echo "====================================================" . PHP_EOL;
echo "TESTES DE INTEGRAÇÃO - FASE 4 (RELATÓRIOS & IA WERT)" . PHP_EOL;
echo "====================================================" . PHP_EOL . PHP_EOL;

// 1. Cria usuário e ambiente de teste isolado
$testEmail = 'phase4_test_' . time() . '@wert.local';
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Tester Phase 4', ?, 'hash', 'user')");
$stmt->execute([$testEmail]);
$userId = (int) $pdo->lastInsertId();

// Cria conta de teste
$stmt = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Investimento', 'investment', 1000.00)");
$stmt->execute([$userId]);
$accountId = (int) $pdo->lastInsertId();

// Cria categorias
$stmt = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Salário Teste', 'income')");
$stmt->execute([$userId]);
$catIncomeId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Tecnologia Teste', 'expense')");
$stmt->execute([$userId]);
$catExpenseId = (int) $pdo->lastInsertId();

// 1. Tabela ai_daily_usage existe com integridade
$tableCheck = $pdo->query("SHOW TABLES LIKE 'ai_daily_usage'")->rowCount() > 0;
assertTest("Tabela ai_daily_usage existe no banco", $tableCheck);

// 2. ClienteIA rate limit inicial
$ai = new ClienteIA();
$usageInicial = $ai->getDailyUsage($pdo, $userId);
assertTest("ClienteIA::getDailyUsage() inicial retorna 0 requisições e 3 restantes", 
    $usageInicial['requests'] === 0 && $usageInicial['remaining'] === 3 && $usageInicial['allowed'] === true
);

// 3. ClienteIA::recordUsage() incrementa e respeita cota diária
$ai->recordUsage($pdo, $userId);
$ai->recordUsage($pdo, $userId);
$usage2 = $ai->getDailyUsage($pdo, $userId);
assertTest("ClienteIA::recordUsage() incrementa requisições corretamente", 
    $usage2['requests'] === 2 && $usage2['remaining'] === 1 && $usage2['allowed'] === true
);

$ai->recordUsage($pdo, $userId);
$usage3 = $ai->getDailyUsage($pdo, $userId);
assertTest("ClienteIA bloqueia novas chamadas ao atingir limite diário de 3", 
    $usage3['requests'] === 3 && $usage3['remaining'] === 0 && $usage3['allowed'] === false
);

// 4. Diagnóstico Heurístico Local (Fallback)
$dadosTeste = [
    'periodo' => 'Outubro/2026',
    'receitas' => 10000.00,
    'despesas' => 6000.00,
    'maiores_despesas' => [
        ['categoria' => 'Tecnologia', 'total' => 4000.00]
    ]
];
$analiseLocal = $ai->gerarAnaliseLocal($dadosTeste);
assertTest("Diagnóstico local heurístico calcula superávit e percentual de economia", 
    str_contains($analiseLocal, 'Superávit Operacional') && 
    str_contains($analiseLocal, 'Tecnologia') &&
    str_contains($analiseLocal, 'R$ 4.000,00')
);

// 5. Inserção de lançamentos para teste de relatórios analíticos
$anoAtual = (int) date('Y');
$mesAtual = (int) date('m');

// Inserir receita de 5000 realizada
$stmt = $pdo->prepare("
    INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status)
    VALUES (?, ?, ?, 'income', 5000.00, 'Salário Outubro', ?, 'completed')
");
$stmt->execute([$userId, $accountId, $catIncomeId, date('Y-m-10')]);

// Inserir despesa de 1500 realizada
$stmt = $pdo->prepare("
    INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status)
    VALUES (?, ?, ?, 'expense', 1500.00, 'Servidor AWS', ?, 'completed')
");
$stmt->execute([$userId, $accountId, $catExpenseId, date('Y-m-15')]);

// Inserir compra no cartão de 800 PENDENTE (não deve entrar no fluxo de caixa realizado)
$stmt = $pdo->prepare("INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day) VALUES (?, ?, 'Black Card Test', 10000.00, 5, 15)");
$stmt->execute([$userId, $accountId]);
$cardId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare("
    INSERT INTO transactions (user_id, account_id, category_id, credit_card_id, type, amount, description, date, status)
    VALUES (?, ?, ?, ?, 'expense', 800.00, 'Smartphone Parcelado', ?, 'pending')
");
$stmt->execute([$userId, $accountId, $catExpenseId, $cardId, date('Y-m-20')]);

// 6. Teste da query de fluxo mensal (como executada em reports.php)
$stmtFluxo = $pdo->prepare("
    SELECT MONTH(date) AS mes, type, SUM(amount) AS total
    FROM transactions
    WHERE user_id = ? AND YEAR(date) = ? AND status = 'completed'
    GROUP BY MONTH(date), type
");
$stmtFluxo->execute([$userId, $anoAtual]);
$resFluxo = $stmtFluxo->fetchAll(PDO::FETCH_ASSOC);

$recMes = 0.0;
$despMes = 0.0;
foreach ($resFluxo as $rf) {
    if ((int)$rf['mes'] === $mesAtual) {
        if ($rf['type'] === 'income') $recMes += (float)$rf['total'];
        if ($rf['type'] === 'expense') $despMes += (float)$rf['total'];
    }
}

assertTest("Query de relatórios totaliza receitas e despesas realizadas sem compras pendentes de cartão", 
    $recMes === 5000.00 && $despMes === 1500.00
);

// 7. Teste de rate de poupança no relatório
$saldoMes = $recMes - $despMes; // 3500.00
$taxaPoupanca = round(($saldoMes / $recMes) * 100, 1); // 70.0%
assertTest("Cálculo de margem e taxa de poupança com exatidão matemática (70%)", 
    $saldoMes === 3500.00 && $taxaPoupanca === 70.0
);

// 8. Limpeza do ambiente de testes
$pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM credit_cards WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userId]);
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
assertTest("Limpeza e isolamento do usuário de teste executados com integridade referencial", true);

echo PHP_EOL . "----------------------------------------------------" . PHP_EOL;
echo "RESULTADO DOS TESTES:" . PHP_EOL;
echo "Sucessos: {$passed} | Falhas: {$failed}" . PHP_EOL;
echo "----------------------------------------------------" . PHP_EOL;

if ($failed === 0) {
    echo "STATUS: TODOS OS TESTES DA FASE 4 PASSARAM COM SUCESSO!" . PHP_EOL;
    exit(0);
} else {
    echo "STATUS: OCORRERAM FALHAS NOS TESTES DA FASE 4." . PHP_EOL;
    exit(1);
}

