<?php
require_once "src/Security.php";
require_once "config/database.php";
require_once "src/Mailer.php";

Security::initSession();

$email = trim($_GET['email'] ?? $_POST['email'] ?? '');
$codigo = trim($_GET['code'] ?? $_GET['token'] ?? $_POST['codigo'] ?? '');
// Remove espaços e traços do código
$codigo = preg_replace('/\D/', '', $codigo);

$erro = $_GET['erro'] ?? '';
$sucesso = $_GET['sucesso'] ?? '';

// 1. Processamento de Reenvio de Código
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_code') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = "Sessão expirada. Por favor, tente novamente.";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = "Informe um endereço de e-mail válido para reenviar o código.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, email_verified_at FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($u) {
                if (!empty($u['email_verified_at'])) {
                    $sucesso = "Este e-mail já está verificado! Você já pode entrar no sistema.";
                } else {
                    $novoCodigo = sprintf("%06d", random_int(100000, 999999));
                    $upd = $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                    $upd->execute([$novoCodigo, $u['id']]);

                    Mailer::sendEmailVerification($u['email'], $u['name'], $novoCodigo);
                    $sucesso = "Novo código de 6 dígitos enviado para " . htmlspecialchars($u['email']) . "! Verifique sua caixa de entrada e spam.";
                }
            } else {
                $sucesso = "Se este e-mail estiver cadastrado, um novo código foi enviado.";
            }
        } catch (Throwable $e) {
            error_log("[Resend Code Error] " . $e->getMessage());
            $erro = "Erro ao enviar o e-mail no momento. Tente novamente mais tarde.";
        }
    }
}

// 2. Validação do Código (Via POST ou via GET com parâmetros completos)
$deveValidar = ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'verify_code')) 
            || (!empty($_GET['email']) && !empty($codigo) && strlen($codigo) === 6);

if ($deveValidar) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = "Sessão expirada. Por favor, tente novamente.";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = "Por favor, informe o seu e-mail cadastrado.";
    } elseif (strlen($codigo) !== 6 || !ctype_digit($codigo)) {
        $erro = "O código deve conter exatamente 6 dígitos numéricos.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, verification_token, email_verified_at, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $erro = "Usuário não encontrado. Verifique o e-mail digitado.";
            } elseif (!empty($user['email_verified_at'])) {
                $sucesso = "Este e-mail já foi verificado anteriormente! Redirecionando para o login...";
                header("Location: login?sucesso=" . urlencode("Seu e-mail já está verificado. Faça login para acessar."));
                exit;
            } elseif ($user['verification_token'] !== $codigo) {
                $erro = "Código incorreto. Por favor, confira os 6 dígitos recebidos no seu e-mail.";
            } else {
                // Código correto: ativa a conta e limpa o token
                $upd = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), verification_token = NULL WHERE id = ?");
                $upd->execute([$user['id']]);

                // Autentica o usuário imediatamente na sessão
                session_regenerate_id(true);
                $_SESSION['user_id']           = $user['id'];
                $_SESSION['user_name']         = $user['name'];
                $_SESSION['user_role']         = $user['role'] ?? 'user';
                $_SESSION['email_verified_at'] = date('Y-m-d H:i:s');

                header("Location: ./?sucesso=" . urlencode("Conta verificada com sucesso! Bem-vindo ao Droma."));
                exit;
            }
        } catch (PDOException $e) {
            error_log("[Verify Email Error] " . $e->getMessage());
            $erro = "Erro no servidor ao validar o código. Tente novamente mais tarde.";
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
    <link rel="stylesheet" href="style_login.css?v=<?= filemtime('style_login.css') ?>">
    <title>Droma - Verificação de E-mail</title>
</head>
<body>

<div class="brand-header">
    <div class="brand-icon">D</div>
    <div class="brand-text">Droma<span>.</span></div>
</div>

<div>
    <h1>Confirme seu e-mail</h1>
    <p class="subtitulo-auth">
        <?php if (!empty($email)): ?>
            Enviamos um código de 6 dígitos para:<br>
            <strong style="color: var(--color-gold); font-size: 14px;"><?= htmlspecialchars($email) ?></strong>
        <?php else: ?>
            Informe o e-mail cadastrado e o código de 6 dígitos recebido
        <?php endif; ?>
    </p>

    <?php if (!empty($erro)): ?>
        <div class="alerta alerta-erro">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div class="alerta alerta-sucesso">
            <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>

    <div class="login">
        <form action="verify_email" method="POST" id="form-verify">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="verify_code">

            <?php if (!empty($email)): ?>
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <?php else: ?>
                <input type="email" name="email" placeholder="Seu e-mail cadastrado" required autofocus>
            <?php endif; ?>

            <label style="font-size: 12px; font-weight: 600; color: var(--color-text-secondary); text-align: center; display: block; margin-bottom: 6px;">
                CÓDIGO DE 6 DÍGITOS
            </label>
            <input type="text" 
                   name="codigo" 
                   id="input-codigo" 
                   class="input-codigo-verificacao" 
                   inputmode="numeric" 
                   pattern="[0-9]{6}" 
                   maxlength="6" 
                   placeholder="------" 
                   value="<?= htmlspecialchars(strlen($codigo) === 6 ? $codigo : '') ?>" 
                   required 
                   autofocus 
                   autocomplete="one-time-code">

            <button type="submit" id="btn-submit">Confirmar Código</button>
        </form>

        <!-- Formulário para Reenviar Código -->
        <form action="verify_email" method="POST" style="margin-top: 14px; text-align: center;">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="resend_code">
            <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
            <button type="submit" style="background: none; border: none; color: var(--color-gold); font-size: 13px; font-weight: 600; text-decoration: underline; cursor: pointer; padding: 6px 12px;">
                ✉️ Não recebeu? Reenviar novo código
            </button>
        </form>
    </div>

    <div class="outro" style="margin-top: 20px; padding-top: 16px;">
        <button type="button" onclick="window.location.href='login'">Voltar ao Login</button>
        <button type="button" onclick="window.location.href='register'">Criar outra conta</button>
    </div>
</div>

<script>
// Auto-submete o formulário quando os 6 dígitos forem digitados
document.getElementById('input-codigo').addEventListener('input', function(e) {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    if (this.value.length === 6) {
        document.getElementById('form-verify').submit();
    }
});
</script>

</body>
</html>
