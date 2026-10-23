<?php
/**
 * Configuração de Conexão com o Banco de Dados — Projeto Wert
 * Suporte a ambientes de Produção em Nuvem e Desenvolvimento Seguro (SSL/TLS)
 */

// 1. Carrega variáveis do arquivo .env se existir na raiz (útil em dev ou containers)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (getenv($k) === false) {
                    putenv("$k=$v");
                    $_ENV[$k] = $v;
                }
            }
        }
    }
}

// 2. Leitura das variáveis de ambiente com sanitização
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'wert';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

// 3. Validação de Segurança para Produção / Servidores em Nuvem
$isLocal = in_array(strtolower($dbHost), ['127.0.0.1', 'localhost', 'mysql', 'host.docker.internal'], true);
$appEnv  = strtolower(getenv('APP_ENV') ?: ($isLocal ? 'development' : 'production'));

// Em ambiente de produção ou servidor remoto, credenciais incompletas são bloqueadas
if (!$isLocal || $appEnv === 'production') {
    if (empty($dbHost) || empty($dbName) || empty($dbUser) || $dbPass === '') {
        error_log('[SECURITY CRITICAL] Conexão abortada: credenciais de banco de dados de produção incompletas.');
        http_response_code(503);
        die("Sistema temporariamente indisponível. Por favor, tente novamente mais tarde.");
    }
}

// 4. Configuração de Opções do PDO (com criptografia SSL/TLS em nuvem)
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Conexão criptografada obrigatória para clusters em nuvem (ex: TiDB Cloud, Aiven, AWS RDS)
$enableSsl = getenv('DB_SSL') === 'true' || (!$isLocal && getenv('DB_SSL') !== 'false');
if ($enableSsl && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    // Registra o erro no log interno do servidor e nunca expõe detalhes ao usuário
    error_log("[Wert DB Error] " . $e->getMessage());
    http_response_code(503);
    die("Não foi possível conectar ao sistema com segurança. Tente novamente mais tarde.");
}
