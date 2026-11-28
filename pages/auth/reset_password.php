<?php
require_once "src/Security.php";
require_once "config/database.php";

Security::initSession();

if (!empty($_SESSION['user_id'])) {
    header("Location: ./");
    exit;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$erro = '';
$user = null;

if (empty($token)) {
    $erro = 'Link ou chave de recuperação inválido ou não fornecido.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT id, name, email, reset_expires_at FROM users WHERE reset_token = ?");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $erro = 'Este link de recuperação é inválido ou já foi utilizado anteriormente.';
        } elseif (strtotime($user['reset_expires_at']) < time()) {
            $erro = 'Este link de recuperação expirou. Por motivos de segurança, solicite um novo link.';
            $user = null;
        }
    } catch (PDOException $e) {
        error_log("[Reset Password Error] " . $e->getMessage());
        $erro = 'Ocorreu um erro no servidor ao validar a solicitação. Tente novamente mais tarde.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada ou requisição inválida. Tente novamente.';
    } else {
        $novaSenha = $_POST['nova_senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';

        if (empty($novaSenha) || empty($confirmarSenha)) {
            $erro = 'Por favor, preencha todos os campos.';
        } elseif (($pwdValidation = Security::validatePasswordStrength($novaSenha, $user['name'] ?? '', $user['email'] ?? '')) && !$pwdValidation['valido']) {
            $erro = $pwdValidation['erro'];
        } elseif ($novaSenha !== $confirmarSenha) {
            $erro = 'As senhas digitadas não conferem. Verifique e tente novamente.';
        } else {
            try {
                $hash = Security::hashPassword($novaSenha);
                $upd = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires_at = NULL, remember_token = NULL WHERE id = ?");
                $upd->execute([$hash, $user['id']]);

                Security::revokeAllSessions($pdo, (int)$user['id']);

                require_once "src/Mailer.php";
                try {
                    Mailer::sendSecurityPasswordChanged($user['email'], $user['name']);
                } catch (Throwable $mailEx) {
                    error_log("[Reset Password Alert Mailer Error] " . $mailEx->getMessage());
                }

                Security::flashRedirect('login', 'sucesso', 'Sua senha foi redefinida com sucesso! Você já pode entrar com a nova senha.');
            } catch (PDOException $e) {
                error_log("[Reset Password Update Error] " . $e->getMessage());
                $erro = 'Erro ao atualizar a senha no banco de dados. Tente novamente mais tarde.';
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
    <title>Droma - Redefinir Senha</title>
</head>
<body>

<div class="brand-header">
    <div class="brand-icon">D</div>
    <div class="brand-text">Droma<span>.</span></div>
</div>

<div>
    <h1>Nova Senha</h1>
    <p class="subtitulo-auth">Cadastre uma nova senha de acesso para sua conta</p>

    <?php if (!empty($erro)): ?>
        <div class="alerta alerta-erro">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if ($user): ?>
        <div class="login">
            <form action="reset_password" method="POST">
                <?= Security::csrfField() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <input type="password" name="nova_senha" id="nova_senha" placeholder="Nova senha (mínimo 8 caracteres)" minlength="8" required autofocus data-password-meter>
                <div class="password-meter-wrap" id="password-meter-nova_senha" aria-live="polite">
                    <div class="password-meter-bar">
                        <div class="password-meter-fill"></div>
                    </div>
                    <div class="password-meter-info">
                        <span class="password-meter-text">Mínimo de 8 caracteres</span>
                        <span class="password-meter-rules">
                            <span class="rule-item" data-rule="length">8+ chars</span>
                            <span class="rule-item" data-rule="upper">A-Z</span>
                            <span class="rule-item" data-rule="lower">a-z</span>
                            <span class="rule-item" data-rule="number">0-9</span>
                        </span>
                    </div>
                </div>
                <input type="password" name="confirmar_senha" placeholder="Confirme a nova senha" minlength="8" required>
                <button type="submit">Salvar nova senha</button>
            </form>
        </div>
        <div class="outro">
            <button type="button" onclick="window.location.href='login'">Cancelar e Voltar</button>
        </div>
    <?php else: ?>
        <div class="outro" style="margin-top: 20px;">
            <button type="button" onclick="window.location.href='recover'">Solicitar novo link</button>
            <button type="button" onclick="window.location.href='login'">Voltar ao Login</button>
        </div>
    <?php endif; ?>
</div>

<script src="js/password-meter.js?v=<?= filemtime('js/password-meter.js') ?>" defer></script>
</body>
</html>
