<?php
require_once 'config/database.php';
require_once 'src/Security.php';
require_once 'src/ApiAuth.php';

$userId = Security::requireAuth();
$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

$stmt = $pdo->prepare('SELECT id, name, email, password, api_token FROM users WHERE id = ?');
$stmt->execute([$userId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    session_unset();
    session_destroy();
    header('Location: login');
    exit;
}

$nome = $usuario['name'];
$email = $usuario['email'];
$apiToken = $usuario['api_token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $acao = $_POST['acao'] ?? 'atualizar_perfil';

    if (!Security::validateCsrf($token)) {
        $erro = 'Não foi possível validar esta solicitação. Atualize a página e tente novamente.';
    } elseif ($acao === 'gerar_token') {
        try {
            $novoToken = ApiAuth::generateToken();
            $stmtToken = $pdo->prepare('UPDATE users SET api_token = ? WHERE id = ?');
            $stmtToken->execute([$novoToken, $userId]);
            header('Location: profile?sucesso=' . urlencode('Nova chave de API gerada com sucesso!'));
            exit;
        } catch (PDOException $e) {
            error_log('Erro ao gerar API token: ' . $e->getMessage());
            $erro = 'Erro ao gerar nova chave de API.';
        }
    } else {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $senhaAtual = $_POST['senha_atual'] ?? '';
        $novaSenha = $_POST['nova_senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';

        $emailAlterado = strcasecmp($email, $usuario['email']) !== 0;
        $senhaAlterada = $novaSenha !== '' || $confirmarSenha !== '';

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 255) {
            $erro = 'Informe um nome entre 2 e 255 caracteres.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um endereço de e-mail válido.';
        } elseif (($emailAlterado || $senhaAlterada) && !password_verify($senhaAtual, $usuario['password'])) {
            $erro = 'Informe sua senha atual para alterar e-mail ou senha.';
        } elseif ($senhaAlterada && mb_strlen($novaSenha) < 6) {
            $erro = 'A nova senha deve conter no mínimo 6 caracteres.';
        } elseif ($senhaAlterada && $novaSenha !== $confirmarSenha) {
            $erro = 'A confirmação da nova senha não confere.';
        } else {
            try {
                if ($emailAlterado) {
                    $emailStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                    $emailStmt->execute([$email, $userId]);

                    if ($emailStmt->fetch()) {
                        $erro = 'Este e-mail já está em uso por outra conta.';
                    }
                }

                if ($erro === '') {
                    if ($senhaAlterada) {
                        $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ? WHERE id = ?');
                        $update->execute([$nome, $email, password_hash($novaSenha, PASSWORD_DEFAULT), $userId]);
                    } else {
                        $update = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                        $update->execute([$nome, $email, $userId]);
                    }

                    $_SESSION['user_name'] = $nome;
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: profile?sucesso=' . urlencode('Dados atualizados com sucesso!'));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Erro ao atualizar perfil: ' . $e->getMessage());
                $erro = 'Não foi possível atualizar os dados. Tente novamente mais tarde.';
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
    <title>Meu perfil - Droma</title>
    <link rel="stylesheet" href="style_index.css?v=<?= filemtime('style_index.css') ?>">
    <link rel="stylesheet" href="style_form.css?v=<?= filemtime('style_form.css') ?>">
</head>
<body>

<?php
$paginaAtiva = 'perfil';
$tituloPagina = 'Meu Perfil';
require_once "includes/layout_header.php";
?>

<main class="painel">
    <section class="perfil-container">
        <div class="perfil-cabecalho">
            <div class="perfil-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper(mb_substr($usuario['name'], 0, 1))) ?></div>
            <div>
                <h1>Dados da conta</h1>
                <p>Atualize seu nome, e-mail ou senha.</p>
            </div>
        </div>

        <?php if ($erro !== ''): ?>
            <div class="alerta-perfil alerta-perfil-erro" role="alert"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <?php if ($sucesso !== ''): ?>
            <div class="alerta-perfil alerta-perfil-sucesso" role="status"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>

        <form class="form-lancamento perfil-form" action="profile" method="POST">
            <?= Security::csrfField() ?>

            <section class="perfil-secao" aria-labelledby="dados-pessoais">
                <h2 id="dados-pessoais">Dados pessoais</h2>
                <p>Informações visíveis na sua conta.</p>

                <label>Nome de usuário
                    <input type="text" name="nome" maxlength="255" value="<?= htmlspecialchars($nome) ?>" required autofocus>
                </label>

                <label for="email">E-mail de acesso
                    <input id="email" type="email" name="email" maxlength="255" value="<?= htmlspecialchars($email) ?>" required data-email-original="<?= htmlspecialchars($usuario['email']) ?>">
                </label>
            </section>

            <section class="perfil-secao perfil-secao-seguranca" aria-labelledby="confirmacao-seguranca">
                <h2 id="confirmacao-seguranca">Confirmação de segurança</h2>
                <p>Obrigatória apenas para mudar e-mail ou senha.</p>

                <label for="senha-atual">Senha atual
                    <span class="senha-input">
                        <input id="senha-atual" type="password" name="senha_atual" autocomplete="current-password" aria-describedby="dica-senha-atual">
                        <button class="botao-visualizar-senha" type="button" data-alvo="senha-atual" aria-label="Mostrar senha atual" aria-pressed="false">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.4 0-9.7 4.7-10 5.1a1.4 1.4 0 0 0 0 1.8C2.3 12.3 6.6 17 12 17s9.7-4.7 10-5.1a1.4 1.4 0 0 0 0-1.8C21.7 9.7 17.4 5 12 5Zm0 9.5A3.5 3.5 0 1 1 12 7a3.5 3.5 0 0 1 0 7.5Zm0-5.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
                        </button>
                    </span>
                </label>
                <span id="dica-senha-atual" class="perfil-dica" aria-live="polite">Pode ficar em branco ao mudar apenas o nome.</span>
            </section>

            <section class="perfil-secao" aria-labelledby="trocar-senha">
                <h2 id="trocar-senha">Trocar senha <span>Opcional</span></h2>
                <p>Use no mínimo 6 caracteres.</p>

                <label for="nova-senha">Nova senha
                    <span class="senha-input">
                        <input id="nova-senha" type="password" name="nova_senha" minlength="6" autocomplete="new-password">
                        <button class="botao-visualizar-senha" type="button" data-alvo="nova-senha" aria-label="Mostrar nova senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.4 0-9.7 4.7-10 5.1a1.4 1.4 0 0 0 0 1.8C2.3 12.3 6.6 17 12 17s9.7-4.7 10-5.1a1.4 1.4 0 0 0 0-1.8C21.7 9.7 17.4 5 12 5Zm0 9.5A3.5 3.5 0 1 1 12 7a3.5 3.5 0 0 1 0 7.5Zm0-5.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
                        </button>
                    </span>
                </label>

                <label for="confirmar-senha">Confirmar nova senha
                    <span class="senha-input">
                        <input id="confirmar-senha" type="password" name="confirmar_senha" minlength="6" autocomplete="new-password">
                        <button class="botao-visualizar-senha" type="button" data-alvo="confirmar-senha" aria-label="Mostrar confirmação da nova senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.4 0-9.7 4.7-10 5.1a1.4 1.4 0 0 0 0 1.8C2.3 12.3 6.6 17 12 17s9.7-4.7 10-5.1a1.4 1.4 0 0 0 0-1.8C21.7 9.7 17.4 5 12 5Zm0 9.5A3.5 3.5 0 1 1 12 7a3.5 3.5 0 0 1 0 7.5Zm0-5.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
                        </button>
                    </span>
                </label>
            </section>

            <button type="submit">Salvar alterações</button>
        </form>

        <section class="perfil-secao" style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--color-border);" aria-labelledby="api-integracao">
            <h2 id="api-integracao" style="display:flex; justify-content:space-between; align-items:center;">
                <span>Integração & API RESTful</span>
                <span style="font-size:12px; background:rgba(212,175,108,0.15); color:var(--color-gold); padding:2px 8px; border-radius:4px; font-weight:700;">v1.0</span>
            </h2>
            <p style="color:var(--color-text-secondary); font-size:13px; margin: 6px 0 16px 0;">
                Use sua chave de API para conectar o Droma com seus próprios scripts, integrações externas ou atalhos mobile via cabeçalho <code>Authorization: Bearer &lt;token&gt;</code>.
            </p>

            <?php if (!empty($apiToken)): ?>
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px;">
                    <input type="text" id="api-token-input" value="<?= htmlspecialchars($apiToken) ?>" readonly style="background:var(--color-surface-secondary); color:var(--color-gold); font-family:monospace; font-size:13px; padding:10px 14px; border:1px solid var(--color-border); border-radius:var(--radius-md); flex:1;">
                    <button type="button" class="sair" style="cursor:pointer; padding:10px 16px; font-size:13px;" onclick="navigator.clipboard.writeText(document.getElementById('api-token-input').value); this.textContent='Copiado!'; setTimeout(()=>this.textContent='Copiar', 2000);">Copiar</button>
                </div>
            <?php else: ?>
                <p style="font-size:13px; color:var(--color-text-secondary); margin-bottom:12px;">Você ainda não gerou uma chave de API para a sua conta.</p>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <form method="POST" action="profile" style="display:inline;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="acao" value="gerar_token">
                    <button type="submit" class="sair" style="font-size:13px; padding:8px 16px; cursor:pointer;" onclick="return confirm('<?= !empty($apiToken) ? 'Regenerar o token invalidará a chave anterior. Deseja continuar?' : 'Gerar nova chave de API?' ?>')">
                        <?= !empty($apiToken) ? '🔄 Regenerar Chave de API' : '🔑 Gerar Chave de API' ?>
                    </button>
                </form>

                <a href="backup" class="sair" style="font-size:13px; padding:8px 16px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    📦 Backup & Restauração de Dados →
                </a>
            </div>
        </section>
    </section>
</main>
<script>
const perfilForm = document.querySelector('.perfil-form');
const emailPerfil = document.getElementById('email');
const senhaAtual = document.getElementById('senha-atual');
const novaSenha = document.getElementById('nova-senha');
const confirmarSenha = document.getElementById('confirmar-senha');
const dicaSenhaAtual = document.getElementById('dica-senha-atual');

function atualizarValidacaoPerfil() {
    const emailAlterado = emailPerfil.value.trim().toLowerCase() !== emailPerfil.dataset.emailOriginal.toLowerCase();
    const senhaAlterada = novaSenha.value !== '' || confirmarSenha.value !== '';
    const exigeSenhaAtual = emailAlterado || senhaAlterada;

    senhaAtual.required = exigeSenhaAtual;
    novaSenha.required = senhaAlterada;
    confirmarSenha.required = senhaAlterada;
    senhaAtual.setCustomValidity(
        exigeSenhaAtual && senhaAtual.value === ''
            ? 'Informe sua senha atual para confirmar a alteração.'
            : ''
    );
    dicaSenhaAtual.textContent = exigeSenhaAtual
        ? 'Informe sua senha atual para confirmar esta alteração.'
        : 'Pode ficar em branco ao mudar apenas o nome.';

    novaSenha.setCustomValidity(novaSenha.value !== '' && novaSenha.value.length < 6 ? 'Use pelo menos 6 caracteres.' : '');
    confirmarSenha.setCustomValidity(
        confirmarSenha.value !== '' && novaSenha.value !== confirmarSenha.value
            ? 'As novas senhas não conferem.'
            : ''
    );
}

[emailPerfil, senhaAtual, novaSenha, confirmarSenha].forEach(campo => {
    campo.addEventListener('input', atualizarValidacaoPerfil);
});

document.querySelectorAll('.botao-visualizar-senha').forEach(botao => {
    botao.addEventListener('click', () => {
        const campo = document.getElementById(botao.dataset.alvo);
        const exibindo = campo.type === 'text';
        campo.type = exibindo ? 'password' : 'text';
        botao.setAttribute('aria-pressed', String(!exibindo));
        botao.setAttribute('aria-label', `${exibindo ? 'Mostrar' : 'Ocultar'} ${botao.getAttribute('aria-label').replace(/^(Mostrar|Ocultar) /, '')}`);
    });
});

perfilForm.addEventListener('submit', atualizarValidacaoPerfil);
</script>

<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


