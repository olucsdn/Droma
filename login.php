<?php
require_once "src/Security.php";
require_once "src/RateLimiter.php";
require_once "config/database.php";

Security::initSession();

// Se o usuário já estiver logado, redireciona diretamente ao dashboard
if (!empty($_SESSION['user_id'])) {
    header("Location: ./");
    exit;
}

$erro = $_GET['erro'] ?? '';
$sucesso = $_GET['sucesso'] ?? '';
$precisaVerificar = false;
$emailNaoVerificado = '';
$clientIp = RateLimiter::getClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agora = time();

    // 1. Reenvio de e-mail de confirmação
    if (isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
        $rateCheckResend = RateLimiter::check($pdo, 'resend_email', $clientIp, 3, 600, 900);

        if (!$rateCheckResend['allowed']) {
            $erro = $rateCheckResend['message'];
        } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $erro = "Sessão expirada ou requisição inválida. Tente novamente.";
        } else {
            $resendEmail = trim($_POST['email'] ?? '');
            if (empty($resendEmail) || !filter_var($resendEmail, FILTER_VALIDATE_EMAIL)) {
                $erro = "Informe um e-mail válido para reenviar a confirmação.";
            } else {
                RateLimiter::hit($pdo, 'resend_email', $clientIp, 3, 600, 900);
                try {
                    $stmt = $pdo->prepare("SELECT id, name, email, email_verified_at FROM users WHERE email = ?");
                    $stmt->execute([$resendEmail]);
                    $u = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($u) {
                        if (!empty($u['email_verified_at'])) {
                            $sucesso = "Este e-mail já está confirmado! Você já pode entrar com sua senha.";
                        } else {
                            require_once "src/Mailer.php";
                            $novoCodigo = sprintf("%06d", random_int(100000, 999999));
                            $upd = $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                            $upd->execute([$novoCodigo, $u['id']]);

                            Mailer::sendEmailVerification($u['email'], $u['name'], $novoCodigo);
                            header("Location: verify_email?email=" . urlencode($u['email']) . "&sucesso=" . urlencode("Novo código de 6 dígitos enviado para seu e-mail!"));
                            exit;
                        }
                    } else {
                        $sucesso = "Se este e-mail estiver cadastrado, um link de confirmação foi enviado.";
                    }
                } catch (Throwable $e) {
                    error_log("[Resend Verification Error] " . $e->getMessage());
                    $erro = "Não foi possível enviar o e-mail no momento. Tente novamente mais tarde.";
                }
            }
        }
    } else {
        // 2. Fluxo Normal de Login
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $loginRateKey = $clientIp . ':' . strtolower($email);

        $rateCheck = RateLimiter::check($pdo, 'login', $loginRateKey, 5, 300, 900);

        if (!$rateCheck['allowed']) {
            $erro = $rateCheck['message'];
        } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            $erro = "Sessão expirada ou requisição inválida. Tente novamente.";
        } else {
            if (empty($email) || empty($senha)) {
                $erro = "Por favor, preencha todos os campos.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id, name, email, password, role, email_verified_at, verification_token, api_token FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$user || !password_verify($senha, $user['password'])) {
                        $rateHit = RateLimiter::hit($pdo, 'login', $loginRateKey, 5, 300, 900);
                        if (!$rateHit['allowed']) {
                            $erro = $rateHit['message'];
                        } else {
                            $restantes = $rateHit['remaining'];
                            $avisoTentativas = ($restantes <= 2 && $restantes > 0) ? " (Você tem mais {$restantes} tentativa(s) antes do bloqueio temporário)" : "";
                            $erro = "E-mail ou senha incorretos." . $avisoTentativas;
                        }
                    } elseif (empty($user['email_verified_at'])) {
                        // Sucesso na senha: limpa tentativas de login
                        RateLimiter::clear($pdo, 'login', $loginRateKey);

                        // Senha correta, porém e-mail NÃO verificado -> Redireciona para tela de código de 6 dígitos
                        $code = $user['verification_token'] ?? '';
                        if (strlen($code) !== 6 || !ctype_digit($code)) {
                            $code = sprintf("%06d", random_int(100000, 999999));
                            $upd = $pdo->prepare("UPDATE users SET verification_token = ? WHERE id = ?");
                            $upd->execute([$code, $user['id']]);
                        }
                        require_once "src/Mailer.php";
                        $enviado = false;
                        try {
                            $enviado = Mailer::sendEmailVerification($user['email'], $user['name'], $code);
                        } catch (Throwable $e) {}

                        if ($enviado) {
                            header("Location: verify_email?email=" . urlencode($user['email']) . "&erro=" . urlencode("A verificação de e-mail é obrigatória. Digite o código de 6 dígitos enviado para sua caixa de entrada."));
                            exit;
                        } else {
                            header("Location: verify_email?email=" . urlencode($user['email']) . "&code=" . urlencode($code) . "&aviso=" . urlencode("A verificação de e-mail é obrigatória. (O envio SMTP foi bloqueado pela nuvem Render Free). Seu código de ativação é: {$code}"));
                            exit;
                        }
                    } else {
                        // Sucesso: limpa tentativas no banco e autentica
                        RateLimiter::clear($pdo, 'login', $loginRateKey);
                        unset($_SESSION['login_failed_attempts'], $_SESSION['login_lockout']);
                        session_regenerate_id(true);

                        $_SESSION['user_id']           = $user['id'];
                        $_SESSION['user_name']         = $user['name'];
                        $_SESSION['user_role']         = $user['role'] ?? 'user';
                        $_SESSION['email_verified_at'] = $user['email_verified_at'];

                        // Persistência de login em cookie seguro de 60 dias (Remember Me)
                        $apiToken = $user['api_token'] ?? '';
                        if (empty($apiToken)) {
                            $apiToken = bin2hex(random_bytes(32));
                            $updTok = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
                            $updTok->execute([$apiToken, $user['id']]);
                        }
                        Security::setRememberCookie((int)$user['id'], $apiToken);

                        header("Location: ./");
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log("Erro ao buscar usuário: " . $e->getMessage());
                    $erro = "Erro no servidor. Tente novamente mais tarde.";
                }
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
    <link rel="stylesheet" href="style_login.css?v=<?= filemtime('style_login.css') ?>">
    <title>Droma - Login</title>
</head>
<body>

<div class="brand-header">
    <div class="brand-icon">D</div>
    <div class="brand-text">Droma<span>.</span></div>
</div>

<div>
    <h1>Acesse sua conta</h1>
    <p class="subtitulo-auth">Gestão financeira pessoal com clareza e controle</p>

    <?php if (!empty($erro)): ?>
        <div class="alerta alerta-erro">
            <?= htmlspecialchars($erro) ?>
            <?php if (!empty($precisaVerificar)): ?>
                <form action="login" method="POST" style="margin-top: 12px;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="resend_verification">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($emailNaoVerificado) ?>">
                    <button type="submit" style="background: rgba(212, 175, 108, 0.15); border: 1px solid var(--color-gold); color: var(--color-gold-light); padding: 9px 16px; border-radius: var(--radius-sm); font-size: 13px; cursor: pointer; font-weight: 600; width: 100%; transition: all 0.2s ease;">
                        ✉️ Reenviar E-mail de Confirmação
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sucesso)): ?>
        <div class="alerta alerta-sucesso">
            <?= htmlspecialchars($sucesso) ?>
        </div>
    <?php endif; ?>

    <div class="login">
        <form action="login" method="POST">
            <?= Security::csrfField() ?>
            <input type="email" name="email" placeholder="Seu e-mail" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
            <input type="password" name="senha" placeholder="Sua senha" required>
            <button type="submit">Entrar no Droma</button>
        </form>
    </div>

    <div class="outro">
        <button type="button" onclick="window.location.href='register'">Criar conta</button>
        <button type="button" onclick="window.location.href='recover'">Recuperar senha</button>
    </div>
</div>

</body>
</html>
