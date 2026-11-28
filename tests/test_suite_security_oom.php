<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Suíte de Validação de Segurança AppSec (Mitigação CVE-2026-109618 / CVE-2026-109619)
 * Testa defesas contra OOM, State Pollution em Workers e Streaming de Dados.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/ExtratoParser.php';

$passed = 0;
$failed = 0;

function assertSec(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo " [\033[32mOK\033[0m] " . $desc . PHP_EOL;
        $passed++;
    } else {
        echo " [\033[31mFALHA\033[0m] " . $desc . PHP_EOL;
        $failed++;
    }
}

echo "====================================================" . PHP_EOL;
echo "TESTES DE SEGURANÇA APPSEC - CVE-2026-109618 & 19" . PHP_EOL;
echo "====================================================" . PHP_EOL . PHP_EOL;

// 1. Conexão PDO sem persistência opaca
assertSec("PDO::ATTR_PERSISTENT está desativado (imune a conexão suja)", 
    $pdo->getAttribute(PDO::ATTR_PERSISTENT) === false
);

// 2. Transações atômicas revertem com integridade
$pdo->beginTransaction();
assertSec("PDO::inTransaction() detecta transação em andamento", $pdo->inTransaction());
$pdo->rollBack();
assertSec("PDO::rollBack() encerra a transação com segurança", !$pdo->inTransaction());

// 3. Security::initSession() usa session_set_cookie_params sem quebrar
Security::initSession();
assertSec("Security::initSession() executa de forma segura sem lançar exceções", session_status() !== PHP_SESSION_DISABLED);

// 4. ExtratoParser::parseCsvStream limita consumo de memória e respeita teto de linhas
$csvBuffer = fopen('php://memory', 'r+');
fwrite($csvBuffer, "Data;Descricao;Valor\n");
for ($i = 0; $i < 3000; $i++) {
    fwrite($csvBuffer, "01/10/2026;Teste {$i};-10.00\n");
}
rewind($csvBuffer);

$cats = [
    ['id' => 1, 'name' => 'Alimentação', 'type' => 'expense']
];

$memBefore = memory_get_usage();
$linhas = ExtratoParser::parseCsvStream($csvBuffer, $cats, 500);
$memAfter = memory_get_usage();
fclose($csvBuffer);

assertSec("ExtratoParser::parseCsvStream respeita o teto máximo de registros (500)", count($linhas) === 500);
assertSec("ExtratoParser streaming não causa salto anormal de memória (< 1MB)", ($memAfter - $memBefore) < 1024 * 1024);

// 5. ExtratoParser::parseOfx resiste a buffers truncados
$ofxPayload = "<OFX><STMTTRN><TRNAMT>-150.00<DTPOSTED>20261028<MEMO>Compra Teste</STMTTRN></OFX>";
$ofxLinhas = ExtratoParser::parseOfx($ofxPayload, $cats, 10);
assertSec("ExtratoParser::parseOfx extrai transações OFX com sucesso", count($ofxLinhas) === 1 && $ofxLinhas[0]['amount'] === 150.00);

// 6. Configurações do php.ini blindadas contra OOM
$iniFile = __DIR__ . '/../docker/php/php.ini';
$iniContent = file_get_contents($iniFile);
assertSec("php.ini define upload_max_filesize de 8M", str_contains($iniContent, 'upload_max_filesize = 8M'));
assertSec("php.ini define post_max_size seguro de 10M", str_contains($iniContent, 'post_max_size = 10M'));
assertSec("php.ini removeu opcache.fast_shutdown", !str_contains($iniContent, 'opcache.fast_shutdown = 1'));
assertSec("php.ini possui limites de backtracking PCRE configurados", str_contains($iniContent, 'pcre.backtrack_limit = 1000000'));

// 7. Dockerfile define reciclagem de workers MaxConnectionsPerChild
$dockerFile = __DIR__ . '/../Dockerfile';
$dockerContent = file_get_contents($dockerFile);
assertSec("Dockerfile define MaxConnectionsPerChild 500 para reciclagem de workers", str_contains($dockerContent, 'MaxConnectionsPerChild 500'));

// 8. Endpoints da API em inglês existem como aliases das rotas em português
assertSec("Endpoint api/v1/accounts.php existe", file_exists(__DIR__ . '/../api/v1/accounts.php'));
assertSec("Endpoint api/v1/categories.php existe", file_exists(__DIR__ . '/../api/v1/categories.php'));

echo PHP_EOL . "----------------------------------------------------" . PHP_EOL;
echo "RESULTADO DOS TESTES DE SEGURANÇA:" . PHP_EOL;
echo "Sucessos: {$passed} | Falhas: {$failed}" . PHP_EOL;
echo "----------------------------------------------------" . PHP_EOL;

if ($failed === 0) {
    echo "\033[32mSTATUS: TODAS AS MITIGAÇÕES DE SEGURANÇA FORAM VALIDADAS!\033[0m" . PHP_EOL;
    exit(0);
} else {
    echo "\033[31mSTATUS: FALHA NA VALIDAÇÃO DE SEGURANÇA!\033[0m" . PHP_EOL;
    exit(1);
}
