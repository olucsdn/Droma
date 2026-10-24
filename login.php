<?php
require_once "src/Security.php";
require_once "config/database.php";

Security::initSession();

// Se o usuário já estiver logado, redireciona diretamente ao dashboard
if (!empty($_SESSION['user_id'])) {
    header("Location: ./");
    exit;
}

$erro = $_GET['erro'] ?? '';
$sucesso = $_GET['sucesso'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $agora = time();

    // 1. Verificação de Bloqueio por Tentativas Excessivas (Rate Limiting)
    if (!empty($_SESSION['login_lockout']) && $_SESSION['login_lockout'] > $agora) {
        $restante = $_SESSION['login_lockout'] - $agora;
        $erro = "Muitas tentativas incorretas. Por segurança, aguarde {$restante} segundos.";
    } elseif (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        // 2. Validação Anti-CSRF
        $erro = "Sessão expirada ou requisição inválida. Tente novamente.";
    } else {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (empty($email) || empty($senha)) {
            $erro = "Por favor, preencha todos os campos.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || !password_verify($senha, $user['password'])) {
                    $_SESSION['login_failed_attempts'] = ($_SESSION['login_failed_attempts'] ?? 0) + 1;

                    // Delay progressivo após 4 tentativas
                    if ($_SESSION['login_failed_attempts'] >= 4) {
                        sleep(2);
                    }

                    // Bloqueio temporário de 60 segundos após 7 falhas
                    if ($_SESSION['login_failed_attempts'] >= 7) {
                        $_SESSION['login_lockout'] = time() + 60;
                        $erro = "Número máximo de tentativas atingido. Acesso bloqueado por 60 segundos.";
                    } else {
                        $erro = "E-mail ou senha incorretos.";
                    }
                } else {
                    // Sucesso: limpa tentativas e autentica
                    unset($_SESSION['login_failed_attempts'], $_SESSION['login_lockout']);
                    session_regenerate_id(true);

                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'] ?? 'user';

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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php require_once "includes/head_favicons.php"; ?>
    <link rel="stylesheet" href="style_login.css?v=<?= filemtime('style_login.css') ?>">
    <title>Droma - Login</title>
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
    <h1>Acesse sua conta</h1>
    <p class="subtitulo-auth">Gestão financeira pessoal com clareza e controle</p>

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

