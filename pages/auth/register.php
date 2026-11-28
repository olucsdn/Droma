<?php
require_once "src/Security.php";
require_once "src/RateLimiter.php";
require_once "config/database.php";
require_once "src/Mailer.php";

Security::initSession();

// Se o usuário já estiver logado, redireciona ao dashboard
if (!empty($_SESSION['user_id'])) {
    header("Location: ./");
    exit;
}

$erro = '';
$nome = '';
$email = '';
$clientIp = RateLimiter::getClientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::ensureNormalizedEmails($pdo);
    $rateCheck = RateLimiter::check($pdo, 'register', $clientIp, 5, 3600, 1800);

    if (!$rateCheck['allowed']) {
        $erro = $rateCheck['message'];
    } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada ou requisição inválida. Por favor, tente novamente.';
    } else {
        $nome           = trim($_POST['nome'] ?? '');
        $email          = Security::normalizeEmail($_POST['email'] ?? '');
        $senha          = $_POST['senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';
        $aceitouTermos  = !empty($_POST['aceitou_termos']);

        if (empty($nome) || empty($email) || empty($senha) || empty($confirmarSenha)) {
            $erro = 'Por favor, preencha todos os campos.';
        } elseif (!$aceitouTermos) {
            $erro = 'Você deve ler e concordar com os Termos de Uso e a Política de Privacidade para criar sua conta.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um endereço de e-mail válido.';
        } elseif (($pwdValidation = Security::validatePasswordStrength($senha, $nome, $email)) && !$pwdValidation['valido']) {
            $erro = $pwdValidation['erro'];
        } elseif ($senha !== $confirmarSenha) {
            $erro = 'As senhas digitadas não coincidem.';
        } else {
            try {
                // Inicia transação atômica para garantir criação segura de conta e integridade multi-tabela
                $pdo->beginTransaction();

                // 1. Verifica se o e-mail já está cadastrado (insensível a maiúsculas/espaços e com lock FOR UPDATE para prevenir race conditions)
                $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? FOR UPDATE");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    $erro = 'Este e-mail já está cadastrado. Tente fazer login ou use outro.';
                } else {
                    $hash = Security::hashPassword($senha);
                    $verificationCode = sprintf("%06d", random_int(100000, 999999));
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, verification_token) VALUES (?, ?, ?, 'user', ?)");
                    $stmt->execute([$nome, $email, $hash, $verificationCode]);
                    $userId = (int) $pdo->lastInsertId();

                    // Cria uma conta inicial padrão
                    $stmtAccount = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Carteira Principal', 'checking', 0.00)");
                    $stmtAccount->execute([$userId]);

                    // Categorias essenciais padrão para o novo usuário
                    $categoriasIniciais = [
                        ['Alimentação', 'expense'],
                        ['Moradia', 'expense'],
                        ['Transporte', 'expense'],
                        ['Lazer', 'expense'],
                        ['Saúde', 'expense'],
                        ['Educação', 'expense'],
                        ['Salário', 'income'],
                        ['Investimentos', 'income'],
                        ['Outros', 'income']
                    ];

                    $stmtCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)");
                    foreach ($categoriasIniciais as [$catNome, $catTipo]) {
                        $stmtCat->execute([$userId, $catNome, $catTipo]);
                    }

                    $pdo->commit();

                    // Registra formalmente o aceite dos termos e privacidade com timestamp
                    Security::recordTermsAcceptance($pdo, $userId);
                    $_SESSION['terms_accepted_at'] = date('Y-m-d H:i:s');

                    // Contabiliza a criação de conta no rate limiting por IP
                    RateLimiter::hit($pdo, 'register', $clientIp, 5, 3600, 1800);

                    // Dispara o e-mail transacional com o código de 6 dígitos
                    $enviado = false;
                    try {
                        $enviado = Mailer::sendEmailVerification($email, $nome, $verificationCode);
                    } catch (Throwable $mailEx) {
                        error_log("[Register Mailer Error] " . $mailEx->getMessage());
                    }

                    $redirectUrl = $enviado
                        ? "verify_email?email=" . urlencode($email) . "&sucesso=" . urlencode("Conta criada com sucesso! Enviamos um código de 6 dígitos para {$email}.")
                        : "verify_email?email=" . urlencode($email) . "&aviso=" . urlencode("Conta criada com sucesso! Houve uma falha temporária no envio do e-mail. Solicite o reenvio do código ou configure BREVO_API_KEY / RESEND_API_KEY no servidor.");

                    if (!headers_sent()) {
                        header("Location: " . $redirectUrl);
                    } else {
                        echo "<script>window.location.href=" . json_encode($redirectUrl) . ";</script>";
                    }
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Erro no cadastro de usuário: " . $e->getMessage());

                // Tratamento seguro contra duplicidade disparada pelo índice UNIQUE do banco de dados
                $isDuplicate = ($e instanceof PDOException) && (
                    $e->getCode() == 23000 || 
                    str_contains($e->getMessage(), 'Duplicate entry') || 
                    str_contains($e->getMessage(), '1062') ||
                    str_contains($e->getMessage(), 'uq_users_email') ||
                    str_contains($e->getMessage(), 'email')
                );

                if ($isDuplicate) {
                    $erro = 'Este e-mail já está cadastrado. Tente fazer login ou use outro.';
                } else {
                    $erro = 'Ocorreu um erro ao processar o cadastro. Tente novamente mais tarde.';
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
    <link rel="stylesheet" href="assets/css/style_login.css?v=<?= filemtime('assets/css/style_login.css') ?>">
    <title>Droma - Criar Conta</title>
</head>
<body>

<div class="brand-header">
    <a href="landing" style="display: inline-flex; align-items: center; gap: 12px; text-decoration: none; color: inherit;" title="Voltar para a página inicial">
        <div class="brand-icon">D</div>
        <div class="brand-text">Droma<span>.</span></div>
    </a>
</div>

<div>
    <h1>Crie sua conta</h1>
    <p class="subtitulo-auth">Dê o primeiro passo para organizar suas finanças</p>

    <?php if (!empty($erro)): ?>
        <div class="alerta alerta-erro">
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <div class="login">
        <form action="register" method="POST" id="formCadastro" onsubmit="var btn=document.getElementById('btnSubmitRegister'); if(btn){ btn.disabled=true; btn.innerText='Criando conta...'; } return true;">
            <?= Security::csrfField() ?>
            <input type="text" name="nome" placeholder="Seu nome completo" value="<?= htmlspecialchars($nome) ?>" required autofocus>
            <input type="email" name="email" placeholder="Seu melhor e-mail" value="<?= htmlspecialchars($email) ?>" required autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="email">
            <input type="password" name="senha" id="senha" placeholder="Senha (mínimo 8 caracteres)" minlength="8" required data-password-meter autocomplete="new-password">
            <div class="password-meter-wrap" id="password-meter-senha" aria-live="polite">
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
            <input type="password" name="confirmar_senha" placeholder="Confirme sua senha" minlength="8" required autocomplete="new-password">
            <div class="auth-terms-container">
                <label class="auth-terms-label">
                    <input type="checkbox" name="aceitou_termos" value="1" required <?= !empty($_POST['aceitou_termos']) ? 'checked' : '' ?>>
                    <span>Li e concordo com os <a href="terms" target="_blank">Termos de Uso</a> e a <a href="terms#privacidade" target="_blank">Política de Privacidade</a>.</span>
                </label>
            </div>
            <button type="submit" id="btnSubmitRegister">Começar agora</button>
        </form>
    </div>

    <div class="outro">
        <button type="button" onclick="window.location.href='login'">Já tenho conta</button>
        <button type="button" onclick="window.location.href='recover'">Recuperar senha</button>
    </div>
</div>

<script src="js/password-meter.js?v=<?= filemtime('js/password-meter.js') ?>" defer></script>
</body>
</html>


