<?php
require_once "src/Security.php";
require_once "config/database.php";

Security::initSession();

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    header("Location: login?erro=" . urlencode("Token de confirmação de e-mail não informado."));
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, email, email_verified_at FROM users WHERE verification_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header("Location: login?erro=" . urlencode("Link de confirmação inválido ou que já foi utilizado anteriormente."));
        exit;
    }

    $upd = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?");
    $upd->execute([$user['id']]);

    if (!empty($_SESSION['user_id'])) {
        header("Location: profile?sucesso=" . urlencode("E-mail confirmado com sucesso! Sua conta está 100% verificada e ativa."));
        exit;
    }

    header("Location: login?sucesso=" . urlencode("E-mail confirmado com sucesso! Sua conta está ativada. Faça login para começar."));
    exit;
} catch (PDOException $e) {
    error_log("[Verify Email Error] " . $e->getMessage());
    header("Location: login?erro=" . urlencode("Erro no servidor ao validar o e-mail. Tente novamente mais tarde."));
    exit;
}
