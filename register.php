<?php
require_once "src/Security.php";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
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
                    $verificationToken = bin2hex(random_bytes(32));
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, api_token, role, verification_token) VALUES (?, ?, ?, ?, 'user', ?)");
                    $stmt->execute([$nome, $email, $hash, $apiToken, $verificationToken]);
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

                    // Dispara o e-mail transacional de confirmação de conta
                    try {
                        Mailer::sendEmailVerification($email, $nome, $verificationToken);
                    } catch (Throwable $mailEx) {
                        error_log("[Register Mailer Error] " . $mailEx->getMessage());
                    }

                    // Autentica o usuário automaticamente
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $userId;
                    $_SESSION['user_name'] = $nome;
                    $_SESSION['user_role'] = 'user';

                    header("Location: ./");
                    exit;
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
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <link rel="stylesheet" href="style_login.css?v=<?= filemtime('style_login.css') ?>">
    <title>Droma - Criar Conta</title>
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


