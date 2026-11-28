<?php
require_once 'config/database.php';
require_once 'src/Security.php';
require_once 'src/ApiAuth.php';
require_once 'src/Mailer.php';

$userId = Security::requireAuth();
$erro = '';
$sucesso = $_GET['sucesso'] ?? '';

$hasApiHash = Security::ensureApiTokenHashColumn($pdo);
$stmt = $pdo->prepare($hasApiHash
    ? 'SELECT id, name, email, password, api_token_hash, email_verified_at FROM users WHERE id = ?'
    : 'SELECT id, name, email, password, api_token, email_verified_at FROM users WHERE id = ?'
);
$stmt->execute([$userId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    session_unset();
    session_destroy();
    header('Location: landing');
    exit;
}

$nome = $usuario['name'];
$email = $usuario['email'];
$temApiToken = $hasApiHash ? !empty($usuario['api_token_hash']) : !empty($usuario['api_token']);
$apiTokenRecemGerado = $_SESSION['novo_api_token'] ?? '';
unset($_SESSION['novo_api_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $acao = $_POST['acao'] ?? 'atualizar_perfil';

    if (!Security::validateCsrf($token)) {
        $erro = 'Não foi possível validar esta solicitação. Atualize a página e tente novamente.';
    } elseif ($acao === 'gerar_token') {
        try {
            $novoToken = ApiAuth::generateToken();
            $novoTokenHash = hash('sha256', $novoToken);
            if ($hasApiHash) {
                $stmtToken = $pdo->prepare('UPDATE users SET api_token_hash = ?, api_token = NULL WHERE id = ?');
                $stmtToken->execute([$novoTokenHash, $userId]);
            } else {
                $stmtToken = $pdo->prepare('UPDATE users SET api_token = ? WHERE id = ?');
                $stmtToken->execute([$novoToken, $userId]);
            }
            $_SESSION['novo_api_token'] = $novoToken;
            Security::flashRedirect('profile', 'sucesso', 'Nova chave de API gerada! Copie agora, pois ela não será exibida novamente.');
        } catch (PDOException $e) {
            error_log('Erro ao gerar API token: ' . $e->getMessage());
            $erro = 'Erro ao gerar nova chave de API.';
        }
    } elseif ($acao === 'reenviar_verificacao') {
        try {
            $novoCodigo = sprintf("%06d", random_int(100000, 999999));
            $stmtToken = $pdo->prepare('UPDATE users SET verification_token = ? WHERE id = ?');
            $stmtToken->execute([$novoCodigo, $userId]);

            Mailer::sendEmailVerification($usuario['email'], $usuario['name'], $novoCodigo);
            $redUrl = 'verify_email?email=' . urlencode($usuario['email']);
            if (!headers_sent()) {
                Security::flashRedirect($redUrl, 'sucesso', 'Código de confirmação de 6 dígitos enviado com sucesso! Verifique sua caixa de entrada e spam.');
            } else {
                Security::setFlash('sucesso', 'Código de confirmação de 6 dígitos enviado com sucesso! Verifique sua caixa de entrada e spam.');
                echo '<script>window.location.href=' . json_encode($redUrl) . ';</script>';
            }
            exit;
        } catch (Throwable $e) {
            error_log('Erro ao reenviar e-mail: ' . $e->getMessage());
            $erro = 'Não foi possível reenviar o e-mail no momento. Tente novamente mais tarde.';
        }
    } else {
        $nome = trim($_POST['nome'] ?? '');
        $email = Security::normalizeEmail($_POST['email'] ?? '');
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
        } elseif ($senhaAlterada && ($pwVal = Security::validatePasswordStrength($novaSenha, $nome, $email)) && !$pwVal['valido']) {
            $erro = $pwVal['erro'];
        } elseif ($senhaAlterada && $novaSenha !== $confirmarSenha) {
            $erro = 'A confirmação da nova senha não confere.';
        } else {
            try {
                if ($emailAlterado) {
                    Security::ensureNormalizedEmails($pdo);
                    $emailStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(TRIM(email)) = ? AND id <> ?');
                    $emailStmt->execute([$email, $userId]);

                    if ($emailStmt->fetch()) {
                        $erro = 'Este e-mail já está em uso por outra conta.';
                    }
                }

                if ($erro === '') {
                    if ($emailAlterado) {
                        $novoCodigo = sprintf("%06d", random_int(100000, 999999));
                        if ($senhaAlterada) {
                            $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ?, email_verified_at = NULL, verification_token = ?, remember_token = NULL WHERE id = ?');
                            $update->execute([$nome, $email, Security::hashPassword($novaSenha), $novoCodigo, $userId]);
                            Security::revokeAllSessions($pdo, $userId);
                            try {
                                Mailer::sendSecurityPasswordChanged($usuario['email'], $usuario['name']);
                            } catch (Throwable $e) {
                                error_log("[Mailer Security Error] " . $e->getMessage());
                            }
                        } else {
                            $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, email_verified_at = NULL, verification_token = ? WHERE id = ?');
                            $update->execute([$nome, $email, $novoCodigo, $userId]);
                        }
                        try {
                            Mailer::sendEmailVerification($email, $nome, $novoCodigo);
                        } catch (Throwable $e) {
                            error_log("[Mailer Error] " . $e->getMessage());
                        }

                        $_SESSION['user_name'] = $nome;
                        $_SESSION['email_verified_at'] = null;
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        Security::flashRedirect('verify_email?email=' . urlencode($email), 'sucesso', 'Dados atualizados! Digite o código de 6 dígitos enviado para seu novo e-mail para confirmá-lo.');
                    } else {
                        if ($senhaAlterada) {
                            $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, password = ?, remember_token = NULL WHERE id = ?');
                            $update->execute([$nome, $email, Security::hashPassword($novaSenha), $userId]);
                            Security::revokeAllSessions($pdo, $userId);
                            try {
                                Mailer::sendSecurityPasswordChanged($usuario['email'], $usuario['name']);
                            } catch (Throwable $e) {
                                error_log("[Mailer Security Error] " . $e->getMessage());
                            }
                        } else {
                            $update = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                            $update->execute([$nome, $email, $userId]);
                        }

                        $_SESSION['user_name'] = $nome;
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        Security::flashRedirect('profile', 'sucesso', 'Dados atualizados com sucesso!');
                    }
                }
            } catch (PDOException $e) {
                error_log('Erro ao atualizar perfil: ' . $e->getMessage());
                $isDuplicate = ($e->getCode() == 23000 || 
                    str_contains($e->getMessage(), 'Duplicate entry') || 
                    str_contains($e->getMessage(), '1062') ||
                    str_contains($e->getMessage(), 'email')
                );
                if ($isDuplicate) {
                    $erro = 'Este e-mail já está em uso por outra conta.';
                } else {
                    $erro = 'Não foi possível atualizar os dados. Tente novamente mais tarde.';
                }
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
    <title>Meu perfil - Droma</title>
    <link rel="stylesheet" href="assets/css/style_index.css?v=<?= filemtime('assets/css/style_index.css') ?>">
    <link rel="stylesheet" href="assets/css/style_form.css?v=<?= filemtime('assets/css/style_form.css') ?>">
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
                    <input id="email" type="email" name="email" maxlength="255" value="<?= htmlspecialchars($email) ?>" required data-email-original="<?= htmlspecialchars($usuario['email']) ?>" autocapitalize="none" autocorrect="off" spellcheck="false">
                </label>
                <?php if (!empty($usuario['email_verified_at'])): ?>
                    <div style="display:flex; align-items:center; gap:6px; font-size:12px; color:var(--color-emerald); margin-top:-6px; margin-bottom:12px; font-weight:600;">
                        <span>✓</span> E-mail verificado em <?= date('d/m/Y \à\s H:i', strtotime($usuario['email_verified_at'])) ?>
                    </div>
                <?php else: ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--color-warning); margin-top:-6px; margin-bottom:12px; background:rgba(245,158,11,0.08); padding:8px 12px; border-radius:var(--radius-sm); border:1px solid rgba(245,158,11,0.25);">
                        <span>⚠️ E-mail pendente de verificação</span>
                        <button type="submit" form="form-reenviar-verificacao" style="background:none; border:none; color:var(--color-gold); font-size:12px; font-weight:700; text-decoration:underline; cursor:pointer; padding:0;">Reenviar confirmação</button>
                    </div>
                <?php endif; ?>
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
                <p>Use no mínimo 8 caracteres com letras maiúsculas, minúsculas e números.</p>

                <label for="nova-senha">Nova senha
                    <span class="senha-input">
                        <input id="nova-senha" type="password" name="nova_senha" minlength="8" autocomplete="new-password" data-password-meter>
                        <button class="botao-visualizar-senha" type="button" data-alvo="nova-senha" aria-label="Mostrar nova senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.4 0-9.7 4.7-10 5.1a1.4 1.4 0 0 0 0 1.8C2.3 12.3 6.6 17 12 17s9.7-4.7 10-5.1a1.4 1.4 0 0 0 0-1.8C21.7 9.7 17.4 5 12 5Zm0 9.5A3.5 3.5 0 1 1 12 7a3.5 3.5 0 0 1 0 7.5Zm0-5.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
                        </button>
                    </span>
                    <div class="password-meter-wrap" id="password-meter-nova-senha" aria-live="polite">
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
                </label>

                <label for="confirmar-senha">Confirmar nova senha
                    <span class="senha-input">
                        <input id="confirmar-senha" type="password" name="confirmar_senha" minlength="8" autocomplete="new-password">
                        <button class="botao-visualizar-senha" type="button" data-alvo="confirmar-senha" aria-label="Mostrar confirmação da nova senha" aria-pressed="false">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c-5.4 0-9.7 4.7-10 5.1a1.4 1.4 0 0 0 0 1.8C2.3 12.3 6.6 17 12 17s9.7-4.7 10-5.1a1.4 1.4 0 0 0 0-1.8C21.7 9.7 17.4 5 12 5Zm0 9.5A3.5 3.5 0 1 1 12 7a3.5 3.5 0 0 1 0 7.5Zm0-5.5a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/></svg>
                        </button>
                    </span>
                </label>
            </section>

            <button type="submit">Salvar alterações</button>
        </form>

        <form id="form-reenviar-verificacao" method="POST" action="profile" style="display:none;">
            <?= Security::csrfField() ?>
            <input type="hidden" name="acao" value="reenviar_verificacao">
        </form>

        <section class="perfil-secao" style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--color-border);" aria-labelledby="push-notificacoes">
            <h2 id="push-notificacoes" style="display:flex; justify-content:space-between; align-items:center;">
                <span>Notificações Push (Navegador)</span>
                <span id="push-status-badge" style="font-size:12px; background:var(--color-surface-secondary); color:var(--color-text-secondary); padding:2px 8px; border-radius:4px; font-weight:700;">Verificando...</span>
            </h2>
            <p style="color:var(--color-text-secondary); font-size:13px; margin: 6px 0 16px 0;">
                Receba alertas no dispositivo sobre contas pendentes com vencimento nos próximos 3 dias via Service Worker.
            </p>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <button type="button" id="btn-push-toggle" class="btn-header-primary" data-csrf="<?= htmlspecialchars(Security::csrfToken()) ?>" style="cursor:pointer; font-size:13px; padding:8px 16px;">Ativar Notificações</button>
                <button type="button" id="btn-push-test" class="sair" style="cursor:pointer; font-size:13px; padding:8px 14px; display:none;">Testar Alerta</button>
            </div>
            <script>
                (function() {
                    function updatePushUi() {
                        const badge = document.getElementById('push-status-badge');
                        const btnToggle = document.getElementById('btn-push-toggle');
                        const btnTest = document.getElementById('btn-push-test');
                        if (!window.DromaPush || !window.DromaPush.isSupported()) {
                            badge.textContent = 'Não suportado';
                            btnToggle.disabled = true;
                            return;
                        }
                        const enabled = window.DromaPush.isEnabled();
                        const perm = window.DromaPush.getPermission();
                        if (perm === 'denied') {
                            badge.textContent = 'Bloqueado no navegador';
                            badge.style.color = 'var(--color-danger)';
                            btnToggle.textContent = 'Desbloqueie nas permissões do site';
                            btnToggle.disabled = true;
                            btnTest.style.display = 'none';
                        } else if (enabled) {
                            badge.textContent = 'Ativo';
                            badge.style.color = 'var(--color-emerald)';
                            btnToggle.textContent = 'Desativar Notificações';
                            btnTest.style.display = 'inline-block';
                        } else {
                            badge.textContent = 'Inativo';
                            badge.style.color = 'var(--color-text-secondary)';
                            btnToggle.textContent = 'Ativar Notificações';
                            btnTest.style.display = 'none';
                        }
                    }
                    document.addEventListener('DOMContentLoaded', function() {
                        updatePushUi();
                        document.getElementById('btn-push-toggle')?.addEventListener('click', async function() {
                            if (!window.DromaPush) return;
                            const csrf = this.getAttribute('data-csrf') || '';
                            if (window.DromaPush.isEnabled()) {
                                await window.DromaPush.disable(csrf);
                            } else {
                                await window.DromaPush.enable(csrf);
                            }
                            updatePushUi();
                        });
                        document.getElementById('btn-push-test')?.addEventListener('click', function() {
                            const csrf = document.getElementById('btn-push-toggle')?.getAttribute('data-csrf') || '';
                            window.DromaPush?.sendTest(csrf);
                        });
                    });
                })();
            </script>
        </section>

        <section class="perfil-secao" style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--color-border);" aria-labelledby="api-integracao">
            <h2 id="api-integracao" style="display:flex; justify-content:space-between; align-items:center;">
                <span>Integração & API RESTful</span>
                <span style="font-size:12px; background:rgba(212,175,108,0.15); color:var(--color-gold); padding:2px 8px; border-radius:4px; font-weight:700;">v1.0</span>
            </h2>
            <p style="color:var(--color-text-secondary); font-size:13px; margin: 6px 0 16px 0;">
                Use sua chave de API para conectar o Droma com seus próprios scripts, integrações externas ou atalhos mobile via cabeçalho <code>Authorization: Bearer &lt;token&gt;</code>.
            </p>

            <?php if ($apiTokenRecemGerado !== ''): ?>
                <div style="font-size:12.5px; color:var(--color-warning); margin-bottom:8px; font-weight:600;">
                    Copie sua nova chave agora — por segurança, ela não será exibida novamente.
                </div>
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px;">
                    <input type="text" id="api-token-input" value="<?= htmlspecialchars($apiTokenRecemGerado) ?>" readonly style="background:var(--color-surface-secondary); color:var(--color-gold); font-family:monospace; font-size:13px; padding:10px 14px; border:1px solid var(--color-border); border-radius:var(--radius-md); flex:1;">
                    <button type="button" class="sair" style="cursor:pointer; padding:10px 16px; font-size:13px;" onclick="navigator.clipboard.writeText(document.getElementById('api-token-input').value); this.textContent='Copiado!'; setTimeout(()=>this.textContent='Copiar', 2000);">Copiar</button>
                </div>
            <?php elseif ($temApiToken): ?>
                <p style="font-size:13px; color:var(--color-emerald); margin-bottom:12px;">✓ Você possui uma chave de API ativa (armazenada com hash seguro). Gere uma nova se precisar substituí-la.</p>
            <?php else: ?>
                <p style="font-size:13px; color:var(--color-text-secondary); margin-bottom:12px;">Você ainda não gerou uma chave de API para a sua conta.</p>
            <?php endif; ?>

            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <form method="POST" action="profile" style="display:inline;">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="acao" value="gerar_token">
                    <button type="submit" class="sair" style="font-size:13px; padding:8px 16px; cursor:pointer;" data-confirm="<?= $temApiToken ? 'Regenerar o token invalidará a chave anterior. Deseja continuar?' : 'Gerar nova chave de API?' ?>" data-confirm-title="Chave de API" data-confirm-btn="Continuar" data-confirm-type="<?= $temApiToken ? 'danger' : 'info' ?>">
                        <?= $temApiToken ? '🔄 Regenerar Chave de API' : '🔑 Gerar Chave de API' ?>
                    </button>
                </form>

                <a href="backup" class="sair" style="font-size:13px; padding:8px 16px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    📦 Backup & Restauração de Dados →
                </a>
            </div>
        </section>

        <section class="perfil-secao" style="margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--color-border);" aria-labelledby="termos-privacidade">
            <h2 id="termos-privacidade">Termos de Uso & Privacidade (LGPD)</h2>
            <p style="color:var(--color-text-secondary); font-size:13px; margin: 6px 0 16px 0;">
                O Droma opera em estrita conformidade com a Lei Geral de Proteção de Dados (Lei 13.709/2018). Seus dados financeiros são privados e nunca são comercializados.
            </p>
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; background:var(--color-surface-secondary); padding:14px 18px; border-radius:var(--radius-md); border:1px solid var(--color-border);">
                <div>
                    <div style="font-size:13.5px; font-weight:600; color:var(--color-text);">Status do Aceite: <span style="color:var(--color-emerald);">✓ Conforme & Ativo</span></div>
                    <div style="font-size:12px; color:var(--color-text-muted); margin-top:2px;">Aceito e registrado sob as diretrizes da LGPD e isenção financeira.</div>
                </div>
                <a href="terms" target="_blank" class="sair" style="text-decoration:none; padding:8px 14px; font-size:12.5px; display:inline-flex; align-items:center; gap:6px;">
                    Ver Termos na Íntegra ↗
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

    novaSenha.setCustomValidity(novaSenha.value !== '' && novaSenha.value.length < 8 ? 'Use pelo menos 8 caracteres.' : '');
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

<script src="js/password-meter.js?v=<?= filemtime('js/password-meter.js') ?>" defer></script>
<?php require_once "includes/layout_footer.php"; ?>
</body>
</html>


