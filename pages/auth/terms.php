<?php
require_once "src/Security.php";
require_once "config/database.php";

Security::initSession();

$isLoggedIn = !empty($_SESSION['user_id']);
$userId = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$jaAceitou = false;

if ($isLoggedIn && $userId > 0) {
    $jaAceitou = Security::hasAcceptedTerms($pdo, $userId);
}

// Aceite direto via POST na própria página
$sucessoAceite = false;
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_terms') {
    if (Security::validateCsrf($_POST['csrf_token'] ?? '')) {
        Security::recordTermsAcceptance($pdo, $userId);
        $_SESSION['terms_accepted_at'] = date('Y-m-d H:i:s');
        $jaAceitou = true;
        $sucessoAceite = true;
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
    <title>Droma - Termos de Uso e Política de Privacidade</title>
    <link rel="stylesheet" href="assets/css/style_login.css?v=<?= filemtime('assets/css/style_login.css') ?>">
    <style>
        html {
            scroll-behavior: smooth;
        }

        body.terms-body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 44px 24px 80px 24px;
            background: var(--color-bg);
            color: var(--color-text);
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body.terms-body > .terms-wrapper,
        .terms-wrapper {
            width: 100% !important;
            max-width: 980px !important;
            margin: 0 auto !important;
            padding: 0 !important;
            border: none !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        .terms-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--color-border);
            width: 100%;
        }

        .terms-brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .terms-back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: var(--radius-md);
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            color: var(--color-text-secondary);
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .terms-back-btn:hover {
            color: var(--color-gold);
            border-color: var(--color-gold);
            background: var(--color-surface-hover);
            transform: translateX(-2px);
        }

        .terms-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-xl);
            padding: 48px 56px;
            box-shadow: var(--shadow-card);
            line-height: 1.75;
            font-size: 15px;
            color: var(--color-text-secondary);
            width: 100%;
        }

        .terms-card h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--color-text);
            text-align: left;
            margin-bottom: 10px;
            letter-spacing: -0.5px;
            line-height: 1.3;
        }

        .terms-updated {
            font-size: 13px;
            color: var(--color-text-muted);
            margin-bottom: 28px;
            line-height: 1.5;
        }

        .terms-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 36px;
            padding: 12px 14px;
            border-radius: var(--radius-md);
            background: rgba(18, 24, 38, 0.95);
            backdrop-filter: blur(12px);
            border: 1px solid var(--color-border);
            position: sticky;
            top: 16px;
            z-index: 100;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
        }

        .terms-nav a {
            color: var(--color-text-secondary);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .terms-nav a:hover, .terms-nav a.ativo {
            color: var(--color-gold);
            background: rgba(212, 175, 108, 0.12);
            border-color: rgba(212, 175, 108, 0.3);
        }

        .terms-section {
            margin-bottom: 44px;
            scroll-margin-top: 90px;
        }

        .terms-section h2 {
            font-size: 21px;
            font-weight: 700;
            color: var(--color-gold);
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--color-divider);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .terms-section h3 {
            font-size: 16px;
            color: var(--color-text);
            margin: 24px 0 10px 0;
            font-weight: 700;
        }

        .terms-section p {
            margin-bottom: 14px;
            color: #CBD5E1;
            line-height: 1.8;
        }

        .terms-section ul {
            margin: 12px 0 18px 24px;
            color: #CBD5E1;
            line-height: 1.8;
        }

        .terms-section li {
            margin-bottom: 8px;
        }

        .disclaimer-box {
            background: rgba(212, 175, 108, 0.08);
            border-left: 4px solid var(--color-gold);
            padding: 20px 24px;
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            margin: 24px 0;
            font-size: 14px;
            color: var(--color-gold-light);
            line-height: 1.7;
        }

        .lgpd-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(16, 185, 129, 0.12);
            color: var(--color-emerald);
            padding: 6px 14px;
            border-radius: var(--radius-pill);
            font-size: 12.5px;
            font-weight: 700;
            margin-bottom: 16px;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .accept-card {
            margin-top: 40px;
            padding: 32px 40px;
            border-radius: var(--radius-lg);
            background: linear-gradient(180deg, rgba(212, 175, 108, 0.1) 0%, rgba(212, 175, 108, 0.03) 100%);
            border: 1px solid rgba(212, 175, 108, 0.35);
            text-align: center;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.3);
        }

        .btn-confirm-accept {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            height: 50px;
            padding: 0 36px;
            border-radius: var(--radius-md);
            background: var(--gold-gradient);
            color: var(--color-on-gold);
            border: 1px solid rgba(249, 228, 167, 0.4);
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            box-shadow: var(--shadow-gold-subtle);
            transition: all 0.2s ease;
        }

        .btn-confirm-accept:hover {
            background: var(--gold-gradient-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-gold-hover);
        }

        /* Tablets e Telas Médias */
        @media (max-width: 992px) {
            .terms-card {
                padding: 36px 32px;
            }
        }

        /* Telas Pequenas e Smartphones */
        @media (max-width: 640px) {
            body.terms-body {
                padding: 20px 12px 48px 12px;
            }
            .terms-header {
                margin-bottom: 16px;
                padding-bottom: 12px;
            }
            .terms-back-btn {
                padding: 6px 12px;
                font-size: 12px;
            }
            .terms-card {
                padding: 24px 18px;
                border-radius: var(--radius-lg);
            }
            .terms-card h1 {
                font-size: 22px;
            }
            .terms-section h2 {
                font-size: 18px;
            }
            .terms-nav {
                flex-wrap: nowrap;
                overflow-x: auto;
                padding: 8px 10px;
                margin-bottom: 24px;
                -webkit-overflow-scrolling: touch;
            }
            .terms-nav a {
                white-space: nowrap;
                font-size: 12.5px;
                padding: 6px 12px;
            }
            .disclaimer-box {
                padding: 14px 16px;
                font-size: 13px;
            }
            .accept-card {
                padding: 20px 16px;
            }
            .btn-confirm-accept {
                width: 100%;
                padding: 0 16px;
                font-size: 13.5px;
            }
        }
    </style>
