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
    $rateCheck = RateLimiter::check($pdo, 'register', $clientIp, 5, 3600, 1800);

    if (!$rateCheck['allowed']) {
        $erro = $rateCheck['message'];
    } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada ou requisição inválida. Por favor, tente novamente.';
    } else {
        $nome           = trim($_POST['nome'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $senha          = $_POST['senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';

        if (empty($nome) || empty($email) || empty($senha) || empty($confirmarSenha)) {
            $erro = 'Por favor, preencha todos os campos.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um endereço de e-mail válido.';
        } elseif (mb_strlen($senha) < 6) {
            $erro = 'A senha deve conter no mínimo 6 caracteres.';
        } elseif ($senha !== $confirmarSenha) {
            $erro = 'As senhas digitadas não coincidem.';
        } else {
            try {
                // Verifica se o e-mail já está cadastrado
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);

                if ($stmt->fetch()) {
                    $erro = 'Este e-mail já está cadastrado. Tente fazer login ou use outro.';
                } else {
                    // Inicia transação para criar usuário e dados iniciais
                    $pdo->beginTransaction();

                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    $apiToken = bin2hex(random_bytes(32));
                    $verificationCode = sprintf("%06d", random_int(100000, 999999));
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, api_token, role, verification_token) VALUES (?, ?, ?, ?, 'user', ?)");
                    $stmt->execute([$nome, $email, $hash, $apiToken, $verificationCode]);
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

                    // Contabiliza a criação de conta no rate limiting por IP
                    RateLimiter::hit($pdo, 'register', $clientIp, 5, 3600, 1800);

                    // Dispara o e-mail transacional com o código de 6 dígitos
                    $enviado = false;
                    try {
                        $enviado = Mailer::sendEmailVerification($email, $nome, $verificationCode);
                    } catch (Throwable $mailEx) {
                        error_log("[Register Mailer Error] " . $mailEx->getMessage());
                    }

                    if ($enviado) {
                        header("Location: verify_email?email=" . urlencode($email) . "&sucesso=" . urlencode("Conta criada com sucesso! Enviamos um código de 6 dígitos para {$email}."));
                        exit;
                    } else {
                        // Se o provedor em nuvem bloquear a porta SMTP (ex: Render Free Tier)
                        header("Location: verify_email?email=" . urlencode($email) . "&code=" . urlencode($verificationCode) . "&aviso=" . urlencode("Conta criada com sucesso! (Aviso: O envio SMTP foi bloqueado pela nuvem Render Free). Preenchemos seu código de ativação automaticamente abaixo:"));
                        exit;
                    }
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Erro no cadastro de usuário: " . $e->getMessage());
                $erro = 'Ocorreu um erro ao processar o cadastro. Tente novamente mais tarde.';
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
    <title>Droma - Criar Conta</title>
</head>
<body>

<div class="brand-header">
    <div class="brand-icon">D</div>
    <div class="brand-text">Droma<span>.</span></div>
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
        <form action="register" method="POST">
            <?= Security::csrfField() ?>
            <input type="text" name="nome" placeholder="Seu nome completo" value="<?= htmlspecialchars($nome) ?>" required autofocus>
            <input type="email" name="email" placeholder="Seu melhor e-mail" value="<?= htmlspecialchars($email) ?>" required>
            <input type="password" name="senha" placeholder="Senha (mínimo 6 caracteres)" minlength="6" required>
            <input type="password" name="confirmar_senha" placeholder="Confirme sua senha" minlength="6" required>
            <button type="submit">Começar agora</button>
        </form>
    </div>

    <div class="outro">
        <button type="button" onclick="window.location.href='login'">Já tenho conta</button>
        <button type="button" onclick="window.location.href='recover'">Recuperar senha</button>
    </div>
</div>
</body>
</html>


