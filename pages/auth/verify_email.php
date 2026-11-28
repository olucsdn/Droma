<?php
require_once "src/Security.php";
require_once "src/RateLimiter.php";
require_once "config/database.php";
require_once "src/Mailer.php";

Security::initSession();

$clientIp = RateLimiter::getClientIp();
$email = Security::normalizeEmail($_GET['email'] ?? $_POST['email'] ?? '');
$codigo = trim($_POST['codigo'] ?? '');
// Remove espaços e traços do código
$codigo = preg_replace('/\D/', '', $codigo);

$erro = $_GET['erro'] ?? '';
$sucesso = $_GET['sucesso'] ?? '';
$avisoUrl = $_GET['aviso'] ?? '';
$avisoHtml = '';

// 1. Processamento de Reenvio de Código
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_code') {
    $rateCheckResend = RateLimiter::check($pdo, 'resend_code', $clientIp, 3, 600, 900);

    if (!$rateCheckResend['allowed']) {
        $erro = $rateCheckResend['message'];
    } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = "Sessão expirada. Por favor, tente novamente.";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = "Informe um endereço de e-mail válido para reenviar o código.";
    } else {
        RateLimiter::hit($pdo, 'resend_code', $clientIp, 3, 600, 900);
        try {
            $stmt = $pdo->prepare("SELECT id, name, email, email_verified_at FROM users WHERE LOWER(TRIM(email)) = ?");
            $stmt->execute([$email]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($u) {
                if (empty($u['email_verified_at'])) {
                    $novoCodigo = sprintf("%06d", random_int(100000, 999999));
                    try {
                        $pdo->exec("ALTER TABLE users ADD COLUMN verification_expires_at DATETIME NULL, ADD COLUMN verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0");
                    } catch (Throwable $eCol) {}
                    $upd = $pdo->prepare("UPDATE users SET verification_token = ?, verification_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE), verification_attempts = 0 WHERE id = ?");
                    $upd->execute([$novoCodigo, $u['id']]);

                    $enviado = Mailer::sendEmailVerification($u['email'], $u['name'], $novoCodigo);
                    if ($enviado) {
                        $sucesso = "Se este e-mail estiver cadastrado, um novo código de 6 dígitos foi enviado para sua caixa de entrada e spam.";
                    } else {
                        $avisoHtml = "Houve uma falha temporária no envio do e-mail. Por favor, tente reenviar ou verifique a configuração das chaves BREVO_API_KEY ou RESEND_API_KEY no servidor.";
                    }
                } else {
                    $sucesso = "Se este e-mail estiver cadastrado, um novo código foi enviado.";
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

// 2. Validação do Código (Estritamente via POST digitado pelo usuário)
$deveValidar = ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'verify_code'));

if ($deveValidar) {
    $verifyRateKey = $clientIp . ':' . strtolower($email);
    $rateCheck = RateLimiter::check($pdo, 'verify_code', $verifyRateKey, 5, 600, 900);

    if (!$rateCheck['allowed']) {
        $erro = $rateCheck['message'];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = "Sessão expirada. Por favor, tente novamente.";
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = "Por favor, informe o seu e-mail cadastrado.";
    } elseif (strlen($codigo) !== 6 || !ctype_digit($codigo)) {
        $erro = "O código deve conter exatamente 6 dígitos numéricos.";
    } else {
        try {
            try {
                $pdo->exec("ALTER TABLE users ADD COLUMN verification_expires_at DATETIME NULL, ADD COLUMN verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0");
            } catch (Throwable $eCol) {}

            $stmt = $pdo->prepare("SELECT id, name, email, verification_token, verification_expires_at, verification_attempts, email_verified_at, role FROM users WHERE LOWER(TRIM(email)) = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $expirado = ($user && !empty($user['verification_expires_at']) && strtotime((string)$user['verification_expires_at']) < time());
            $tentativasEsgotadas = ($user && (int)($user['verification_attempts'] ?? 0) >= 10);

            // Comparação em tempo constante contra timing attacks e uniformização estrita de resposta
            $expectedToken = ($user && !empty($user['verification_token'])) ? (string)$user['verification_token'] : '000000';
            $tokenValid = !$expirado && !$tentativasEsgotadas && hash_equals($expectedToken, $codigo) && $user !== false && empty($user['email_verified_at']);

            if (!$tokenValid) {
                if ($user && empty($user['email_verified_at']) && !$expirado && !$tentativasEsgotadas) {
                    $novasTentativas = (int)($user['verification_attempts'] ?? 0) + 1;
                    if ($novasTentativas >= 10) {
                        $pdo->prepare("UPDATE users SET verification_token = NULL, verification_attempts = 10 WHERE id = ?")->execute([$user['id']]);
                    } else {
                        $pdo->prepare("UPDATE users SET verification_attempts = ? WHERE id = ?")->execute([$novasTentativas, $user['id']]);
                    }
                }
                $rateHit = RateLimiter::hit($pdo, 'verify_code', $verifyRateKey, 5, 600, 900);
                if (!$rateHit['allowed']) {
                    $erro = $rateHit['message'];
                } elseif ($expirado || $tentativasEsgotadas) {
                    $erro = "Este código expirou ou excedeu o limite de tentativas. Solicite um novo código.";
                } else {
                    $restantes = $rateHit['remaining'];
                    $avisoTentativas = ($restantes <= 2 && $restantes > 0) ? " (Você tem mais {$restantes} tentativa(s) antes do bloqueio temporário)" : "";
                    $erro = "Código de confirmação incorreto ou e-mail inválido. Por favor, confira os dados ou solicite um novo código." . $avisoTentativas;
                }
            } else {
                // Código correto: ativa a conta e limpa o token e as tentativas de rate limit
                RateLimiter::clear($pdo, 'verify_code', $verifyRateKey);
                $upd = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), verification_token = NULL, verification_attempts = 0, verification_expires_at = NULL WHERE id = ?");
                $upd->execute([$user['id']]);

                // Autentica o usuário imediatamente na sessão
                session_regenerate_id(true);
                $_SESSION['user_id']           = $user['id'];
                $_SESSION['user_name']         = $user['name'];
                $_SESSION['user_role']         = $user['role'] ?? 'user';
                $_SESSION['email_verified_at'] = date('Y-m-d H:i:s');

                // Persistência de login em cookie seguro de 60 dias (Remember Me)
                Security::setRememberCookie((int)$user['id'], null, $pdo);

                Security::setFlash('sucesso', 'Conta verificada com sucesso! Bem-vindo ao Droma.');
                $destUrl = "./";
                if (!headers_sent()) {
                    header("Location: " . $destUrl);
                } else {
                    echo "<script>window.location.href=" . json_encode($destUrl) . ";</script>";
                }
                exit;
            }
        } catch (Throwable $e) {
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
    <link rel="stylesheet" href="assets/css/style_login.css?v=<?= filemtime('assets/css/style_login.css') ?>">
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

    <?php if (!empty($avisoHtml)): ?>
        <div class="alerta alerta-aviso">
            <?= $avisoHtml ?>
        </div>
    <?php elseif (!empty($avisoUrl)): ?>
        <div class="alerta alerta-aviso">
            <?= htmlspecialchars($avisoUrl, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

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
                <input type="email" name="email" placeholder="Seu e-mail cadastrado" required autofocus autocapitalize="none" autocorrect="off" spellcheck="false">
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
                   value="" 
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
