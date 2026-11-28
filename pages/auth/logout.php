<?php
require_once "src/Security.php";
require_once "config/database.php";
Security::initSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Security::validateCsrf($_POST['csrf_token'] ?? '')) {
    header("Location: ./");
    exit;
}

// Revoga o remember_token no servidor antes de destruir a sessão
if (!empty($_SESSION['user_id']) && isset($pdo)) {
    try {
        $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([(int)$_SESSION['user_id']]);
    } catch (Throwable $e) {
        error_log("Erro ao revogar remember_token no logout: " . $e->getMessage());
    }
}

// Remove o cookie persistente de "Lembrar de mim"
Security::clearRememberCookie();

// Limpa todas as variáveis de sessão
$_SESSION = [];

// Invalida o cookie da sessão se existir
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destrói a sessão
session_destroy();

// Solicita aos navegadores compatíveis a purga imediata do armazenamento do cliente (localStorage / IndexedDB)
header('Clear-Site-Data: "storage"');

// Redireciona para a página inicial de apresentação
header("Location: landing");
exit;


