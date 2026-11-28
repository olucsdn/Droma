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
            document.cookie = "droma_sidebar_collapsed=" + (willBeCollapsed ? 'true' : 'false') + "; path=/; max-age=31536000; SameSite=Lax";
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
            if (!modal.classList.contains('active')) return;
            if (e.key === 'Escape') {
                closeModal();
                if (typeof cancelCallback === 'function') {
                    cancelCallback();
                }
            } else if (e.key === 'Tab') {
                const focusables = Array.from(modal.querySelectorAll('button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'));
                if (focusables.length > 0) {
                    const first = focusables[0];
                    const last = focusables[focusables.length - 1];
                    if (e.shiftKey && document.activeElement === first) {
                        e.preventDefault();
                        last.focus();
                    } else if (!e.shiftKey && document.activeElement === last) {
                        e.preventDefault();
                        first.focus();
                    }
                }
            }
        });

        return modal;
    }

    let lastFocusedBeforeModal = null;

    function closeModal() {
        if (!confirmModal) return;
        confirmModal.classList.remove('active');
        document.body.style.overflow = '';
        if (lastFocusedBeforeModal && typeof lastFocusedBeforeModal.focus === 'function') {
            lastFocusedBeforeModal.focus();
            lastFocusedBeforeModal = null;
        }
    }

    window.DromaConfirm = {
        show(options = {}) {
            lastFocusedBeforeModal = document.activeElement;
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
            if (type === 'logout') {
                okBtn.className = 'btn-confirm-danger';
                iconWrap.className = 'droma-confirm-icon-wrap danger';
                iconWrap.innerHTML = `
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                    </svg>
                `;
            } else if (type === 'danger') {
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

            // Fecha eventuais dropdowns ou sheets móveis abertos para foco limpo no modal
            document.querySelectorAll('.user-dropdown-menu.aberto, .bottom-sheet.aberto, .app-backdrop.visivel').forEach(el => {
                el.classList.remove('aberto');
                el.classList.remove('visivel');
            });

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
        if (m.includes('sair') || m.includes('deslogar')) return 'Sair da Conta?';
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

    // Purga completa de dados residuais offline do navegador ao deslogar
    function clearClientOfflineStorage() {
        try {
            localStorage.removeItem('droma_cached_meta');
            localStorage.removeItem('droma_fallback_pending');
            if ('indexedDB' in window) {
                indexedDB.deleteDatabase('DromaOfflineDB');
            }
            if ('caches' in window) {
                caches.keys().then(keys => {
                    keys.forEach(k => {
                        if (k.startsWith('droma-cache-')) {
                            caches.delete(k);
                        }
                    });
                }).catch(() => {});
            }
        } catch (err) {}
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
                if (type === 'logout') {
                    clearClientOfflineStorage();
                }
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

    // 2. Interceptação de botões individuais com onclick="return confirm(...)", data-confirm ou link de logout
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('button, a, [data-confirm]');
        if (!btn) return;

        if (btn._dromaConfirmed) return;

        const href = (btn.tagName === 'A' ? (btn.getAttribute('href') || '') : '');
        const isLogoutLink = (href === 'logout' || href === 'logout.php' || href.endsWith('/logout') || href.endsWith('/logout.php'));

        let confirmMsg = btn.dataset.confirm || (isLogoutLink ? 'Deseja realmente sair da sua conta?' : null) || extrairMensagem(btn.getAttribute('onclick'));
        if (!confirmMsg) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        const form = btn.closest('form');
        const isLogout = isLogoutLink || btn.dataset.confirmType === 'logout' || confirmMsg.toLowerCase().includes('sair') || confirmMsg.toLowerCase().includes('deslogar');
        const title = btn.dataset.confirmTitle || (isLogout ? 'Sair da Conta?' : sugerirTitulo(confirmMsg));
        const isDanger = isLogout || btn.dataset.confirmType === 'danger' || confirmMsg.toLowerCase().includes('excluir') || confirmMsg.toLowerCase().includes('invalida');
        const type = btn.dataset.confirmType || (isLogout ? 'logout' : (isDanger ? 'danger' : 'info'));
        const confirmBtnText = btn.dataset.confirmBtn || (isLogout ? 'Sair da Conta' : (isDanger ? 'Confirmar' : 'Continuar'));

        window.DromaConfirm.show({
            title: title,
            message: confirmMsg,
            confirmText: confirmBtnText,
            cancelText: 'Cancelar',
            type: type,
            onConfirm: () => {
                if (isLogout) {
                    clearClientOfflineStorage();
                }
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

    // 3. Prevenção Global contra Double-Submit em formulários (Proteção contra cliques duplos)
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form || e.defaultPrevented) return;

        // Se o formulário já está sendo enviado, previne envios paralelos
        if (form.dataset.submitting === 'true') {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }

        const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submitBtn) {
            form.dataset.submitting = 'true';
            const origHtml = submitBtn.innerHTML;
            const origWidth = submitBtn.offsetWidth;
            if (origWidth > 0) {
                submitBtn.style.minWidth = origWidth + 'px';
            }
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.75';
            submitBtn.style.cursor = 'not-allowed';
            if (submitBtn.tagName === 'BUTTON') {
                submitBtn.innerHTML = 'Processando...';
            }

            // Timeout de segurança: restaura botão após 10s se não houver redirecionamento
            setTimeout(() => {
                form.dataset.submitting = 'false';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '';
                submitBtn.style.cursor = '';
                submitBtn.style.minWidth = '';
                submitBtn.innerHTML = origHtml;
            }, 10000);
        }
    }, false);
})();
