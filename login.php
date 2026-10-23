<?php
require_once "src/Security.php";
require_once "config/database.php";

Security::initSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: paginalogar.php");
    exit;
}

// 1. Verificação de Bloqueio por Tentativas Excessivas (Rate Limiting)
$agora = time();
if (!empty($_SESSION['login_lockout']) && $_SESSION['login_lockout'] > $agora) {
    $restante = $_SESSION['login_lockout'] - $agora;
    header("Location: paginalogar.php?erro=" . urlencode("Muitas tentativas incorretas. Por segurança, aguarde {$restante} segundos."));
    exit;
}

// 2. Validação Anti-CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!Security::validateCsrf($csrfToken)) {
    header("Location: paginalogar.php?erro=" . urlencode("Sessão expirada ou requisição inválida. Tente novamente."));
    exit;
}

$email = trim($_POST['email'] ?? '');
$senha = $_POST['senha'] ?? '';

if (empty($email) || empty($senha)) {
    header("Location: paginalogar.php?erro=" . urlencode("Por favor, preencha todos os campos."));
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar usuário: " . $e->getMessage());
    header("Location: paginalogar.php?erro=" . urlencode("Erro no servidor. Tente novamente mais tarde."));
    exit;
}

if (!$user || !password_verify($senha, $user['password'])) {
    // Incrementa contador de tentativas falhas
    $_SESSION['login_failed_attempts'] = ($_SESSION['login_failed_attempts'] ?? 0) + 1;

    // Delay progressivo após 4 tentativas para conter scripts de força bruta
    if ($_SESSION['login_failed_attempts'] >= 4) {
        sleep(2);
    }

    // Bloqueio temporário de 60 segundos após 7 falhas consecutivas
    if ($_SESSION['login_failed_attempts'] >= 7) {
        $_SESSION['login_lockout'] = time() + 60;
        header("Location: paginalogar.php?erro=" . urlencode("Número máximo de tentativas atingido. Acesso bloqueado por 60 segundos."));
        exit;
    }

    header("Location: paginalogar.php?erro=" . urlencode("E-mail ou senha incorretos."));
    exit;
}

// Sucesso: reseta tentativas e lockout
unset($_SESSION['login_failed_attempts'], $_SESSION['login_lockout']);

// Regenera o ID da sessão para prevenir Session Fixation
session_regenerate_id(true);

$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_role'] = $user['role'] ?? 'user';

header("Location: index.php");
exit;