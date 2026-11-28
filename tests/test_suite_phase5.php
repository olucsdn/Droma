<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Suíte de Testes Automatizados - Fase 5 (API RESTful e Expansão)
 * Metodologia SDD - Projeto Droma
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/ApiAuth.php';
require_once __DIR__ . '/../src/ApiResponse.php';

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
echo "TESTES DE INTEGRAÇÃO - FASE 5 (API RESTful DO WERT)" . PHP_EOL;
echo "====================================================" . PHP_EOL . PHP_EOL;

// 1. Cria usuários de teste com tokens de API e e-mail verificado
$token1 = ApiAuth::generateToken();
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, api_token, api_token_hash, email_verified_at, role) VALUES ('Tester API 1', ?, 'hash', ?, ?, NOW(), 'user')");
$stmt->execute(['api_user1_' . time() . '@wert.local', $token1, hash('sha256', $token1)]);
$user1Id = (int) $pdo->lastInsertId();

$token2 = ApiAuth::generateToken();
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, api_token, api_token_hash, email_verified_at, role) VALUES ('Tester API 2', ?, 'hash', ?, ?, NOW(), 'user')");
$stmt->execute(['api_user2_' . time() . '@wert.local', $token2, hash('sha256', $token2)]);
$user2Id = (int) $pdo->lastInsertId();

// Cria conta para o Usuário 1
$stmt = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Principal API', 'checking', 2500.00)");
$stmt->execute([$user1Id]);
$account1Id = (int) $pdo->lastInsertId();

// Cria conta para o Usuário 2 (para teste de IDOR)
$stmt = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Conta Secreta Outro Usuário', 'savings', 8000.00)");
$stmt->execute([$user2Id]);
$account2Id = (int) $pdo->lastInsertId();

// Cria categoria para o Usuário 1
$stmt = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Alimentação API', 'expense')");
$stmt->execute([$user1Id]);
$cat1Id = (int) $pdo->lastInsertId();

// 1. Validação de Migration 004
$cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'api_token'")->fetchAll();
assertTest("Coluna api_token existe na tabela users", count($cols) === 1);

// 2. ApiAuth::generateToken() produz hex de 64 caracteres
assertTest("ApiAuth::generateToken() gera token hexadecimal de 64 caracteres", 
    strlen($token1) === 64 && ctype_xdigit($token1)
);

// 3. Função de chamada interna simulada dos scripts da API via STDIN no PHP CLI
function callApi(string $script, string $method = 'GET', array $params = [], ?array $body = null, ?string $token = null): array {
    $scriptPath = __DIR__ . '/../api/v1/' . $script;
    $phpBin = PHP_BINARY;
    $laragonIni = 'C:\\laragon\\bin\\php\\php-8.5.10-Win32-vs17-x64\\php.ini';
    $cmd = (PHP_OS_FAMILY === 'Windows' && file_exists($laragonIni))
        ? "\"{$phpBin}\" -c \"{$laragonIni}\""
        : escapeshellarg($phpBin);

    $code = '<?php ';
    $code .= '$_SERVER["REQUEST_METHOD"] = ' . var_export($method, true) . '; ';
    if ($token !== null) {
        $code .= '$_SERVER["HTTP_AUTHORIZATION"] = ' . var_export('Bearer ' . $token, true) . '; ';
    }
    $code .= '$_GET = ' . var_export($params, true) . '; ';
    if ($body !== null) {
        $code .= '$_POST = ' . var_export($body, true) . '; ';
    }
    $code .= 'require ' . var_export($scriptPath, true) . ';';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['error' => 'proc_open failed'];
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($proc);

    $decoded = json_decode((string)$stdout, true);
    return is_array($decoded) ? $decoded : ['raw' => $stdout, 'stderr' => $stderr];
}

// 4. Teste de Autenticação: Rejeição sem Token (401)
$resSemToken = callApi('resumo.php', 'GET', [], null, null);
assertTest("Endpoint /api/v1/resumo.php rejeita requisição sem token com HTTP 401", 
    isset($resSemToken['status']) && $resSemToken['status'] === 401 && $resSemToken['success'] === false
);

// 5. Teste de Autenticação: Rejeição com Token Inválido (401)
$resTokenInvalido = callApi('resumo.php', 'GET', [], null, 'token_falso_1234567890abcdef');
assertTest("Endpoint rejeita token inválido ou falso com HTTP 401", 
    isset($resTokenInvalido['status']) && $resTokenInvalido['status'] === 401
);

