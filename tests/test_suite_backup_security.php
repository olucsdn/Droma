<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Suíte de Testes Automatizados - Validação e Segurança de Backup & Restauração
 * Testa defesas contra arquivos compactados, injeções SQL, sanitização e substituição atômica.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';

$passed = 0;
$failed = 0;

function assertBackup(string $desc, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        echo " [\033[32mOK\033[0m] " . $desc . PHP_EOL;
        $passed++;
    } else {
        echo " [\033[31mFALHA\033[0m] " . $desc . PHP_EOL;
        $failed++;
    }
}

echo "====================================================" . PHP_EOL;
echo "TESTES DE SEGURANÇA E RESTAURAÇÃO DE BACKUP (DROMA)" . PHP_EOL;
echo "====================================================" . PHP_EOL . PHP_EOL;

// Helper para criar mock de upload
function createMockUpload(string $filename, string $content): array {
    $tmp = tempnam(sys_get_temp_dir(), 'droma_test_');
    file_put_contents($tmp, $content);
    return [
        'name'     => $filename,
        'type'     => 'application/json',
        'tmp_name' => $tmp,
        'error'    => UPLOAD_ERR_OK,
        'size'     => strlen($content),
    ];
}

// -------------------------------------------------------------------------
// 1. TESTES DE VALIDAÇÃO DE SEGURANÇA (MAGIC BYTES E FORMATOS)
// -------------------------------------------------------------------------

// 1.1 Arquivo ZIP renomeado para .json
$zipMock = createMockUpload('backup.json', "PK\x03\x04\x14\x00\x00\x00\x08\x00" . str_repeat('A', 100));
$resZip = Security::validateBackupUpload($zipMock);
assertBackup("Bloqueia arquivo ZIP com magic bytes PK renomeado para .json", !$resZip['valid'] && str_contains($resZip['error'], 'ZIP'));
@unlink($zipMock['tmp_name']);

// 1.2 Arquivo GZIP renomeado para .json
$gzipMock = createMockUpload('backup.json', "\x1F\x8B\x08\x00" . str_repeat('B', 100));
$resGzip = Security::validateBackupUpload($gzipMock);
assertBackup("Bloqueia pacote GZIP com magic bytes 1F 8B renomeado para .json", !$resGzip['valid'] && str_contains($resGzip['error'], 'GZIP'));
@unlink($gzipMock['tmp_name']);

// 1.3 Arquivo RAR renomeado para .json
$rarMock = createMockUpload('backup.json', "Rar!\x1A\x07\x00" . str_repeat('C', 100));
$resRar = Security::validateBackupUpload($rarMock);
assertBackup("Bloqueia arquivo RAR renomeado para .json", !$resRar['valid'] && str_contains($resRar['error'], 'RAR'));
@unlink($rarMock['tmp_name']);

// 1.4 Arquivo 7-Zip renomeado para .json
$sevenZMock = createMockUpload('backup.json', "7z\xBC\xAF\x27\x1C" . str_repeat('D', 100));
$res7z = Security::validateBackupUpload($sevenZMock);
assertBackup("Bloqueia pacote 7-Zip renomeado para .json", !$res7z['valid'] && str_contains($res7z['error'], '7-Zip'));
@unlink($sevenZMock['tmp_name']);

// 1.5 Arquivo compactado com extensão .zip direta
$extZipMock = createMockUpload('backup.zip', 'PK qualquerrr');
$resExtZip = Security::validateBackupUpload($extZipMock);
assertBackup("Bloqueia upload com extensão .zip direta com mensagem explicativa", !$resExtZip['valid'] && str_contains($resExtZip['error'], 'descompacte'));
@unlink($extZipMock['tmp_name']);

// 1.6 Script SQL puro renomeado para .json
$sqlMock = createMockUpload('backup.json', "-- Dump SQL\nDROP TABLE transactions; INSERT INTO users VALUES (1, 'hacker');");
$resSql = Security::validateBackupUpload($sqlMock);
assertBackup("Bloqueia script SQL puro com comandos diretos (-- / DROP / INSERT)", !$resSql['valid'] && str_contains($resSql['error'], 'SQL'));
@unlink($sqlMock['tmp_name']);

