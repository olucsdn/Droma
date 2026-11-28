    </div><!-- /.app-main -->

    <!-- Barra de Navegação Inferior (Mobile - 5 opções) -->
    <nav class="app-bottom-nav" aria-label="Navegação inferior mobile">
        <a href="./" class="bottom-nav-item <?= ($paginaAtiva ?? '') === 'painel' ? 'ativo' : '' ?>" title="Ir para o Início">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
            <span>Início</span>
        </a>
        <a href="statement" class="bottom-nav-item <?= ($paginaAtiva ?? '') === 'extrato' ? 'ativo' : '' ?>" title="Ir para o Extrato">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
            <span>Extrato</span>
        </a>
        <button type="button" class="bottom-nav-add-btn" id="btnQuickAdd" aria-label="Criar novo lançamento ou transferência" title="Novo Lançamento">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        </button>
        <button type="button" class="bottom-nav-item" id="btnThemeToggleMobile" onclick="window.toggleDromaTheme()" title="Alternar Modo Claro / Escuro" aria-label="Alternar Modo Claro / Escuro">
            <span class="theme-icon-dark" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5zM2 13h2c.55 0 1-.45 1-1s-.45-1-1-1H2c-.55 0-1 .45-1 1s.45 1 1 1zm18 0h2c.55 0 1-.45 1-1s-.45-1-1-1h-2c-.55 0-1 .45-1 1s.45 1 1 1zM11 2v2c0 .55.45 1 1 1s1-.45 1-1V2c0-.55-.45-1-1-1s-1 .45-1 1zm0 18v2c0 .55.45 1 1 1s1-.45 1-1v-2c0-.55-.45-1-1-1s-1 .45-1 1zM5.99 4.58a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 0 .996.996 0 0 0 0 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41l-1.06-1.06zm1.06-10.96a.996.996 0 0 0 0-1.41.996.996 0 0 0-1.41 0l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.36a.996.996 0 0 0 0-1.41.996.996 0 0 0 0 1.41l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/></svg>
            </span>
            <span class="theme-icon-light" aria-hidden="true" style="display:none;">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12.3 2a10 10 0 0 0-1.9 20 10 10 0 0 0 9.6-6.4 1 1 0 0 0-1.2-1.3 8 8 0 1 1-7.8-11.1 1 1 0 0 0-1.3-1.2h2.6z"/></svg>
            </span>
            <span class="bottom-nav-theme-label">Tema</span>
        </button>
        <button type="button" class="bottom-nav-item <?= in_array(($paginaAtiva ?? ''), ['calendario', 'metas', 'investimentos', 'contas', 'cartoes', 'categorias', 'recorrencias', 'relatorios', 'assistente', 'perfil', 'backup'], true) ? 'ativo' : '' ?>" id="btnMaisMenu" aria-label="Abrir mais módulos" title="Mais módulos">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h4V4H4v4zm6 12h4v-4h-4v4zm-6 0h4v-4H4v4zm0-6h4v-4H4v4zm6 0h4v-4h-4v4zm6-10v4h4V4h-4zm-6 4h4V4h-4v4zm6 6h4v-4h-4v4zm0 6h4v-4h-4v4z"/></svg>
            <span>Mais</span>
        </button>
    </nav>

    <!-- Bottom Sheet "Mais Módulos" (Mobile) -->
    <div class="bottom-sheet" id="sheetMais" role="dialog" aria-modal="true" aria-labelledby="tituloSheetMais">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3 id="tituloSheetMais">Mais Recursos</h3>
            <button type="button" class="sheet-close-btn" data-close="sheetMais" aria-label="Fechar menu">✕</button>
        </div>

        <div class="sheet-grid">
            <a href="calendar" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                </div>
                <span>Calendário</span>
            </a>
            <a href="goals" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2" fill="none"/><circle cx="12" cy="12" r="5" stroke="currentColor" stroke-width="2" fill="none"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg>
                </div>
                <span>Metas</span>
            </a>
            <a href="investments" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>
                </div>
                <span>Investimentos</span>
            </a>
            <a href="accounts" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 10v7h3v-7H4zm6 0v7h3v-7h-3zM2 22h19v-3H2v3zm14-12v7h3v-7h-3zm-4.5-9L2 6v2h19V6l-9.5-5z"/></svg>
                </div>
                <span>Contas</span>
            </a>
            <a href="cards" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>
                </div>
                <span>Cartões</span>
            </a>
            <a href="categories" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/></svg>
                </div>
                <span>Categorias</span>
            </a>
            <a href="recurring" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
                </div>
                <span>Recorrências</span>
            </a>
            <a href="reports" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9.2h3V19H5zM10.6 5h2.8v14.2h-2.8zm5.6 8h2.8v6.2h-2.8z"/><path d="M19 19H5V3H3v18h18v-2z"/></svg>
                </div>
                <span>Relatórios</span>
            </a>
            <a href="assistant" class="sheet-grid-item">
                <div class="sheet-icon-bubble">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25z"/></svg>
                </div>
                <span>Assistente IA</span>
            </a>
        </div>

        <div style="border-top:1px solid var(--color-divider); padding-top:12px; display:flex; flex-direction:column; gap:8px;">
            <a href="profile" class="quick-add-btn" style="padding:10px 14px; font-size:13.5px;">
                <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:var(--color-gold);"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                <span>Meu Perfil</span>
            </a>
            <a href="backup" class="quick-add-btn" style="padding:10px 14px; font-size:13.5px;">
                <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:var(--color-blue);"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>
                <span>Backup dos Dados</span>
            </a>
            <a href="terms" class="quick-add-btn" style="padding:10px 14px; font-size:13.5px;" target="_blank">
                <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:var(--color-gold);"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                <span>Termos & Privacidade</span>
            </a>
            <a href="logout" class="quick-add-btn" style="padding:10px 14px; font-size:13.5px; color:var(--color-danger);" data-confirm="Deseja realmente sair da sua conta?" data-confirm-title="Sair da Conta?" data-confirm-btn="Sair da Conta" data-confirm-type="logout">
                <svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:currentColor;"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                <span>Sair da Conta</span>
            </a>
        </div>
    </div>

    <!-- Bottom Sheet "Adicionar Lançamento" (Mobile) -->
    <div class="bottom-sheet" id="sheetAdicionar" role="dialog" aria-modal="true" aria-labelledby="tituloSheetAdd">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h3 id="tituloSheetAdd">Novo Lançamento</h3>
            <button type="button" class="sheet-close-btn" data-close="sheetAdicionar" aria-label="Fechar">✕</button>
        </div>

        <div class="quick-add-actions">
            <a href="add_expense?tipo=expense" class="quick-add-btn">
                <div class="quick-add-icon expense">
                    <svg viewBox="0 0 24 24" style="width:22px; height:22px; fill:currentColor;"><path d="M19 13H5v-2h14v2z"/></svg>
                </div>
                <div>
                    <div>Nova Despesa</div>
                    <div style="font-size:12px; font-weight:400; color:var(--color-text-secondary);">Registrar saída de dinheiro ou cartão</div>
                </div>
            </a>
            <a href="add_expense?tipo=income" class="quick-add-btn">
                <div class="quick-add-icon income">
                    <svg viewBox="0 0 24 24" style="width:22px; height:22px; fill:currentColor;"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                </div>
                <div>
                    <div>Nova Receita</div>
                    <div style="font-size:12px; font-weight:400; color:var(--color-text-secondary);">Salário, rendimentos ou entradas</div>
                </div>
            </a>
            <a href="transfer" class="quick-add-btn">
                <div class="quick-add-icon transfer">
                    <svg viewBox="0 0 24 24" style="width:22px; height:22px; fill:currentColor;"><path d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z"/></svg>
                </div>
                <div>
                    <div>Transferência</div>
                    <div style="font-size:12px; font-weight:400; color:var(--color-text-secondary);">Movimentar saldo entre contas</div>
                </div>
            </a>
            <a href="import" class="quick-add-btn">
                <div class="quick-add-icon import">
                    <svg viewBox="0 0 24 24" style="width:22px; height:22px; fill:currentColor;"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
                </div>
                <div>
                    <div>Importar Extrato</div>
                    <div style="font-size:12px; font-weight:400; color:var(--color-text-secondary);">Subir arquivo OFX ou CSV do banco</div>
                </div>
            </a>
        </div>
    </div>
