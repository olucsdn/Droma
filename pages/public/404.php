<?php
/**
 * Página de Erro 404 Personalizada — Droma Financy
 * Design System Luxury Dark (Ouro & Ardósia)
 */
http_response_code(404);

// 1. Resposta JSON limpa se for requisição de API ou AJAX
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isApi = str_starts_with($requestUri, '/api/') 
    || str_contains($requestUri, '/api/v1/')
    || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($isApi) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => 404,
        'message' => 'Endpoint ou recurso não encontrado.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 2. Inicializa sessão para identificar contexto do usuário
$isLoggedIn = false;
$userName = '';
try {
    require_once dirname(__DIR__, 2) . '/src/Security.php';
    Security::initSession();
    $isLoggedIn = !empty($_SESSION['user_id']);
    $userName = $_SESSION['user_name'] ?? '';
} catch (Throwable) {
    // Fallback silencioso se o banco de dados estiver inacessível
}

$cleanPath = htmlspecialchars(parse_url($requestUri, PHP_URL_PATH) ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark" data-theme-fixed="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Página Não Encontrada (404) — Droma Financy</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php 
    $dromaFixedDark = true;
    if (file_exists(dirname(__DIR__, 2) . '/includes/head_favicons.php')) {
        require_once dirname(__DIR__, 2) . '/includes/head_favicons.php';
    }
    ?>
    <style>
        :root {
            --bg-base: #070A0E;
            --bg-surface: #0F1420;
            --bg-surface-elevated: #141B2B;
            --border-subtle: #1F2A3F;
            --border-highlight: rgba(212, 175, 108, 0.35);
            --color-gold-light: #F7E5B8;
            --color-gold: #D4AF6C;
            --color-gold-dark: #A17632;
            --color-text-primary: #F8FAFC;
            --color-text-secondary: #94A3B8;
            --color-text-muted: #64748B;
            --gold-gradient: linear-gradient(135deg, #F7E5B8 0%, #D4AF6C 50%, #A17632 100%);
            --gold-gradient-hover: linear-gradient(135deg, #FFF1CD 0%, #E4C58B 50%, #B8863D 100%);
            --font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-base);
            color: var(--color-text-primary);
            font-family: var(--font-family);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* Iluminação e Glow de Fundo */
        .ambient-glow {
            position: absolute;
            top: 20%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(212, 175, 108, 0.12) 0%, rgba(59, 130, 246, 0.05) 45%, transparent 70%);
            pointer-events: none;
            z-index: 0;
            filter: blur(40px);
        }

        .container {
            width: 100%;
            max-width: 580px;
            position: relative;
            z-index: 1;
            text-align: center;
        }

        /* Branding Topo */
        .brand-header {
            margin-bottom: 24px;
            display: inline-flex;
        }

        .brand-link {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .brand-link:hover {
            transform: scale(1.02);
            opacity: 0.95;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: var(--gold-gradient);
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 22px;
            color: #0A0E17;
            box-shadow: 0 4px 16px rgba(212, 175, 108, 0.25);
            border: 1px solid rgba(247, 229, 184, 0.4);
            letter-spacing: -0.5px;
        }

        .brand-name {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: #FFFFFF;
        }

        .brand-name span {
            color: var(--color-gold);
        }

        /* Card Principal 404 */
        .error-card {
            background: rgba(15, 20, 32, 0.75);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-subtle);
            border-radius: 24px;
            padding: 44px 36px;
            box-shadow: 0 24px 50px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.03);
            position: relative;
            overflow: hidden;
            transition: border-color 0.3s ease;
        }

        .error-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--color-gold), transparent);
        }

        /* Badge Superior */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(212, 175, 108, 0.1);
            border: 1px solid rgba(212, 175, 108, 0.25);
            color: var(--color-gold-light);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 6px 14px;
            border-radius: 999px;
            margin-bottom: 20px;
        }

        .status-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background-color: var(--color-gold);
            box-shadow: 0 0 8px var(--color-gold);
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        /* Dígitos 404 Gigantes com Gradiente Ouro */
        .num-404 {
            font-size: clamp(86px, 16vw, 132px);
            font-weight: 800;
            line-height: 0.95;
            letter-spacing: -4px;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #FFF6DD 0%, #D4AF6C 50%, #8C6225 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 10px 30px rgba(212, 175, 108, 0.15);
            user-select: none;
        }

        /* Título e Texto */
        h1 {
            font-size: clamp(20px, 3.5vw, 24px);
            font-weight: 700;
            color: #FFFFFF;
            letter-spacing: -0.5px;
            margin-bottom: 12px;
        }

        p.description {
            font-size: 15px;
            line-height: 1.6;
            color: var(--color-text-secondary);
            margin-bottom: 24px;
            max-width: 460px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Detalhe da Rota Solicitada */
        <?php if (!empty($cleanPath) && $cleanPath !== '/404' && $cleanPath !== '/404.php'): ?>
        .requested-path {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px dashed var(--border-subtle);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 13px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: var(--color-text-muted);
            margin-bottom: 28px;
            max-width: 90%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .requested-path span {
            color: var(--color-gold-light);
        }
        <?php endif; ?>

        /* Grade de Botões de Ação */
        .actions-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 28px;
        }

        .actions-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        @media (max-width: 480px) {
            .actions-row {
                grid-template-columns: 1fr;
            }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 22px;
            border-radius: 12px;
            font-size: 14.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
            cursor: pointer;
            border: none;
            outline: none;
            font-family: inherit;
        }

        .btn-primary {
            background: var(--gold-gradient);
            color: #0A0E17;
            box-shadow: 0 4px 16px rgba(212, 175, 108, 0.25);
        }

        .btn-primary:hover {
            background: var(--gold-gradient-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 175, 108, 0.35);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-subtle);
            color: var(--color-text-primary);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.09);
            border-color: rgba(212, 175, 108, 0.4);
            transform: translateY(-1px);
        }

        .btn-ghost {
            background: transparent;
            color: var(--color-text-secondary);
            font-size: 13.5px;
            padding: 8px 16px;
        }

        .btn-ghost:hover {
            color: var(--color-gold-light);
            text-decoration: underline;
        }

        /* Divisor e Dica de Navegação */
        .helper-box {
            border-top: 1px solid var(--border-subtle);
            padding-top: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--color-text-muted);
        }

        .helper-box a {
            color: var(--color-text-secondary);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .helper-box a:hover {
            color: var(--color-gold-light);
        }

        /* Rodapé Discreto */
        .page-footer {
            margin-top: 28px;
            font-size: 12px;
            color: var(--color-text-muted);
            letter-spacing: 0.2px;
        }

        .page-footer span {
            color: rgba(212, 175, 108, 0.8);
        }
    </style>
</head>
<body>

<div class="ambient-glow"></div>

<div class="container">
    <!-- Brand Header -->
    <div class="brand-header">
        <a href="<?= $isLoggedIn ? './' : 'landing' ?>" class="brand-link" title="Ir para a página principal">
            <div class="brand-icon">D</div>
            <div class="brand-name">Droma<span>.</span></div>
        </a>
    </div>

    <!-- Error Card -->
    <div class="error-card">
        <div class="status-badge">
            <span class="status-dot"></span>
            Código 404 • Rota Não Encontrada
        </div>

        <div class="num-404">404</div>

        <h1>Ops! Essa página sumiu do mapa financeiro.</h1>

        <p class="description">
            O endereço acessado não existe, foi alterado ou pode conter um erro de digitação. Não se preocupe: seus lançamentos e saldo continuam 100% seguros.
        </p>

        <?php if (!empty($cleanPath) && $cleanPath !== '/404' && $cleanPath !== '/404.php'): ?>
            <div class="requested-path" title="<?= $cleanPath ?>">
                Rota solicitada: <span><?= $cleanPath ?></span>
            </div>
        <?php endif; ?>

        <!-- Ações dinâmicas conforme estado da sessão -->
        <div class="actions-stack">
            <?php if ($isLoggedIn): ?>
                <a href="./" class="btn btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Ir para o Meu Painel
                </a>

                <div class="actions-row">
                    <a href="statement" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="8" y1="6" x2="21" y2="6"/>
                            <line x1="8" y1="12" x2="21" y2="12"/>
                            <line x1="8" y1="18" x2="21" y2="18"/>
                            <line x1="3" y1="6" x2="3.01" y2="6"/>
                            <line x1="3" y1="12" x2="3.01" y2="12"/>
                            <line x1="3" y1="18" x2="3.01" y2="18"/>
                        </svg>
                        Ver Extrato
                    </a>

                    <button type="button" class="btn btn-secondary" onclick="voltarPagina()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="19" y1="12" x2="5" y2="12"/>
                            <polyline points="12 19 5 12 12 5"/>
                        </svg>
                        Voltar à Anterior
                    </button>
                </div>
            <?php else: ?>
                <a href="landing" class="btn btn-primary">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="m10 15 5-3-5-3v6z"/>
                    </svg>
                    Ir para a Página Inicial
                </a>

                <div class="actions-row">
                    <a href="login" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                            <polyline points="10 17 15 12 10 7"/>
                            <line x1="15" y1="12" x2="3" y2="12"/>
                        </svg>
                        Acessar Conta
                    </a>

                    <a href="register" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="8.5" cy="7" r="4"/>
                            <line x1="20" y1="8" x2="20" y2="14"/>
                            <line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                        Criar Conta Grátis
                    </a>
                </div>

                <button type="button" class="btn btn-ghost" onclick="voltarPagina()">
                    ← Voltar à página anterior
                </button>
            <?php endif; ?>
        </div>

        <!-- Links Auxiliares -->
        <div class="helper-box">
            <span>Precisa de suporte?</span>
            <?php if ($isLoggedIn): ?>
                <a href="assistant">Assistente Financeiro</a>
                <span>•</span>
                <a href="profile">Minha Conta</a>
            <?php else: ?>
                <a href="landing#faq">Dúvidas Frequentes</a>
                <span>•</span>
                <a href="terms">Termos de Uso</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rodapé Técnico -->
    <div class="page-footer">
        Droma Financy • Status HTTP <span>404 Not Found</span>
    </div>
</div>

<script>
function voltarPagina() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = '<?= $isLoggedIn ? "./" : "landing" ?>';
    }
}
</script>

</body>
</html>