// 1.7 Código PHP ou tags de script executável
$phpMock = createMockUpload('backup.json', "<?php system('ls'); ?>");
$resPhp = Security::validateBackupUpload($phpMock);
assertBackup("Bloqueia arquivo com código executável PHP", !$resPhp['valid'] && (str_contains($resPhp['error'], 'executável') || str_contains($resPhp['error'], 'JSON válido')));
@unlink($phpMock['tmp_name']);

// 1.8 Arquivo contendo byte nulo (\0)
$nullByteMock = createMockUpload('backup.json', '{"app":"DromaFinancy","data":' . "\0" . '{}}');
$resNull = Security::validateBackupUpload($nullByteMock);
assertBackup("Bloqueia arquivo com caracteres binários nulos (NUL byte injection)", !$resNull['valid'] && str_contains($resNull['error'], 'nulos'));
@unlink($nullByteMock['tmp_name']);

// 1.9 JSON sem assinatura Droma
$fakeJsonMock = createMockUpload('backup.json', '{"algum_app": "outro", "dados": []}');
$resFake = Security::validateBackupUpload($fakeJsonMock);
assertBackup("Bloqueia arquivo JSON sem assinatura oficial DromaFinancy/WertFinancy", !$resFake['valid'] && str_contains($resFake['error'], 'Droma'));
@unlink($fakeJsonMock['tmp_name']);

// 1.10 JSON válido legítimo
$validJson = json_encode([
    'app' => 'DromaFinancy',
    'schema_version' => '1.1',
    'data' => [
        'accounts' => [
            ['id' => 1, 'name' => 'Conta Teste', 'type' => 'checking', 'initial_balance' => 500.0]
        ],
        'categories' => [
            ['id' => 2, 'name' => 'Alimentação', 'type' => 'expense']
        ],
        'transactions' => [
            ['id' => 10, 'account_id' => 1, 'category_id' => 2, 'type' => 'expense', 'amount' => 45.0, 'description' => 'Mercado', 'date' => '2026-10-28']
        ]
    ]
]);
$validMock = createMockUpload('backup.json', $validJson);
$resValid = Security::validateBackupUpload($validMock);
assertBackup("Valida com sucesso arquivo de backup JSON estruturado legítimo do Droma", $resValid['valid'] && is_array($resValid['payload']));
@unlink($validMock['tmp_name']);

// -------------------------------------------------------------------------
// 2. TESTE DE SUBSTITUIÇÃO (SUBSTITUI EM VEZ DE CLONAR/DUPLICAR)
// -------------------------------------------------------------------------

// Cria Usuário A com dados pré-existentes
$emailA = 'user_replace_' . time() . '@droma.test';
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('User Replace', ?, 'hash', 'user')")->execute([$emailA]);
$userAId = (int)$pdo->lastInsertId();

// Adiciona 3 contas pré-existentes para o Usuário A
$pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Antiga 1', 'checking', 100)")->execute([$userAId]);
$oldAcc1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Antiga 2', 'savings', 200)")->execute([$userAId]);
$oldAcc2 = (int)$pdo->lastInsertId();

// Adiciona 4 categorias pré-existentes para o Usuário A
$pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Cat Antiga 1', 'expense')")->execute([$userAId]);
$oldCat1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Cat Antiga 2', 'income')")->execute([$userAId]);
$oldCat2 = (int)$pdo->lastInsertId();

// Adiciona 5 transações pré-existentes para o Usuário A
for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date) VALUES (?, ?, ?, 'expense', ?, 'Antiga {$i}', '2026-01-01')")
        ->execute([$userAId, $oldAcc1, $oldCat1, 10 * $i]);
}

$contasAntes = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE user_id = {$userAId}")->fetchColumn();
$catsAntes   = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE user_id = {$userAId}")->fetchColumn();
$txsAntes    = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$userAId}")->fetchColumn();

assertBackup("Usuário de teste possui estado inicial com 2 contas, 2 categorias e 5 transações", 
    $contasAntes === 2 && $catsAntes === 2 && $txsAntes === 5
);

