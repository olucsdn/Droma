<?php
require_once "src/Security.php";
Security::initSession();

$isLoggedIn = !empty($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark" data-theme-fixed="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Droma — Organização Financeira Pessoal</title>
    <meta name="description" content="Organize contas bancárias, cartões de crédito, metas, investimentos e vencimentos em um único lugar, sem planilhas.">
    <?php 
    $dromaFixedDark = true;
    require_once "includes/head_favicons.php"; 
    ?>
    <link rel="stylesheet" href="assets/css/style_landing.css?v=<?= filemtime('assets/css/style_landing.css') ?>">
</head>
<body>

<div class="bg-glow-top"></div>

<!-- Top Navigation -->
<header class="landing-navbar">
    <div class="container">
        <div class="nav-inner">
            <a href="landing" class="brand">
                <div class="brand-icon">D</div>
                <div class="brand-text">Droma<span>.</span></div>
            </a>

            <ul class="nav-links">
                <li><a href="#recursos">Recursos</a></li>
                <li><a href="#assistente">Assistente</a></li>
                <li><a href="#seguranca">Segurança</a></li>
                <li><a href="#faq">Dúvidas</a></li>
            </ul>

            <div class="nav-actions">
                <?php if ($isLoggedIn): ?>
                    <a href="./" class="btn-nav-primary">Acessar Meu Painel →</a>
                <?php else: ?>
                    <a href="login" class="btn-nav-login">Entrar</a>
                    <a href="register" class="btn-nav-primary">Criar Conta Grátis</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</header>

<main>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-copy">
            <div class="hero-kicker"><span></span> Clareza para cuidar do seu dinheiro</div>
            <h1 class="hero-title">
                Seu dinheiro, <span class="highlight-gold">finalmente em ordem.</span>
            </h1>

            <p class="hero-subtitle">
                Contas, cartões, metas e vencimentos em um painel simples. Saiba o que já aconteceu e o que ainda vem pela frente.
            </p>

            <div class="hero-cta-group">
                <a href="register" class="btn-hero-primary">
                    Organizar minhas finanças
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
                <a href="#recursos" class="btn-hero-secondary">
                    Ver recursos
                </a>
            </div>

            <div class="hero-trust-bar">
                <div class="hero-trust-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                    <span>Sem pedir senha do seu banco</span>
                </div>
                <div class="hero-trust-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                    <span>Uso gratuito</span>
                </div>
                <div class="hero-trust-item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                    <span>Exporte seus dados quando quiser</span>
                </div>
            </div>

            </div>
            <!-- Dashboard Mockup Showcase -->
            <div class="mockup-container">
                <div class="mockup-header-bar">
                    <span class="mockup-header-title">Resumo financeiro</span>
                    <span class="mockup-header-period">Novembro</span>
                </div>

                <div class="mockup-content">
                    <div class="mockup-cards-grid">
                        <div class="mockup-stat-card">
                            <div class="mockup-stat-label">Saldo em contas</div>
                            <div class="mockup-stat-value gold">R$ 14.850,00</div>
                        </div>
                        <div class="mockup-stat-card">
                            <div class="mockup-stat-label">Entradas no mês</div>
                            <div class="mockup-stat-value green">+ R$ 8.200,00</div>
                        </div>
                        <div class="mockup-stat-card">
                            <div class="mockup-stat-label">Saídas no mês</div>
                            <div class="mockup-stat-value red">- R$ 3.420,00</div>
                        </div>
                        <div class="mockup-stat-card">
                            <div class="mockup-stat-label">Previsão de fechamento</div>
                            <div class="mockup-stat-value neutral">R$ 19.630,00</div>
                        </div>
                    </div>

                    <div class="mockup-ai-card">
                        <span class="mockup-ai-badge">Resumo do mês</span>
                        <div class="mockup-ai-text">
                            <strong>Acompanhamento de orçamento:</strong> Você utilizou 62% do teto definido para Alimentação neste mês e tem 2 contas pendentes para os próximos 7 dias.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Recursos Principais -->
    <section class="features-section" id="recursos">
        <div class="container">
            <div class="section-tag">Funcionalidades</div>
            <h2 class="section-title">O essencial para acompanhar seu dinheiro</h2>
            <p class="section-subtitle">Ferramentas diretas para registrar movimentações, planejar o mês e saber exatamente quanto você tem disponível.</p>

            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                    </div>
                    <h3>Contas e Cartões</h3>
                    <p>Gerencie saldo em múltiplas contas bancárias e acompanhe faturas, limites e compras parceladas em um só lugar.</p>
                </div>

                <div class="feature-card" id="assistente">
                    <div class="feature-icon-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                    </div>
                    <h3>Relatórios e Análises</h3>
                    <p>Visualize entradas, saídas por categoria, comparativos mensais e exporte relatórios em CSV ou PDF quando precisar.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>
                    </div>
                    <h3>Metas e Investimentos</h3>
                    <p>Defina objetivos de economia com prazo e registre aportes, rendimentos e resgates vinculados às suas contas.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                    </div>
                    <h3>Calendário de Vencimentos</h3>
                    <p>Veja contas pendentes e recorrências organizadas por data para evitar atrasos e juros desnecessários.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                    </div>
                    <h3>Importação de Extratos</h3>
                    <p>Traga lançamentos do seu banco em arquivos OFX ou CSV com revisão prévia antes de confirmar no seu extrato.</p>
                </div>

                <div class="feature-card">
                    <div class="feature-icon-box">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" x2="12.01" y1="18" y2="18"/></svg>
                    </div>
                    <h3>Acesso no Celular e Computador</h3>
                    <p>Use direto do navegador ou adicione à tela inicial do celular para lançar despesas rápidas no dia a dia.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Como Funciona -->
    <section class="steps-section">
        <div class="container">
            <div style="text-align: center;">
                <div class="section-tag">Primeiros Passos</div>
                <h2 class="section-title">Como começar</h2>
            </div>

            <div class="steps-grid">
                <div class="step-card">
                    <div class="step-number">01</div>
                    <h4>Crie sua conta</h4>
                    <p>Cadastro simples com nome, e-mail e senha, sem precisar conectar ou informar senhas do seu banco.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">02</div>
                    <h4>Registre ou importe movimentações</h4>
                    <p>Adicione receitas e despesas manualmente ou importe o extrato em OFX e CSV direto do seu internet banking.</p>
                </div>

                <div class="step-card">
                    <div class="step-number">03</div>
                    <h4>Acompanhe seu mês</h4>
                    <p>Veja a previsão de fechamento, limites por categoria, vencimentos próximos e evolução das suas metas.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Segurança e Privacidade -->
    <section class="container" id="seguranca">
        <div class="security-banner">
            <div class="security-content">
                <div class="section-tag" style="color: #34D399;">Privacidade</div>
                <h3>Seus registros financeiros ficam sob o seu controle.</h3>
                <p>
                    O Droma não vende nem compartilha suas informações com terceiros. Você pode exportar um backup completo ou excluir seus dados a qualquer momento, em conformidade com a LGPD.
                </p>
            </div>
            <div class="security-badges">
                <div class="sec-pill">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px; margin-right:6px;"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Sem acesso às senhas bancárias
                </div>
                <div class="sec-pill">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px; margin-right:6px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Dados isolados por conta
                </div>
                <div class="sec-pill">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-3px; margin-right:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    Backup completo a qualquer hora
                </div>
            </div>
        </div>
    </section>

    <!-- Perguntas Frequentes -->
    <section class="faq-section" id="faq">
        <div class="container">
            <div style="text-align: center; margin-bottom: 40px;">
                <div class="section-tag">Dúvidas</div>
                <h2 class="section-title">Perguntas Frequentes</h2>
            </div>

            <div class="faq-list">
                <div class="faq-item">
                    <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                        <span>O Droma é gratuito?</span>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Sim. Você pode criar sua conta gratuitamente e usar controle de contas, cartões, metas, calendário de vencimentos, importação de extratos e relatórios.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                        <span>Preciso informar a senha do meu banco?</span>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Não. O Droma não solicita senhas nem tokens bancários. Os lançamentos são feitos por você ou via importação de arquivos de extrato (OFX ou CSV).
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                        <span>Posso exportar meus dados se quiser sair?</span>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Sim. Você pode baixar um backup completo dos seus lançamentos, contas, metas e cartões a qualquer momento pela tela de Backup.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" role="button" tabindex="0" aria-expanded="false">
                        <span>Posso usar no celular como aplicativo?</span>
                        <span class="faq-icon">+</span>
                    </div>
                    <div class="faq-answer">
                        Sim. Ao abrir o Droma pelo navegador do celular (Android ou iPhone), basta tocar em "Adicionar à tela de início" para ter um atalho em tela cheia.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Final -->
    <section class="final-cta-section">
        <div class="container">
            <div class="final-cta-box">
                <h2>Comece a organizar seu mês hoje</h2>
                <p>Crie sua conta em poucos segundos e centralize suas contas, cartões e metas em um único painel.</p>
                <a href="register" class="btn-hero-primary btn-cta-final">
                    Criar conta gratuita
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </section>
</main>

<!-- Footer -->
<footer class="landing-footer">
    <div class="container">
        <div class="footer-inner">
            <div class="brand">
                <div class="brand-icon" style="width:30px; height:30px; font-size:16px;">D</div>
                <div class="brand-text" style="font-size:18px;">Droma<span>.</span></div>
            </div>

            <div class="footer-links">
                <a href="terms">Termos de Uso</a>
                <a href="terms#privacidade">Privacidade & LGPD</a>
                <a href="login">Entrar</a>
                <a href="register">Criar Conta</a>
                <a href="recover">Recuperar Senha</a>
            </div>

            <div class="footer-copy">
                &copy; <?= date('Y') ?> Droma Financy &bull; Organização Financeira Pessoal.
            </div>
        </div>
    </div>
</footer>

<script>
<?php if (!$isLoggedIn): ?>
// Garante a eliminação imediata de metadados residuais de contas/categorias no navegador após deslogar
try {
    localStorage.removeItem('droma_cached_meta');
    localStorage.removeItem('droma_fallback_pending');
    if ('indexedDB' in window) {
        indexedDB.deleteDatabase('DromaOfflineDB');
    }
} catch (e) {}
<?php endif; ?>

// Interatividade das Perguntas Frequentes (FAQ Accordion)
document.querySelectorAll('.faq-item').forEach(function(item) {
    var question = item.querySelector('.faq-question');
    function toggleFaq() {
        var isOpen = item.classList.contains('open');
        document.querySelectorAll('.faq-item').forEach(function(other) {
            other.classList.remove('open');
            other.querySelector('.faq-question').setAttribute('aria-expanded', 'false');
        });
        if (!isOpen) {
            item.classList.add('open');
            question.setAttribute('aria-expanded', 'true');
        }
    }
    question.addEventListener('click', toggleFaq);
    question.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggleFaq();
        }
    });
});
</script>

</body>
</html>
