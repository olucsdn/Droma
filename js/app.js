/**
 * Droma Financy — App Layout & Interaction Scripts
 */
document.addEventListener('DOMContentLoaded', () => {
    const layout = document.getElementById('appLayout');
    const sidebar = document.getElementById('appSidebar');
    const btnToggleSidebar = document.getElementById('btnToggleSidebar');
    const backdrop = document.getElementById('appBackdrop');
    const btnUserMenu = document.getElementById('btnUserMenu');
    const userDropdownMenu = document.getElementById('userDropdownMenu');
    const btnMaisMenu = document.getElementById('btnMaisMenu');
    const btnQuickAdd = document.getElementById('btnQuickAdd');
    const sheetMais = document.getElementById('sheetMais');
    const sheetAdicionar = document.getElementById('sheetAdicionar');

    // 1. Sidebar Collapse (Desktop)
    const btnToggleSidebarTopbar = document.getElementById('btnToggleSidebarTopbar');
    const sidebarBrand = document.querySelector('.sidebar-brand');

    function toggleSidebar(forceState) {
        if (!layout) return;
        const willBeCollapsed = (forceState !== undefined)
            ? Boolean(forceState)
            : !layout.classList.contains('sidebar-collapsed');

        if (willBeCollapsed) {
            layout.classList.add('sidebar-collapsed');
        } else {
            layout.classList.remove('sidebar-collapsed');
        }

        try {
            localStorage.setItem('droma_sidebar_collapsed', willBeCollapsed ? 'true' : 'false');
            localStorage.setItem('wert_sidebar_collapsed', willBeCollapsed ? 'true' : 'false');
            document.cookie = "droma_sidebar_collapsed=" + (willBeCollapsed ? 'true' : 'false') + "; path=/; max-age=31536000; SameSite=Lax";
            document.cookie = "wert_sidebar_collapsed=" + (willBeCollapsed ? 'true' : 'false') + "; path=/; max-age=31536000; SameSite=Lax";
        } catch (err) {}

        const label = willBeCollapsed ? 'Expandir barra lateral' : 'Recolher barra lateral';
        if (btnToggleSidebar) {
            btnToggleSidebar.setAttribute('title', label);
            btnToggleSidebar.setAttribute('aria-label', label);
        }
        if (btnToggleSidebarTopbar) {
            btnToggleSidebarTopbar.setAttribute('title', label);
            btnToggleSidebarTopbar.setAttribute('aria-label', label);
        }
    }

    if (btnToggleSidebar) {
        btnToggleSidebar.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    if (btnToggleSidebarTopbar) {
        btnToggleSidebarTopbar.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Se a barra estiver recolhida e o usuário clicar no ícone "W", expande a barra suavemente
    if (sidebarBrand) {
        sidebarBrand.addEventListener('click', (e) => {
            if (layout && layout.classList.contains('sidebar-collapsed')) {
                e.preventDefault();
                e.stopPropagation();
                toggleSidebar(false);
            }
        });
    }

    // Sincroniza labels iniciais de acessibilidade
    const isInitiallyCollapsed = layout && layout.classList.contains('sidebar-collapsed');
    const initialLabel = isInitiallyCollapsed ? 'Expandir barra lateral' : 'Recolher barra lateral';
    if (btnToggleSidebar) {
        btnToggleSidebar.setAttribute('title', initialLabel);
        btnToggleSidebar.setAttribute('aria-label', initialLabel);
    }
    if (btnToggleSidebarTopbar) {
        btnToggleSidebarTopbar.setAttribute('title', initialLabel);
        btnToggleSidebarTopbar.setAttribute('aria-label', initialLabel);
    }

    // 2. User Avatar Dropdown
    if (btnUserMenu && userDropdownMenu) {
        btnUserMenu.addEventListener('click', (e) => {
            e.stopPropagation();
            const aberto = userDropdownMenu.classList.toggle('aberto');
            btnUserMenu.setAttribute('aria-expanded', String(aberto));
        });
    }

    // 3. Bottom Sheets Functions (Mobile)
    function openSheet(sheet) {
        if (!sheet) return;
        closeAllSheets();
        sheet.classList.add('aberto');
        if (backdrop) backdrop.classList.add('visivel');
        document.body.style.overflow = 'hidden';
    }

    function closeAllSheets() {
        if (sheetMais) sheetMais.classList.remove('aberto');
        if (sheetAdicionar) sheetAdicionar.classList.remove('aberto');
        if (userDropdownMenu) {
            userDropdownMenu.classList.remove('aberto');
            btnUserMenu?.setAttribute('aria-expanded', 'false');
        }
        if (backdrop) backdrop.classList.remove('visivel');
        document.body.style.overflow = '';
    }

    if (btnMaisMenu) {
        btnMaisMenu.addEventListener('click', (e) => {
            e.preventDefault();
            openSheet(sheetMais);
        });
    }

    if (btnQuickAdd) {
        btnQuickAdd.addEventListener('click', (e) => {
            e.preventDefault();
            openSheet(sheetAdicionar);
        });
    }

    // Close buttons on sheets
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => {
            closeAllSheets();
        });
    });

    // Backdrop click closes everything
    if (backdrop) {
        backdrop.addEventListener('click', () => {
            closeAllSheets();
        });
    }

    // Global Click outside for User Dropdown
    document.addEventListener('click', (e) => {
        if (userDropdownMenu && userDropdownMenu.classList.contains('aberto')) {
            if (!userDropdownMenu.contains(e.target) && !btnUserMenu.contains(e.target)) {
                userDropdownMenu.classList.remove('aberto');
                btnUserMenu?.setAttribute('aria-expanded', 'false');
            }
        }
    });

    // Keyboard ESC handler
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAllSheets();
        }
    });

    // Register Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(err => console.debug('SW reg error:', err));
        });
    }
});
