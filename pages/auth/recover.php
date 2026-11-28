<?php
require_once "src/Security.php";
require_once "src/RateLimiter.php";
require_once "config/database.php";
require_once "src/Mailer.php";

Security::initSession();

if (!empty($_SESSION['user_id'])) {
    header("Location: ./");
    exit;
}

$mensagem = '';
$sucesso = false;
$clientIp = RateLimiter::getClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::ensureNormalizedEmails($pdo);
    $recoverRateKey = $clientIp;
    $rateCheck = RateLimiter::check($pdo, 'recover_password', $recoverRateKey, 3, 600, 900);

    if (!$rateCheck['allowed']) {
        $mensagem = $rateCheck['message'];
    } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $mensagem = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        $email = Security::normalizeEmail($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensagem = 'Informe um e-mail válido.';
        } else {
            RateLimiter::hit($pdo, 'recover_password', $recoverRateKey, 3, 600, 900);
            try {
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE LOWER(TRIM(email)) = ?");
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
<html lang="pt-BR" data-theme="dark" data-theme-fixed="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php 
    $dromaFixedDark = true;
    require_once "includes/head_favicons.php"; 
    ?>
    <link rel="stylesheet" href="assets/css/style_login.css?v=<?= filemtime('assets/css/style_login.css') ?>">
    <title>Droma - Recuperar Senha</title>
</head>
<body>

<div class="brand-header">
    <a href="landing" style="display: inline-flex; align-items: center; gap: 12px; text-decoration: none; color: inherit;" title="Voltar para a página inicial">
        <div class="brand-icon">D</div>
        <div class="brand-text">Droma<span>.</span></div>
    </a>
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
            <input type="email" name="email" placeholder="Digite seu e-mail cadastrado" required autofocus autocapitalize="none" autocorrect="off" spellcheck="false">
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
