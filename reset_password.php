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
        } elseif (mb_strlen($novaSenha) < 6) {
            $erro = 'A nova senha deve ter no mínimo 6 caracteres.';
        } elseif ($novaSenha !== $confirmarSenha) {
            $erro = 'As senhas digitadas não conferem. Verifique e tente novamente.';
        } else {
            try {
                $hash = password_hash($novaSenha, PASSWORD_DEFAULT);
                $upd = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires_at = NULL WHERE id = ?");
                $upd->execute([$hash, $user['id']]);

                header("Location: login?sucesso=" . urlencode("Sua senha foi redefinida com sucesso! Você já pode entrar com a nova senha."));
                exit;
            } catch (PDOException $e) {
                error_log("[Reset Password Update Error] " . $e->getMessage());
                $erro = 'Erro ao atualizar a senha no banco de dados. Tente novamente mais tarde.';
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
    <title>Droma - Redefinir Senha</title>
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
                
                <input type="password" name="nova_senha" placeholder="Nova senha (mínimo 6 caracteres)" minlength="6" required autofocus>
                <input type="password" name="confirmar_senha" placeholder="Confirme a nova senha" minlength="6" required>
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
</body>
</html>
