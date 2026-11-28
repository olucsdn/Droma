/**
 * Gerenciador de Temas (Claro / Escuro) & Favicon Dinâmico — Droma Financy
 */
(function() {
    'use strict';

    // Se a página possuir modo escuro fixo (ex: login, cadastro, recuperação), trava em escuro
    if (document.documentElement.getAttribute('data-theme-fixed') === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
        var staticFav = document.getElementById('droma-favicon') || document.querySelector("link[rel*='icon']");
        if (staticFav) staticFav.href = 'img/favicon-darkmode.png';
        var staticApple = document.getElementById('droma-apple-touch') || document.querySelector("link[rel='apple-touch-icon']");
        if (staticApple) staticApple.href = 'img/favicon-darkmode.png';
        var staticMeta = document.getElementById('droma-meta-theme-color') || document.querySelector("meta[name='theme-color']");
        if (staticMeta) staticMeta.content = '#070A0E';
        window.applyDromaTheme = function() {};
        window.toggleDromaTheme = function() {};
        return;
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : null;
    }

    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax';
    }

    function getInitialTheme() {
        try {
            var local = localStorage.getItem('droma_theme');
            if (local === 'light' || local === 'dark') return local;
            var cookie = getCookie('droma_theme');
            if (cookie === 'light' || cookie === 'dark') return cookie;
        } catch (e) {}
        return 'dark'; // Padrão de luxo do Droma
    }

    window.applyDromaTheme = function(theme, persist) {
        if (theme !== 'light' && theme !== 'dark') theme = 'dark';
        if (typeof persist === 'undefined') persist = true;

        var root = document.documentElement;
        root.setAttribute('data-theme', theme);

        if (persist) {
            try {
                localStorage.setItem('droma_theme', theme);
            } catch (e) {}
            setCookie('droma_theme', theme, 365);
        }

        // 1. Atualiza Favicon e Apple Touch Icon (Sempre ícone escuro)
        var iconPath = 'img/favicon-darkmode.png';

        var fav = document.getElementById('droma-favicon');
        if (fav) {
            fav.href = iconPath;
        } else {
            fav = document.querySelector("link[rel*='icon']");
            if (fav) fav.href = iconPath;
        }

        var apple = document.getElementById('droma-apple-touch');
        if (apple) {
            apple.href = iconPath;
        } else {
            apple = document.querySelector("link[rel='apple-touch-icon']");
            if (apple) apple.href = iconPath;
        }

        // 2. Atualiza a barra de status / tema do navegador (mobile & PWA)
        var metaTheme = document.getElementById('droma-meta-theme-color') || document.querySelector("meta[name='theme-color']");
        if (metaTheme) {
            metaTheme.content = (theme === 'light') ? '#F8FAFC' : '#070A0E';
        }

        // 3. Atualiza acessibilidade e títulos dos botões na tela
        var toggleButtons = document.querySelectorAll('.sidebar-theme-btn, #btnThemeToggleMobile, .btn-theme-toggle');
        toggleButtons.forEach(function(btn) {
            btn.setAttribute('data-current-theme', theme);
            var title = (theme === 'light') ? 'Mudar para Modo Escuro' : 'Mudar para Modo Claro';
            btn.setAttribute('title', title);
            btn.setAttribute('aria-label', title);
        });

        var sidebarText = document.querySelector('.sidebar-theme-text');
        if (sidebarText) {
            sidebarText.textContent = (theme === 'light') ? 'Modo Escuro' : 'Modo Claro';
        }
    };

    window.toggleDromaTheme = function() {
        var current = document.documentElement.getAttribute('data-theme') || 'dark';
        var next = (current === 'light') ? 'dark' : 'light';
        window.applyDromaTheme(next, true);
    };

    // Aplicação imediata antes de renderizar para prevenir flash de cor
    var currentTheme = getInitialTheme();
    window.applyDromaTheme(currentTheme, false);

    // Reafirma estado após DOM pronto
    document.addEventListener('DOMContentLoaded', function() {
        var active = document.documentElement.getAttribute('data-theme') || currentTheme;
        window.applyDromaTheme(active, false);
    });
})();
