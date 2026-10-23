<?php

// Carrega variáveis do arquivo .env se existir na raiz
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

$dbHost = getenv('DB_HOST') ?: 'sql306.infinityfree.com';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'if0_43252975_wert';
$dbUser = getenv('DB_USER') ?: 'if0_43252975';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'K7mQ9vR2xL5pN8a';

$dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";

try {

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

} catch (PDOException $e) {

    // Loga o erro real em arquivo, nunca mostra pro usuário
    error_log("Erro de conexão com o banco: " . $e->getMessage());
    die("Não foi possível conectar ao sistema. Tente novamente mais tarde.");

}
