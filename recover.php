<?php
require_once "src/Security.php";
require_once "config/database.php";
require_once "src/Mailer.php";

Security::initSession();

if (!empty($_SESSION['user_id'])) {
    header("Location: ./");
    exit;
}

$mensagem = '';
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensagem = 'Informe um e-mail válido.';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $token = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora de validade

                    $upd = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE id = ?");
                    $upd->execute([$token, $expiresAt, $user['id']]);

                    Mailer::sendPasswordReset($user['email'], $user['name'], $token);
                }

                // Mensagem uniforme para segurança (prevenção contra enumeração de e-mails)
                $mensagem = 'Se houver uma conta associada a este e-mail, as instruções de recuperação foram enviadas para sua caixa de entrada (verifique também a pasta de Spam).';
                $sucesso = true;
            } catch (Throwable $e) {
                error_log("[Recover Error] " . $e->getMessage());
                $mensagem = 'Ocorreu um erro no servidor. Por favor, tente novamente mais tarde.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <link rel="stylesheet" href="style_login.css?v=<?= filemtime('style_login.css') ?>">
    <title>Droma - Recuperar Senha</title>
</head>
<body>

<div style="position: fixed; top: 20px; right: 20px; z-index: 1000;">
    <button type="button" class="btn-theme-toggle" id="btnThemeToggle" title="Alternar Modo Claro / Escuro" aria-label="Alternar Modo Claro / Escuro" onclick="window.toggleDromaTheme()">
        <span class="theme-icon-dark" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96a.996.996 0 0 0 0-1.41.996.996 0 0 0-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.36a.996.996 0 0 0 0-1.41.996.996 0 0 0 0 1.41l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/></svg>
        </span>
        <span class="theme-icon-light" aria-hidden="true" style="display:none;">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12.3 2a10 10 0 0 0-1.9 20 10 10 0 0 0 9.6-6.4 1 1 0 0 0-1.2-1.3 8 8 0 1 1-7.8-11.1 1 1 0 0 0-1.3-1.2h2.6z"/></svg>
        </span>
    </button>
</div>

<div class="brand-header">
    <div class="brand-icon">D</div>
    <div class="brand-text">Droma<span>.</span></div>
</div>

<div>
    <h1>Recuperar senha</h1>
    <p class="subtitulo-auth">Instruções seguras serão enviadas para o seu e-mail</p>

    <?php if (!empty($mensagem)): ?>
        <div class="alerta <?= $sucesso ? 'alerta-sucesso' : 'alerta-erro' ?>">
            <?= htmlspecialchars($mensagem) ?>
        </div>
    <?php endif; ?>

    <div class="login">
        <form action="recover" method="POST">
            <?= Security::csrfField() ?>
            <input type="email" name="email" placeholder="Digite seu e-mail cadastrado" required autofocus>
            <button type="submit">Enviar instruções</button>
        </form>
    </div>

    <div class="outro">
        <button type="button" onclick="window.location.href='login'">Voltar ao Login</button>
        <button type="button" onclick="window.location.href='register'">Criar Conta</button>
    </div>
</div>
</body>
</html>