</div><!-- /.app-layout -->

<?php
// Verificação de Obrigatoriedade de Aceite dos Termos de Uso e LGPD para Usuários Autenticados
$mostrarModalTermos = false;
if (!empty($_SESSION['user_id']) && empty($_SESSION['terms_accepted_at'])) {
    global $pdo;
    if (isset($pdo) && class_exists('Security')) {
        if (Security::hasAcceptedTerms($pdo, (int)$_SESSION['user_id'])) {
            $_SESSION['terms_accepted_at'] = true;
        } else {
            $mostrarModalTermos = true;
        }
    }
}
?>

<?php if (!empty($mostrarModalTermos)): ?>
<div class="droma-terms-modal-overlay" id="termsModalOverlay" role="dialog" aria-modal="true" aria-labelledby="termsModalTitle">
    <div class="droma-terms-modal">
        <div class="terms-modal-header">
            <div class="brand-icon" style="width:40px; height:40px; margin:0 auto 14px auto; display:flex;">D</div>
            <h2 id="termsModalTitle">Atualização dos Termos de Uso & LGPD</h2>
            <p>Para continuar utilizando o Droma com transparência e segurança jurídica, confirme que você concorda com nossos termos atualizados.</p>
        </div>
        <div class="terms-modal-body">
            <div class="terms-summary-box">
                <h4>🛡️ Seus Dados e Direitos Protegidos:</h4>
                <ul>
                    <li><strong>Isenção Financeira:</strong> O Droma é um software de organização pessoal e não presta consultoria formal de investimentos regulada pela CVM/Bacen.</li>
                    <li><strong>Privacidade & LGPD:</strong> Seus dados não são vendidos para anunciantes. As análises inteligentes trafegam apenas resumos criptografados sem fins comerciais.</li>
                    <li><strong>Cookies Técnicos:</strong> Utilizamos apenas cookies estritamente essenciais de sessão e segurança.</li>
                </ul>
            </div>
            <div class="terms-link-read">
                Leia o documento completo na íntegra: <a href="terms" target="_blank" rel="noopener">Termos de Uso e Política de Privacidade ↗</a>
            </div>
        </div>
        <div class="terms-modal-footer">
            <form action="accept_terms" method="POST">
                <?= Security::csrfField() ?>
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? './') ?>">
                <button type="submit" class="btn-confirm-accept">
                    ✓ Li e Concordo com os Termos e Privacidade
                </button>
            </form>
        </div>
    </div>
