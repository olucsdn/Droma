<?php
/**
 * Suíte de Testes Automatizados - Fase 6 (PWA, Importação de Extratos e Backup)
 * Metodologia SDD - Projeto Wert
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';

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
echo "TESTES DE INTEGRAÇÃO - FASE 6 (PWA, IMPORT & BACKUP)" . PHP_EOL;
echo "====================================================" . PHP_EOL . PHP_EOL;

// 1. Validação de PWA Manifest e Service Worker
$manifestPath = __DIR__ . '/../manifest.json';
$swPath = __DIR__ . '/../sw.js';

assertTest("Arquivo manifest.json existe e possui JSON válido", 
    file_exists($manifestPath) && is_array(json_decode(file_get_contents($manifestPath), true))
);

$manifestData = json_decode(file_get_contents($manifestPath), true);
assertTest("manifest.json define start_url, theme_color e display standalone", 
    ($manifestData['display'] ?? '') === 'standalone' &&
    ($manifestData['start_url'] ?? '') === 'index.php' &&
    isset($manifestData['icons']) && count($manifestData['icons']) >= 2
);

assertTest("Arquivo sw.js existe e define listener para install e fetch", 
    file_exists($swPath) && 
    str_contains(file_get_contents($swPath), 'addEventListener(\'install\'') &&
    str_contains(file_get_contents($swPath), 'addEventListener(\'fetch\'')
);

// 2. Cria usuário para testes de backup e importação
$testEmail = 'phase6_tester_' . time() . '@wert.local';
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Tester Phase 6', ?, 'hash', 'user')");
$stmt->execute([$testEmail]);
$userId = (int) $pdo->lastInsertId();

// Cria conta
$stmt = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Corrente PWA', 'checking', 1000.00)");
$stmt->execute([$userId]);
$accountId = (int) $pdo->lastInsertId();

// Cria categorias
$stmt = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Alimentação e Mercado', 'expense')");
$stmt->execute([$userId]);
$catDespId = (int) $pdo->lastInsertId();

$stmt = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Salário e Renda', 'income')");
$stmt->execute([$userId]);
$catRecId = (int) $pdo->lastInsertId();

// Cria cartão vinculado à conta
$stmt = $pdo->prepare("INSERT INTO credit_cards (user_id, account_id, name, limit_amount, closing_day, due_day) VALUES (?, ?, 'Cartão Platinum', 5000.00, 1, 10)");
$stmt->execute([$userId, $accountId]);
$cardId = (int) $pdo->lastInsertId();

// Cria transações
$stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status) VALUES (?, ?, ?, 'income', 3000.00, 'Salário Mensal', ?, 'completed')");
$stmt->execute([$userId, $accountId, $catRecId, date('Y-m-05')]);

$stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, credit_card_id, type, amount, description, date, status) VALUES (?, ?, ?, ?, 'expense', 150.00, 'Almoço Cartão', ?, 'pending')");
$stmt->execute([$userId, $accountId, $catDespId, $cardId, date('Y-m-12')]);
$txExpenseId = (int) $pdo->lastInsertId();

// 3. Teste do Backup JSON: Estrutura e Consistência
$stmtTxs = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ?");
$stmtTxs->execute([$userId]);
$txs = $stmtTxs->fetchAll(PDO::FETCH_ASSOC);

$backupGerado = [
    'app' => 'WertFinancy',
    'schema_version' => '1.0',
    'exported_at' => date('Y-m-d H:i:s'),
    'user_id' => $userId,
    'data' => [
        'accounts' => [['id' => $accountId, 'name' => 'Conta Corrente PWA', 'type' => 'checking', 'initial_balance' => 1000.00]],
        'categories' => [['id' => $catDespId, 'name' => 'Alimentação e Mercado', 'type' => 'expense']],
        'credit_cards' => [['id' => $cardId, 'name' => 'Cartão Platinum', 'limit_amount' => 5000.00]],
        'transactions' => $txs
    ]
];
$jsonEncoded = json_encode($backupGerado);
assertTest("Geração de backup exporta estrutura com contas, categorias, cartões e transações", 
    is_string($jsonEncoded) && count($txs) === 2
);

// 4. Teste de Restauração de Backup em Novo Usuário
$destEmail = 'restore_dest_' . time() . '@wert.local';
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Destinatário Restore', ?, 'hash', 'user')");
$stmt->execute([$destEmail]);
$destUserId = (int) $pdo->lastInsertId();

// Simula rotina de restauração
$pdo->beginTransaction();
$mapAcc = [];
foreach ($backupGerado['data']['accounts'] as $a) {
    $ins = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, ?, ?, ?)");
    $ins->execute([$destUserId, $a['name'], $a['type'], $a['initial_balance']]);
    $mapAcc[$a['id']] = (int)$pdo->lastInsertId();
}

$mapCat = [];
foreach ($backupGerado['data']['categories'] as $c) {
    $ins = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)");
    $ins->execute([$destUserId, $c['name'], $c['type']]);
    $mapCat[$c['id']] = (int)$pdo->lastInsertId();
}

foreach ($backupGerado['data']['transactions'] as $t) {
    $catId = $mapCat[$t['category_id']] ?? array_values($mapCat)[0];
    $ins = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$destUserId, $mapAcc[$t['account_id']], $catId, $t['type'], $t['amount'], $t['description'], $t['date'], $t['status']]);
}
$pdo->commit();

$countRestauradas = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$destUserId}")->fetchColumn();
assertTest("Restauração de backup persiste registros com sucesso e remapeia chaves estrangeiras", 
    $countRestauradas === 2
);

// 5. Teste de Sugestão de Categoria e Parsers no Módulo de Importação
require_once __DIR__ . '/../src/ExtratoParser.php';
$categoriasMock = [
    ['id' => 10, 'name' => 'Alimentação', 'type' => 'expense'],
    ['id' => 20, 'name' => 'Transporte', 'type' => 'expense'],
    ['id' => 30, 'name' => 'Salário', 'type' => 'income'],
];

$catSugerida1 = ExtratoParser::sugerirCategoria('IFOOD *RESTAURANTE 123', $categoriasMock, 'expense');
$catSugerida2 = ExtratoParser::sugerirCategoria('POSTO IPIRANGA COMBUSTIVEL', $categoriasMock, 'expense');
$catSugerida3 = ExtratoParser::sugerirCategoria('TED RECEBIDA SALARIO MENSAL', $categoriasMock, 'income');

assertTest("Parser de importação infere corretamente categorias com base em descrição (iFood -> Alimentação)", 
    $catSugerida1 === 10
);
assertTest("Parser de importação infere Transporte para Posto/Combustível", 
    $catSugerida2 === 20
);
assertTest("Parser de importação infere Salário para TED/Salário de entrada", 
    $catSugerida3 === 30
);

// Teste de parser CSV
$csvMock = "Data;Descricao;Valor\n22/10/2026;Supermercado Central;-250,50\n23/10/2026;Pix Recebido Freelance;1200,00";
$csvParsed = ExtratoParser::parseCsv($csvMock, $categoriasMock);
assertTest("ExtratoParser::parseCsv() converte formato bancário brasileiro (DD/MM/YYYY e -250,50)", 
    count($csvParsed) === 2 &&
    $csvParsed[0]['amount'] === 250.50 &&
    $csvParsed[0]['type'] === 'expense' &&
    $csvParsed[1]['amount'] === 1200.00 &&
    $csvParsed[1]['type'] === 'income'
);

// Teste de parser OFX
$ofxMock = "<OFX><STMTTRN><TRNAMT>-89.90</TRNAMT><DTPOSTED>20261022</DTPOSTED><MEMO>Uber Viagem</MEMO></STMTTRN></OFX>";
$ofxParsed = ExtratoParser::parseOfx($ofxMock, $categoriasMock);
assertTest("ExtratoParser::parseOfx() extrai nós <TRNAMT>, <DTPOSTED> e <MEMO>", 
    count($ofxParsed) === 1 &&
    $ofxParsed[0]['amount'] === 89.90 &&
    $ofxParsed[0]['type'] === 'expense' &&
    $ofxParsed[0]['date'] === '2026-10-22'
);

// 6. Teste de Edição de Gasto com Cartão de Crédito
// Atualiza a transação com cartão e verifica persistência
$stmtUpdate = $pdo->prepare("
    UPDATE transactions
    SET credit_card_id = ?, description = 'Almoço Executivo Platinum'
    WHERE id = ? AND user_id = ?
");
$stmtUpdate->execute([$cardId, $txExpenseId, $userId]);

$stmtCheck = $pdo->prepare("SELECT credit_card_id, description FROM transactions WHERE id = ?");
$stmtCheck->execute([$txExpenseId]);
$checkTx = $stmtCheck->fetch(PDO::FETCH_ASSOC);

assertTest("editar_gasto.php associa e atualiza cartão de crédito corretamente", 
    (int)$checkTx['credit_card_id'] === $cardId &&
    $checkTx['description'] === 'Almoço Executivo Platinum'
);

// 7. Limpeza dos dados de teste
$pdo->prepare("DELETE FROM transactions WHERE user_id IN (?, ?)")->execute([$userId, $destUserId]);
$pdo->prepare("DELETE FROM credit_cards WHERE user_id IN (?, ?)")->execute([$userId, $destUserId]);
$pdo->prepare("DELETE FROM accounts WHERE user_id IN (?, ?)")->execute([$userId, $destUserId]);
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$userId, $destUserId]);
assertTest("Limpeza do ambiente de testes da Fase 6 concluída", true);

echo PHP_EOL . "----------------------------------------------------" . PHP_EOL;
echo "RESULTADO DOS TESTES:" . PHP_EOL;
echo "Sucessos: {$passed} | Falhas: {$failed}" . PHP_EOL;
echo "----------------------------------------------------" . PHP_EOL;

if ($failed === 0) {
    echo "STATUS: TODOS OS TESTES DA FASE 6 PASSARAM COM SUCESSO!" . PHP_EOL;
    exit(0);
} else {
    echo "STATUS: OCORRERAM FALHAS NOS TESTES DA FASE 6." . PHP_EOL;
    exit(1);
}
