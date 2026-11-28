<?php
if (!isset($paginaAtiva)) $paginaAtiva = '';
if (!isset($tituloPagina)) $tituloPagina = 'Droma Financy';

$userName = htmlspecialchars($_SESSION['user_name'] ?? 'Usuário');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$userInitial = mb_strtoupper(mb_substr($userName, 0, 1, 'UTF-8'), 'UTF-8');

$diasSemana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
$meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$diaSemanaNum = (int)date('w');
$mesNum = (int)date('n');
$dataHojeFormatada = $diasSemana[$diaSemanaNum] . ', ' . date('j') . ' de ' . $meses[$mesNum];
$dataHojeCurta = date('d/m/Y');
$isSidebarCollapsed = (!empty($_COOKIE['droma_sidebar_collapsed']) ? $_COOKIE['droma_sidebar_collapsed'] === 'true' : (!empty($_COOKIE['wert_sidebar_collapsed']) && $_COOKIE['wert_sidebar_collapsed'] === 'true'));
?>
<div class="app-layout <?= $isSidebarCollapsed ? 'sidebar-collapsed' : '' ?>" id="appLayout">
    <script>
        (function() {
            try {
                var saved = localStorage.getItem('droma_sidebar_collapsed') || localStorage.getItem('wert_sidebar_collapsed');
                var layout = document.getElementById('appLayout');
                if (saved === 'true' && window.innerWidth > 768) {
                    if (layout && !layout.classList.contains('sidebar-collapsed')) {
                        layout.classList.add('sidebar-collapsed');
                    }
                    document.cookie = "droma_sidebar_collapsed=true; path=/; max-age=31536000; SameSite=Lax";
                } else if (saved === 'false') {
                    if (layout && layout.classList.contains('sidebar-collapsed')) {
                        layout.classList.remove('sidebar-collapsed');
                    }
                    document.cookie = "droma_sidebar_collapsed=false; path=/; max-age=31536000; SameSite=Lax";
                }
            } catch(e) {}
        })();
    </script>
    <!-- Barra Lateral Fixa (Desktop) -->
    <aside class="app-sidebar" id="appSidebar" aria-label="Navegação lateral">
        <div class="sidebar-header">
            <a href="./" class="sidebar-brand" title="Droma Financy">
                <div class="brand-icon">D</div>
                <div class="brand-text">Droma<span>.</span></div>
            </a>
            <button type="button" class="sidebar-collapse-btn" id="btnToggleSidebar" title="Recolher barra lateral" aria-label="Recolher barra lateral">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <!-- Grupo Principal -->
            <div class="nav-group">
                <div class="nav-group-title">Principal</div>
                <ul class="nav-group-items">
                    <li>
                        <a href="./" class="nav-item <?= $paginaAtiva === 'painel' ? 'ativo' : '' ?>" title="Painel Geral">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                            <span>Painel</span>
                        </a>
                    </li>
                    <li>
                        <a href="calendar" class="nav-item <?= $paginaAtiva === 'calendario' ? 'ativo' : '' ?>" title="Calendário Financeiro">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                            <span>Calendário</span>
                        </a>
                    </li>
                    <li>
                        <a href="statement" class="nav-item <?= $paginaAtiva === 'extrato' ? 'ativo' : '' ?>" title="Extrato e Filtros">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                            <span>Extrato</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Grupo Finanças -->
            <div class="nav-group">
                <div class="nav-group-title">Finanças</div>
                <ul class="nav-group-items">
                    <li>
                        <a href="accounts" class="nav-item <?= $paginaAtiva === 'contas' ? 'ativo' : '' ?>" title="Gerenciar Contas">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v7h3v-7H4zm6 0v7h3v-7h-3zM2 22h19v-3H2v3zm14-12v7h3v-7h-3zm-4.5-9L2 6v2h19V6l-9.5-5z"/></svg>
                            <span>Contas</span>
                        </a>
                    </li>
                    <li>
                        <a href="cards" class="nav-item <?= $paginaAtiva === 'cartoes' ? 'ativo' : '' ?>" title="Cartões de Crédito">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>
                            <span>Cartões</span>
                        </a>
                    </li>
                    <li>
                        <a href="categories" class="nav-item <?= $paginaAtiva === 'categorias' ? 'ativo' : '' ?>" title="Gerenciar Categorias">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>
                            <span>Categorias</span>
                        </a>
                    </li>
                    <li>
                        <a href="recurring" class="nav-item <?= $paginaAtiva === 'recorrencias' ? 'ativo' : '' ?>" title="Lançamentos Recorrentes">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                            <span>Recorrências</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Grupo Planejamento -->
            <div class="nav-group">
                <div class="nav-group-title">Planejamento</div>
                <ul class="nav-group-items">
                    <li>
                        <a href="goals" class="nav-item <?= $paginaAtiva === 'metas' ? 'ativo' : '' ?>" title="Metas Financeiras">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" fill="none"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2" fill="none"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg>
                            <span>Metas</span>
                        </a>
                    </li>
                    <li>
                        <a href="investments" class="nav-item <?= $paginaAtiva === 'investimentos' ? 'ativo' : '' ?>" title="Gestão de Investimentos">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>
                            <span>Investimentos</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Grupo Análise -->
            <div class="nav-group">
                <div class="nav-group-title">Análise</div>
                <ul class="nav-group-items">
                    <li>
                        <a href="reports" class="nav-item <?= $paginaAtiva === 'relatorios' ? 'ativo' : '' ?>" title="Relatórios Analíticos">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9.2h3V19H5zM10.6 5h2.8v14.2h-2.8zm5.6 8h2.8v6.2h-2.8z"/><path d="M19 19H5V3H3v18h18v-2z"/></svg>
                            <span>Relatórios</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Grupo Ferramentas -->
            <div class="nav-group">
                <div class="nav-group-title">Ferramentas</div>
                <ul class="nav-group-items">
                    <li>
                        <a href="assistant" class="nav-item <?= $paginaAtiva === 'assistente' ? 'ativo' : '' ?>" title="Assistente Financeiro IA">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25z"/></svg>
                            <span>Assistente IA</span>
                        </a>
                    </li>
                    <li>
                        <a href="backup" class="nav-item <?= $paginaAtiva === 'backup' ? 'ativo' : '' ?>" title="Backup e Dados">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                            <span>Backup</span>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="sidebar-footer">
            <button type="button" class="sidebar-theme-btn" id="btnThemeToggleSidebar" onclick="window.toggleDromaTheme()" title="Alternar Modo Claro / Escuro" aria-label="Alternar Modo Claro / Escuro">
                <span class="theme-icon-dark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96a.996.996 0 0 0 0-1.41.996.996 0 0 0-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.36a.996.996 0 0 0 0-1.41.996.996 0 0 0 0 1.41l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/></svg>
                </span>
                <span class="theme-icon-light" aria-hidden="true" style="display:none;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12.3 2a10 10 0 0 0-1.9 20 10 10 0 0 0 9.6-6.4 1 1 0 0 0-1.2-1.3 8 8 0 1 1-7.8-11.1 1 1 0 0 0-1.3-1.2h2.6z"/></svg>
                </span>
                <span class="sidebar-theme-text sidebar-footer-text">Modo Claro</span>
            </button>
            <div class="sidebar-footer-row">
                <a href="profile" class="sidebar-user-link" title="Meu Perfil">
                    <div class="avatar-mini"><?= $userInitial ?></div>
                    <div class="sidebar-user-info sidebar-footer-text">
                        <span class="name"><?= $userName ?></span>
                        <span class="meta">Meu Perfil</span>
                    </div>
                </a>
                <form action="logout" method="POST" style="display:inline; margin:0;">
                    <?= Security::csrfField() ?>
                    <button type="submit" class="sidebar-logout-btn sidebar-footer-text" title="Sair do sistema" aria-label="Sair" data-confirm="Deseja realmente sair da sua conta?" data-confirm-title="Sair da Conta?" data-confirm-btn="Sair da Conta" data-confirm-type="logout" style="border:none; cursor:pointer;">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <!-- Overlay para fechar sheets e dropdowns no mobile -->
    <div class="app-backdrop" id="appBackdrop"></div>

    <!-- Área Principal de Conteúdo -->
    <div class="app-main">
        <!-- Topbar Compacta -->
        <header class="app-topbar">
            <div class="topbar-left">
                <!-- Mobile Brand -->
                <a href="./" class="topbar-mobile-brand" title="Droma Financy">
                    <div class="brand-icon">D</div>
                    <div class="brand-text">Droma<span>.</span></div>
                </a>

                <!-- Desktop Greeting & Date -->
                <div class="topbar-greeting-desktop">
                    <div class="topbar-user-greeting">Olá, <strong><?= $userName ?></strong></div>
                    <div class="topbar-date"><?= $dataHojeFormatada ?></div>
                </div>
            </div>

            <div class="topbar-right">

                <!-- Botão Destacado Novo lançamento (Desktop) -->
                <a class="btn-novo-lancamento" href="add_expense">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    <span>Novo lançamento</span>
                </a>

                <!-- Menu de Avatar do Usuário -->
                <div class="topbar-user-menu">
                    <button type="button" class="avatar-btn" id="btnUserMenu" aria-label="Abrir menu de perfil" aria-expanded="false" title="Menu de usuário">
                        <?= $userInitial ?>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu" role="menu">
                        <div class="dropdown-user-info">
                            <div class="name"><?= $userName ?></div>
                            <div class="role">Conta Ativa</div>
                        </div>
                        <a href="profile" class="dropdown-link" role="menuitem">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            <span>Meu Perfil</span>
                        </a>
                        <a href="backup" class="dropdown-link" role="menuitem">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                            <span>Backup & Dados</span>
                        </a>
                        <a href="terms" class="dropdown-link" role="menuitem" target="_blank">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                            <span>Termos & Privacidade</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <form action="logout" method="POST" style="margin:0;">
                            <?= Security::csrfField() ?>
                            <button type="submit" class="dropdown-link sair-link" role="menuitem" data-confirm="Deseja realmente sair da sua conta?" data-confirm-title="Sair da Conta?" data-confirm-btn="Sair da Conta" data-confirm-type="logout" style="width:100%; background:none; border:none; text-align:left; cursor:pointer; font:inherit;">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                                <span>Sair da Conta</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <!-- Saudação e Data no Mobile (Abaixo do cabeçalho, dentro do conteúdo) -->
        <div class="mobile-greeting-bar">
            <span>Olá, <strong><?= $userName ?></strong></span>
            <span class="mobile-date"><?= $dataHojeFormatada ?></span>
        </div>