</head>
<body class="terms-body">

<div class="terms-wrapper">
    <!-- Cabeçalho de Navegação -->
    <header class="terms-header">
        <a href="./" class="terms-brand">
            <div class="brand-icon">D</div>
            <div class="brand-text">Droma<span>.</span></div>
        </a>
        <a href="<?= $isLoggedIn ? './' : 'login' ?>" class="terms-back-btn">
            ← <?= $isLoggedIn ? 'Voltar ao Sistema' : 'Voltar ao Login' ?>
        </a>
    </header>

    <main class="terms-card">
        <h1>Termos de Uso e Política de Privacidade</h1>
        <div class="terms-updated">
            Versão 1.0 • Vigência a partir de 02 de Novembro de 2026 • Em estrita conformidade com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018 - LGPD).
        </div>

        <nav class="terms-nav" aria-label="Navegação do documento">
            <a href="#termos" class="ativo">1. Termos de Uso</a>
            <a href="#privacidade">2. Política de Privacidade & LGPD</a>
            <a href="#cookies">3. Cookies & Rastreamento</a>
        </nav>

        <?php if ($sucessoAceite): ?>
            <div class="alerta alerta-sucesso" style="margin-bottom: 24px;">
                ✓ Termos de Uso e Política de Privacidade aceitos com sucesso! Seu consentimento foi registrado.
            </div>
        <?php endif; ?>

        <!-- SEÇÃO 1: TERMOS DE USO -->
        <section class="terms-section" id="termos">
            <h2>1. Termos e Condições de Uso</h2>
            
            <p>
                Bem-vindo ao <strong>Droma</strong> (denominado "Sistema" ou "Plataforma"). Ao criar uma conta, acessar ou utilizar qualquer um de nossos serviços, você declara ter lido, compreendido e concordado integralmente com estes Termos de Uso. Caso não concorde, você deve abster-se de utilizar a plataforma.
            </p>

            <h3>1.1. Objeto do Serviço</h3>
            <p>
                O Droma é uma aplicação de software desenvolvida para auxiliar pessoas físicas na organização, consolidação e visualização de seus fluxos financeiros pessoais, incluindo receitas, despesas, cartões de crédito, contas bancárias, metas de economia e lançamentos recorrentes.
            </p>

            <h3>1.2. Isenção de Responsabilidade Financeira e Regulatória</h3>
            <div class="disclaimer-box">
                <strong>AVISO IMPORTANTE DE ISENÇÃO DE RESPONSABILIDADE (CVM / BACEN):</strong><br>
                O Droma é única e exclusivamente um software de gestão e organização de dados pessoais. <strong>O Droma NÃO é uma instituição financeira, corretora de valores, consultoria de investimentos ou entidade autorizada pela Comissão de Valores Mobiliários (CVM) ou pelo Banco Central do Brasil.</strong><br><br>
                Nenhuma projeção matemática, gráfico, cálculo de Retorno sobre Investimento (ROI), indicador de saldo ou diagnóstico emitido pelo Assistente de Inteligência Artificial constitui recomendação de compra, venda, alocação de ativos, assessoria patrimonial ou consultoria jurídica/tributária. Todas as decisões econômicas, aportes e quitações financeiras são de responsabilidade exclusiva, soberana e pessoal do usuário.
            </div>

            <h3>1.3. Guarda de Credenciais e Segurança da Conta</h3>
            <p>
                O usuário é o único responsável pela guarda, confidencialidade e sigilo de sua senha de acesso. A plataforma não solicita senhas de bancos reais (Open Finance), tokens de segurança de agências bancárias ou números de cartão com CVV para lançamentos. Todas as informações inseridas são de natureza cadastral gerencial.
            </p>

            <h3>1.4. Uso Aceitável e Integridade do Software</h3>
            <p>
                É expressamente proibido:
            </p>
            <ul>
                <li>Utilizar a plataforma para atividades ilícitas, lavagem de capitais ou fraude.</li>
                <li>Realizar ataques de negação de serviço (DoS/DDoS), engenharia reversa ou tentar burlar mecanismos de autenticação, rate limiting ou proteção CSRF.</li>
                <li>Automatizar scraping ou extração em massa sem autorização expressa via chave de API oficial da plataforma.</li>
            </ul>
        </section>

        <!-- SEÇÃO 2: POLÍTICA DE PRIVACIDADE E LGPD -->
        <section class="terms-section" id="privacidade">
            <h2>2. Política de Privacidade e Proteção de Dados (LGPD)</h2>
            <div class="lgpd-badge">🔒 Em conformidade com a Lei Federal nº 13.709/2018 (LGPD)</div>

            <p>
                A sua privacidade e a soberania sobre os seus dados financeiros são os pilares fundamentais da arquitetura do Droma. Esta política detalha de forma transparente como tratamos e protegemos suas informações.
            </p>

            <h3>2.1. Dados Pessoais Coletados</h3>
            <p>
                Coletamos apenas as informações estritamente indispensáveis para a prestação do serviço:
            </p>
            <ul>
                <li><strong>Dados de Identificação:</strong> Nome completo e endereço de e-mail (para login, recuperação de senha e segurança de sessão).</li>
                <li><strong>Dados Financeiros Inseridos:</strong> Valores de lançamentos, categorias, contas manuais, datas de vencimento e descrições de compras/receitas cadastradas pelo próprio usuário.</li>
                <li><strong>Dados Técnicos:</strong> Endereço IP (utilizado exclusivamente para proteção temporária contra tentativas de força bruta e rate limiting via MySQL) e logs de segurança.</li>
            </ul>

            <h3>2.2. Base Legal para o Tratamento</h3>
            <p>
                O tratamento de seus dados é fundamentado no <strong>Artigo 7º, Inciso V da LGPD</strong> (execução de contrato e procedimentos preliminares a pedido do titular) e no legítimo interesse para segurança e prevenção a fraudes (Artigo 7º, Inciso IX).
            </p>

            <h3>2.3. Processamento com Inteligência Artificial</h3>
            <p>
                O Droma disponibiliza um módulo opcional de consultoria e diagnóstico financeiro por Inteligência Artificial avançada. 
            </p>
            <ul>
                <li>O processamento inteligente ocorre <strong>somente quando o usuário clica expressamente para analisar suas finanças</strong>.</li>
                <li>Apenas resumos agregados de receitas, despesas e categorias são processados de forma criptografada para inferência analítica. Dados confidenciais de credenciais ou senhas nunca são processados.</li>
                <li>Os dados transmitidos para o motor inteligente não são comercializados nem utilizados para fins publicitários ou treinamento público de modelos, respeitando rigorosos termos de proteção e sigilo de dados.</li>
            </ul>

            <h3>2.4. Não Comercialização e Sigilo Absoluto</h3>
            <p>
                <strong>O Droma não vende, não aluga, não compartilha e não cede seus dados pessoais ou financeiros para corretores de dados, parceiros comerciais, anunciantes ou empresas de publicidade sob hipótese alguma.</strong>
            </p>

            <h3>2.5. Soberania e Direitos do Titular (Artigo 18 da LGPD)</h3>
            <p>
                Você possui total soberania sobre suas informações, podendo exercer a qualquer momento:
            </p>
            <ul>
                <li><strong>Acesso e Portabilidade Integral:</strong> Você pode exportar todos os seus dados a qualquer instante em formato estruturado (JSON ou CSV) através da ferramenta de <a href="backup" style="color:var(--color-gold);">Backup dos Dados</a>.</li>
                <li><strong>Correção e Atualização:</strong> Alteração de nome, e-mail e dados na aba de Perfil.</li>
                <li><strong>Exclusão Definitiva (Direito ao Esquecimento):</strong> Solicitação de exclusão permanente de sua conta e de todos os registros financeiros atrelados ao seu identificador.</li>
            </ul>
        </section>

        <!-- SEÇÃO 3: COOKIES -->
        <section class="terms-section" id="cookies">
            <h2>3. Política de Cookies e Tecnologias de Sessão</h2>
            
            <p>
                Diferente da maioria dos sites comerciais, <strong>o Droma NÃO utiliza cookies de rastreamento de terceiros, pixels do Facebook, Google Ads ou scripts publicitários invasivos</strong>.
            </p>
            <p>
                Utilizamos exclusivamente <strong>cookies estritamente técnicos e essenciais</strong>, indispensáveis para que a plataforma funcione:
            </p>
            <ul>
                <li><code>PHPSESSID</code>: Identificador criptográfico de sessão ativa do usuário.</li>
                <li><code>droma_remember</code>: Cookie persistente e seguro (HttpOnly, Secure, SameSite=Lax) utilizado para manter seu dispositivo conectado com segurança por 60 dias.</li>
                <li><code>droma_theme</code>: Armazena exclusivamente a sua preferência de interface (modo claro ou modo escuro) para evitar flashes visuais ao carregar páginas.</li>
                <li><code>droma_sidebar_collapsed</code>: Armazena a preferência de recolhimento da barra lateral no desktop.</li>
            </ul>
        </section>

        <!-- AÇÃO DE ACEITE CASO O USUÁRIO LOGADO AINDA NÃO TENHA ACEITADO -->
        <?php if ($isLoggedIn && !$jaAceitou): ?>
            <div class="accept-card">
                <h3 style="color:var(--color-gold); margin-bottom:8px;">Confirmação de Aceite Obrigatória</h3>
                <p style="margin-bottom:16px;">Para continuar utilizando sua conta no Droma, clique no botão abaixo para registrar formalmente seu consentimento com os Termos e a Política de Privacidade.</p>
                <form action="terms" method="POST">
                    <?= Security::csrfField() ?>
                    <input type="hidden" name="action" value="accept_terms">
                    <button type="submit" class="btn-confirm-accept">
                        ✓ Li e Aceito os Termos de Uso e Política de Privacidade
                    </button>
                </form>
            </div>
        <?php elseif ($isLoggedIn && $jaAceitou): ?>
            <div style="margin-top:24px; padding:16px; border-radius:var(--radius-md); background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); text-align:center; font-size:13px; color:var(--color-emerald);">
                ✓ Você aceitou os Termos de Uso e a Política de Privacidade do Droma.
            </div>
        <?php endif; ?>

    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const sections = document.querySelectorAll('.terms-section');
    const navLinks = document.querySelectorAll('.terms-nav a');

    if ('IntersectionObserver' in window && sections.length > 0) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const id = entry.target.getAttribute('id');
                    navLinks.forEach(link => {
                        if (link.getAttribute('href') === '#' + id) {
                            link.classList.add('ativo');
                        } else {
                            link.classList.remove('ativo');
                        }
                    });
                }
            });
        }, { rootMargin: '-20% 0px -70% 0px' });

        sections.forEach(sec => observer.observe(sec));
    }
});
</script>

</body>
</html>