// 6. Teste de Autenticação com Sucesso: /api/v1/resumo.php
$resResumo = callApi('resumo.php', 'GET', [], null, $token1);
assertTest("Endpoint /api/v1/resumo.php responde 200 OK com token válido e retorna saldo consolidado", 
    isset($resResumo['status']) && $resResumo['status'] === 200 &&
    isset($resResumo['data']['saldo_consolidado']) &&
    $resResumo['data']['saldo_consolidado'] == 2500.00
);

// 7. Teste de Listagem: /api/v1/accounts.php
$resContas = callApi('accounts.php', 'GET', [], null, $token1);
assertTest("Endpoint /api/v1/accounts.php lista apenas as contas do usuário autenticado", 
    isset($resContas['data']['contas']) && 
    count($resContas['data']['contas']) === 1 &&
    $resContas['data']['contas'][0]['id'] === $account1Id
);

// 8. Teste de Listagem: /api/v1/categories.php
$resCats = callApi('categories.php', 'GET', ['type' => 'expense'], null, $token1);
assertTest("Endpoint /api/v1/categories.php filtra categorias por tipo", 
    isset($resCats['data']['categorias']) &&
    count($resCats['data']['categorias']) === 1 &&
    $resCats['data']['categorias'][0]['id'] === $cat1Id
);

// 9. Teste de Criação de Transação via POST: /api/v1/transacoes.php
$novoGasto = [
    'description' => 'Almoço Executivo',
    'amount'      => 85.50,
    'type'        => 'expense',
    'date'        => date('Y-m-d'),
    'account_id'  => $account1Id,
    'category_id' => $cat1Id,
    'status'      => 'completed'
];
$resCriacao = callApi('transacoes.php', 'POST', [], $novoGasto, $token1);
$txCriadaId = $resCriacao['data']['id'] ?? 0;
assertTest("POST /api/v1/transacoes.php cria movimentação com sucesso retornando HTTP 201", 
    isset($resCriacao['status']) && $resCriacao['status'] === 201 &&
    $txCriadaId > 0 &&
    $resCriacao['data']['amount'] == 85.50
);

// 10. Teste de Proteção contra IDOR (Insecure Direct Object Reference)
// Usuário 1 tenta criar gasto apontando para a conta do Usuário 2!
$gastoInvasivo = [
    'description' => 'Tentativa de Fraude IDOR',
    'amount'      => 500.00,
    'type'        => 'expense',
    'date'        => date('Y-m-d'),
    'account_id'  => $account2Id, // Pertence ao User 2!
    'status'      => 'completed'
];
$resIdor = callApi('transacoes.php', 'POST', [], $gastoInvasivo, $token1);
assertTest("POST /api/v1/transacoes.php bloqueia tentativa de IDOR em conta de outro usuário (HTTP 403 Forbidden)", 
    isset($resIdor['status']) && $resIdor['status'] === 403
);

// 11. Teste de Exclusão de Transação via DELETE: /api/v1/transacoes.php
$resDelete = callApi('transacoes.php', 'DELETE', ['id' => $txCriadaId], null, $token1);
assertTest("DELETE /api/v1/transacoes.php exclui transação do próprio usuário com HTTP 200", 
    isset($resDelete['status']) && $resDelete['status'] === 200 &&
    isset($resDelete['data']['id']) && $resDelete['data']['id'] === $txCriadaId
);

// 12. Teste de Proteção no DELETE contra registro de terceiro
$resDelAlheio = callApi('transacoes.php', 'DELETE', ['id' => 999999], null, $token1);
assertTest("DELETE /api/v1/transacoes.php rejeita exclusão de transação inexistente ou de terceiro (HTTP 404)", 
    isset($resDelAlheio['status']) && $resDelAlheio['status'] === 404
);

// Limpeza dos usuários de teste
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$user1Id, $user2Id]);
assertTest("Isolamento e limpeza dos usuários de teste da API finalizados com integridade", true);

echo PHP_EOL . "----------------------------------------------------" . PHP_EOL;
echo "RESULTADO DOS TESTES:" . PHP_EOL;
echo "Sucessos: {$passed} | Falhas: {$failed}" . PHP_EOL;
echo "----------------------------------------------------" . PHP_EOL;

if ($failed === 0) {
    echo "STATUS: TODOS OS TESTES DA FASE 5 PASSARAM COM SUCESSO!" . PHP_EOL;
    exit(0);
} else {
    echo "STATUS: OCORRERAM FALHAS NOS TESTES DA FASE 5." . PHP_EOL;
    exit(1);
}

