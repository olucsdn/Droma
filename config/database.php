<?php
@ini_set('expose_php', 'off');

/**
 * Configuração de Conexão com o Banco de Dados — Projeto Droma
 * Suporte a ambientes de Produção em Nuvem e Desenvolvimento Seguro (SSL/TLS)
 */

date_default_timezone_set('America/Sao_Paulo');

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
                if (!isset($_ENV[$k])) {
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                    if (getenv($k) === false) {
                        @putenv("$k=$v");
                    }
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

// 4. Configuração de Opções do PDO (sem poluição de estado em workers e UTF-8 Completo)
$mysqlInitCommand = defined('Pdo\Mysql::ATTR_INIT_COMMAND')
    ? Pdo\Mysql::ATTR_INIT_COMMAND
    : (defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? PDO::MYSQL_ATTR_INIT_COMMAND : 1002);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false, // Desativa reuso de conexões PDO para evitar vazamento de transações órfãs entre requisições
];
if ($mysqlInitCommand) {
    $options[$mysqlInitCommand] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
}

// Conexão criptografada obrigatória para clusters em nuvem (ex: TiDB Cloud, Aiven, AWS RDS)
$enableSsl = getenv('DB_SSL') === 'true' || (!$isLocal && getenv('DB_SSL') !== 'false');
if ($enableSsl) {
    // Procura o certificado CA oficial (bundle do Let's Encrypt / ISRG Root X1 ou do sistema)
    $caPaths = [
        __DIR__ . '/ca.pem',                  // Certificado Let's Encrypt / TiDB Cloud
        '/etc/ssl/certs/ca-certificates.crt', // Debian / Ubuntu / Nosso Dockerfile
        '/etc/pki/tls/certs/ca-bundle.crt',   // RedHat / CentOS
        '/etc/ssl/cert.pem',                  // Alpine / macOS
    ];
    $foundCa = false;
    foreach ($caPaths as $path) {
        if (file_exists($path)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $path;
            $foundCa = true;
            break;
        }
    }

    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $foundCa;
    }
}

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET CHARACTER SET utf8mb4");
    try {
        $pdo->exec("SET time_zone = '-03:00'");
    } catch (Throwable) {}

    // Blindagem AppSec (CVE-2026-109618/19): garante reversão de transações pendentes se o worker sofrer OOM ou erro fatal
    register_shutdown_function(static function() use (&$pdo): void {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            try {
                $pdo->rollBack();
                error_log('[SECURITY APSEC] Transação PDO pendente revertida com sucesso no shutdown do worker.');
            } catch (Throwable) {}
        }
    });

    // Correção automática de categorias com caracteres corrompidos por encoding anterior
    try {
        $pdo->exec("UPDATE categories SET name = 'Alimentação' WHERE (name LIKE 'Alimenta%' AND name LIKE '%o' AND name <> 'Alimentação') OR name = 'Alimenta??o'");
        $pdo->exec("UPDATE categories SET name = 'Educação' WHERE (name LIKE 'Educa%' AND name LIKE '%o' AND name <> 'Educação') OR name = 'Educa??o'");
        $pdo->exec("UPDATE categories SET name = 'Saúde' WHERE (name LIKE 'Sa%de' AND name <> 'Saúde') OR name = 'Sa?de'");
        $pdo->exec("UPDATE categories SET name = 'Salário' WHERE (name LIKE 'Sal%rio' AND name <> 'Salário') OR name = 'Sal?rio'");
        $pdo->exec("UPDATE categories SET name = 'Transferência' WHERE (name LIKE 'Transfer%ncia' AND name <> 'Transferência') OR name = 'Transfer?ncia'");
    } catch (Throwable $e) {}
} catch (PDOException $e) {
    // Registra o erro no log interno do servidor e nunca expõe detalhes ao usuário
    error_log("[Droma DB Error] " . $e->getMessage());
    http_response_code(503);
    die("Não foi possível conectar ao sistema com segurança. Tente novamente mais tarde.");
}
