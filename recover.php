<?php
require_once "src/Security.php";
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
            // Simulação/preparação para envio de token/e-mail
            $mensagem = 'Se houver uma conta associada a este e-mail, as instruções de recuperação serão enviadas.';
            $sucesso = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style_login.css?v=<?= filemtime('style_login.css') ?>">
    <title>Droma - Recuperar Senha</title>
</head>
<body>

<div class="brand-header">
    <div class="brand-icon">D</div>
    <div class="brand-text">Droma<span>.</span></div>
</div>

<div>
    <h1>Recuperar senha</h1>
    <p class="subtitulo-auth">Instruções serão enviadas para o seu e-mail</p>

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