</div>
<style>
.droma-terms-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(10, 14, 23, 0.92);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    z-index: 9999999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
}

.droma-terms-modal {
    width: min(520px, 100%);
    background: var(--color-surface, #121826);
    border: 1px solid var(--color-border, #1F2A3F);
    border-radius: var(--radius-lg, 16px);
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7);
    padding: 32px 28px;
    text-align: center;
    animation: termsFadeIn 0.3s ease;
}

@keyframes termsFadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.terms-modal-header h2 {
    font-size: 20px;
    font-weight: 700;
    color: var(--color-text, #F8FAFC);
    margin-bottom: 8px;
    letter-spacing: -0.3px;
}

.terms-modal-header p {
    font-size: 13px;
    color: var(--color-text-secondary, #94A3B8);
    line-height: 1.5;
    margin-bottom: 18px;
}

.terms-summary-box {
    background: rgba(212, 175, 108, 0.08);
    border: 1px solid rgba(212, 175, 108, 0.25);
    border-radius: var(--radius-md, 10px);
    padding: 16px;
    text-align: left;
    margin-bottom: 16px;
}

.terms-summary-box h4 {
    font-size: 13.5px;
    color: var(--color-gold, #D4AF6C);
    margin-bottom: 10px;
    font-weight: 700;
}

.terms-summary-box ul {
    margin: 0 0 0 16px;
    padding: 0;
    font-size: 12.5px;
    line-height: 1.5;
    color: #CBD5E1;
}

.terms-summary-box li {
    margin-bottom: 6px;
}

.terms-link-read {
    font-size: 12.5px;
    color: var(--color-text-secondary, #94A3B8);
    margin-bottom: 22px;
}

.terms-link-read a {
    color: var(--color-gold, #D4AF6C);
    text-decoration: underline;
    font-weight: 600;
}

.terms-modal-footer .btn-confirm-accept {
    width: 100%;
    height: 48px;
    background: var(--gold-gradient, linear-gradient(135deg, #F7E5B8 0%, #D4AF6C 50%, #A17632 100%));
    color: var(--color-on-gold, #0A0E17);
    border: 1px solid rgba(249, 228, 167, 0.4);
    border-radius: var(--radius-md, 10px);
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(212, 175, 108, 0.22);
    transition: all 0.2s ease;
}

.terms-modal-footer .btn-confirm-accept:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
}
</style>
<?php endif; ?>

<script src="js/offline-manager.js?v=<?= file_exists(__DIR__ . '/../js/offline-manager.js') ? filemtime(__DIR__ . '/../js/offline-manager.js') : time() ?>"></script>
<script src="js/money-mask.js?v=<?= file_exists(__DIR__ . '/../js/money-mask.js') ? filemtime(__DIR__ . '/../js/money-mask.js') : time() ?>"></script>
<script src="js/app.js?v=<?= filemtime(__DIR__ . '/../js/app.js') ?? time() ?>"></script>

<?php
$dromaUndoTx = $_SESSION['droma_undo_tx'] ?? null;
unset($_SESSION['droma_undo_tx']);
if (is_array($dromaUndoTx) && !empty($dromaUndoTx['id']) && (time() - (int)($dromaUndoTx['ts'] ?? 0)) <= 15):
?>
<div id="dromaUndoToast" role="status" aria-live="polite" style="position:fixed; bottom:84px; left:50%; transform:translateX(-50%); z-index:9999; background:var(--color-surface, #141414); color:var(--color-text, #F5F5F5); border:1px solid rgba(212,175,108,0.45); border-radius:10px; padding:10px 16px; display:flex; align-items:center; gap:14px; box-shadow:0 8px 28px rgba(0,0,0,0.45); font-size:13px;">
    <span>Lançamento excluído</span>
    <button type="button" id="btnDesfazerExclusao" style="background:none; border:none; color:var(--color-gold, #D4AF6C); font-weight:700; cursor:pointer; text-decoration:underline; padding:2px 4px;">Desfazer</button>
</div>
<script>
(function() {
    const toast = document.getElementById('dromaUndoToast');
    const btn = document.getElementById('btnDesfazerExclusao');
    if (!toast || !btn) return;
    const timer = setTimeout(() => toast.remove(), 8000);
    btn.addEventListener('click', async () => {
        clearTimeout(timer);
        btn.disabled = true;
        btn.textContent = 'Restaurando...';
        try {
            const fd = new FormData();
            fd.append('id', '<?= (int)$dromaUndoTx['id'] ?>');
            fd.append('csrf_token', <?= json_encode(Security::csrfToken()) ?>);
            const r = await fetch('restore_expense', { method: 'POST', body: fd });
            if (r.ok) {
                window.location.reload();
                return;
            }
        } catch (e) {}
        toast.remove();
    });
})();
</script>
<?php endif; ?>

<?php
$dromaFlash = class_exists('Security') ? Security::consumeFlash() : [];
if (!empty($dromaFlash['message'])):
    $flashBorder = (($dromaFlash['type'] ?? 'sucesso') === 'erro') ? '#ef4444' : 'var(--color-emerald, #10B981)';
?>
<div role="status" aria-live="polite" id="dromaFlashToast" style="position:fixed; top:20px; right:20px; z-index:9999; background:var(--color-surface, #141414); color:var(--color-text, #F5F5F5); border:1px solid <?= $flashBorder ?>; border-radius:10px; padding:10px 16px; font-size:13px; box-shadow:0 8px 28px rgba(0,0,0,0.45);">
    <?= htmlspecialchars((string)$dromaFlash['message']) ?>
</div>
<script>setTimeout(() => document.getElementById('dromaFlashToast')?.remove(), 4500);</script>
<?php endif; ?>

<script src="js/push-notifications.js" defer></script>
<script>
document.addEventListener('keydown', (e) => {
    const tag = (e.target && e.target.tagName) ? e.target.tagName.toUpperCase() : '';
    const inInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || e.target?.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        const busca = document.querySelector('input[name="busca"]');
        if (busca) { busca.focus(); busca.select(); } else { window.location.href = 'statement'; }
        return;
    }
    if (inInput) return;
    if (e.key === '/') {
        e.preventDefault();
        const busca = document.querySelector('input[name="busca"]');
        if (busca) { busca.focus(); } else { window.location.href = 'statement'; }
    } else if (e.key.toLowerCase() === 'n' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        window.location.href = 'add_expense';
    }
});
</script>



