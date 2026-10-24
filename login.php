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