// Agora simula o novo fluxo de restauração do backup.php (que substitui os dados)
// O backup tem: APENAS 1 CONTA ("Nova Conta Backup"), 1 CATEGORIA ("Nova Categoria Backup") e 2 TRANSAÇÕES
$backupPayload = [
    'app' => 'DromaFinancy',
    'schema_version' => '1.1',
    'data' => [
        'accounts' => [
            ['id' => 999, 'name' => '<script>alert(1)</script>Nova Conta Única', 'type' => 'checking', 'initial_balance' => '1500.50']
        ],
        'categories' => [
            ['id' => 888, 'name' => 'Nova Categoria Única', 'type' => 'expense', 'budget_limit' => '300.00']
        ],
        'transactions' => [
            ['id' => 777, 'account_id' => 999, 'category_id' => 888, 'type' => 'expense', 'amount' => '120.00', 'description' => 'Despesa Nova 1', 'date' => '2026-10-10'],
            ['id' => 778, 'account_id' => 999, 'category_id' => 888, 'type' => 'expense', 'amount' => '80.00', 'description' => 'Despesa Nova 2', 'date' => '2026-10-15'],
        ]
    ]
];

// Executa a lógica de substituição exatamente como em backup.php
$pdo->beginTransaction();

// Limpeza prévia (Substituição)
$pdo->prepare("DELETE i FROM installments i INNER JOIN transactions t ON i.transaction_id = t.id WHERE t.user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM recurring_transactions WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM goals WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM investments WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM credit_cards WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userAId]);

$mapAccounts = [];
$stmtAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, ?, ?, ?)");
foreach ($backupPayload['data']['accounts'] as $acc) {
    $name = Security::sanitizeText($acc['name'], 100, 'Conta');
    $stmtAcc->execute([$userAId, $name, $acc['type'], (float)$acc['initial_balance']]);
    $mapAccounts[(int)$acc['id']] = (int)$pdo->lastInsertId();
}

$mapCats = [];
$stmtCat = $pdo->prepare("INSERT INTO categories (user_id, name, type, budget_limit) VALUES (?, ?, ?, ?)");
foreach ($backupPayload['data']['categories'] as $cat) {
    $stmtCat->execute([$userAId, $cat['name'], $cat['type'], (float)$cat['budget_limit']]);
    $mapCats[(int)$cat['id']] = (int)$pdo->lastInsertId();
}

$stmtTx = $pdo->prepare("INSERT INTO transactions (user_id, account_id, category_id, type, amount, description, date, status, urgency) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 'media')");
foreach ($backupPayload['data']['transactions'] as $tx) {
    $accId = $mapAccounts[(int)$tx['account_id']];
    $catId = $mapCats[(int)$tx['category_id']];
    $stmtTx->execute([$userAId, $accId, $catId, $tx['type'], (float)$tx['amount'], $tx['description'], $tx['date']]);
}

$pdo->commit();

// Verificações pós-restauração
$contasDepois = (int)$pdo->query("SELECT COUNT(*) FROM accounts WHERE user_id = {$userAId}")->fetchColumn();
$catsDepois   = (int)$pdo->query("SELECT COUNT(*) FROM categories WHERE user_id = {$userAId}")->fetchColumn();
$txsDepois    = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id = {$userAId}")->fetchColumn();

$accCriada = $pdo->query("SELECT name FROM accounts WHERE user_id = {$userAId}")->fetch(PDO::FETCH_ASSOC);

assertBackup("Restauração substituiu contas antigas (resultado: exatamente 1 conta, sem duplicação)", $contasDepois === 1);
assertBackup("Restauração sanitizou nome da conta removendo tags <script>", $accCriada['name'] === 'Nova Conta Única');
assertBackup("Restauração substituiu categorias antigas (resultado: exatamente 1 categoria, sem duplicar)", $catsDepois === 1);
assertBackup("Restauração substituiu transações antigas (resultado: exatamente 2 transações em vez de 7)", $txsDepois === 2);

// Limpeza final do usuário de teste
$pdo->prepare("DELETE FROM transactions WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM categories WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM accounts WHERE user_id = ?")->execute([$userAId]);
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userAId]);

echo PHP_EOL . "----------------------------------------------------" . PHP_EOL;
echo "RESULTADO DOS TESTES DE BACKUP:" . PHP_EOL;
echo "Sucessos: {$passed} | Falhas: {$failed}" . PHP_EOL;
echo "----------------------------------------------------" . PHP_EOL;

if ($failed === 0) {
    echo "\033[32mSTATUS: TODOS OS TESTES DE VALIDAÇÃO E RESTAURAÇÃO FORAM APROVADOS COM SUCESSO!\033[0m" . PHP_EOL;
    exit(0);
} else {
    echo "\033[31mSTATUS: OCORRERAM FALHAS NOS TESTES DE BACKUP.\033[0m" . PHP_EOL;
    exit(1);
}
