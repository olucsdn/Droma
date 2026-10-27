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
    }

    if (btnToggleSidebar) {
        btnToggleSidebar.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Se a barra estiver recolhida e o usuário clicar na marca, expande a barra suavemente
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

    // Register Service Worker & Pré-aquecimento de rotas vitais
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(err => console.debug('SW reg error:', err));

            // Quando online e autenticado, pré-aquece formulário e páginas essenciais no cache
            if ('caches' in window && navigator.onLine) {
                setTimeout(() => {
                    caches.open('droma-cache-v6').then(cache => {
                        cache.add('add_expense').catch(() => {});
                        cache.add('add_expense.php').catch(() => {});
                        cache.add('./').catch(() => {});
                    }).catch(() => {});
                }, 2000);
            }
        });
    }
});

// ==============================================================================
// 4. Modal de Confirmação Global & Elegante (Substituto do confirm() nativo)
// ==============================================================================
(function() {
    let confirmModal = null;
    let confirmCallback = null;
    let cancelCallback = null;

    function createConfirmModal() {
        let modal = document.getElementById('dromaConfirmModal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'dromaConfirmModal';
        modal.className = 'droma-confirm-backdrop';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.innerHTML = `
            <div class="droma-confirm-card">
                <div class="droma-confirm-icon-wrap danger" id="dromaConfirmIcon">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        <line x1="10" y1="11" x2="10" y2="17"></line>
                        <line x1="14" y1="11" x2="14" y2="17"></line>
                    </svg>
                </div>
                <h3 class="droma-confirm-title" id="dromaConfirmTitle">Confirmar Exclusão</h3>
                <p class="droma-confirm-msg" id="dromaConfirmMsg">Deseja realmente excluir este item?</p>
                <div class="droma-confirm-actions">
                    <button type="button" class="btn-confirm-cancel" id="dromaConfirmCancel">Cancelar</button>
                    <button type="button" class="btn-confirm-danger" id="dromaConfirmOk">Excluir</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const btnOk = modal.querySelector('#dromaConfirmOk');
        const btnCancel = modal.querySelector('#dromaConfirmCancel');

        btnOk.addEventListener('click', () => {
            closeModal();
            if (typeof confirmCallback === 'function') {
                confirmCallback();
            }
        });

        btnCancel.addEventListener('click', () => {
            closeModal();
            if (typeof cancelCallback === 'function') {
                cancelCallback();
            }
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
                if (typeof cancelCallback === 'function') {
                    cancelCallback();
                }
            }
        });

        document.addEventListener('keydown', (e) => {
            if (modal.classList.contains('active') && e.key === 'Escape') {
                closeModal();
                if (typeof cancelCallback === 'function') {
                    cancelCallback();
                }
            }
        });

        return modal;
    }

    function closeModal() {
        if (!confirmModal) return;
        confirmModal.classList.remove('active');
        document.body.style.overflow = '';
    }

    window.DromaConfirm = {
        show(options = {}) {
            confirmModal = createConfirmModal();
            const titleEl = confirmModal.querySelector('#dromaConfirmTitle');
            const msgEl = confirmModal.querySelector('#dromaConfirmMsg');
            const okBtn = confirmModal.querySelector('#dromaConfirmOk');
            const cancelBtn = confirmModal.querySelector('#dromaConfirmCancel');
            const iconWrap = confirmModal.querySelector('#dromaConfirmIcon');

            titleEl.textContent = options.title || 'Confirmar ação';
            msgEl.textContent = options.message || 'Deseja realmente continuar?';
            okBtn.textContent = options.confirmText || 'Confirmar';
            cancelBtn.textContent = options.cancelText || 'Cancelar';

            const type = options.type || 'danger';
            if (type === 'danger') {
                okBtn.className = 'btn-confirm-danger';
                iconWrap.className = 'droma-confirm-icon-wrap danger';
                iconWrap.innerHTML = `
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"></polyline>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                        <line x1="10" y1="11" x2="10" y2="17"></line>
                        <line x1="14" y1="11" x2="14" y2="17"></line>
                    </svg>
                `;
            } else {
                okBtn.className = 'btn-confirm-primary';
                iconWrap.className = 'droma-confirm-icon-wrap info';
                iconWrap.innerHTML = `
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                `;
            }

            confirmCallback = options.onConfirm || null;
            cancelCallback = options.onCancel || null;

            confirmModal.classList.add('active');
            document.body.style.overflow = 'hidden';

            setTimeout(() => {
                cancelBtn.focus();
            }, 60);
        },
        close: closeModal
    };

    function extrairMensagem(str) {
        if (!str) return null;
        const match = str.match(/confirm\s*\(\s*(['"`])(.*?)\1\s*\)/);
        return match ? match[2] : null;
    }

    function sugerirTitulo(msg) {
        const m = (msg || '').toLowerCase();
        if (m.includes('lançamento') || m.includes('lancamento')) return 'Excluir Lançamento?';
        if (m.includes('conta')) return 'Excluir Conta?';
        if (m.includes('cartão') || m.includes('cartao')) return 'Excluir Cartão?';
        if (m.includes('categoria')) return 'Excluir Categoria?';
        if (m.includes('meta')) return 'Excluir Meta?';
        if (m.includes('investimento')) return 'Excluir Investimento?';
        if (m.includes('recorrência') || m.includes('recorrencia')) return 'Excluir Recorrência?';
        if (m.includes('restauração') || m.includes('restauracao')) return 'Restaurar Dados?';
        if (m.includes('token') || m.includes('chave')) return 'Gerar Chave de API?';
        return 'Confirmar Exclusão?';
    }

    // 1. Interceptação global de envios de formulário
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form) return;

        if (form._dromaConfirmed) {
            return;
        }

        let confirmMsg = form.dataset.confirm || extrairMensagem(form.getAttribute('onsubmit'));
        if (!confirmMsg) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        const title = form.dataset.confirmTitle || sugerirTitulo(confirmMsg);
        const confirmBtnText = form.dataset.confirmBtn || 'Excluir';
        const type = form.dataset.confirmType || 'danger';

        window.DromaConfirm.show({
            title: title,
            message: confirmMsg,
            confirmText: confirmBtnText,
            cancelText: 'Cancelar',
            type: type,
            onConfirm: () => {
                form._dromaConfirmed = true;
                form.removeAttribute('onsubmit');
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
    }, true);

    // 2. Interceptação de botões individuais com onclick="return confirm(...)" ou data-confirm
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('button, a, [data-confirm]');
        if (!btn) return;

        if (btn._dromaConfirmed) return;

        let confirmMsg = btn.dataset.confirm || extrairMensagem(btn.getAttribute('onclick'));
        if (!confirmMsg) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        const form = btn.closest('form');
        const title = btn.dataset.confirmTitle || sugerirTitulo(confirmMsg);
        const isDanger = confirmMsg.toLowerCase().includes('excluir') || confirmMsg.toLowerCase().includes('invalida');

        window.DromaConfirm.show({
            title: title,
            message: confirmMsg,
            confirmText: btn.dataset.confirmBtn || (isDanger ? 'Confirmar' : 'Continuar'),
            cancelText: 'Cancelar',
            type: isDanger ? 'danger' : 'info',
            onConfirm: () => {
                btn._dromaConfirmed = true;
                btn.removeAttribute('onclick');
                if (btn.tagName === 'A' && btn.href) {
                    window.location.href = btn.href;
                } else if (form) {
                    form._dromaConfirmed = true;
                    btn.click();
                }
            }
        });
    }, true);
})();
