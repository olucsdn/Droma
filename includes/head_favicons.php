<?php
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$isAuthFixedDark = !empty($dromaFixedDark) || in_array($currentPage, ['login.php', 'register.php', 'recover.php', 'reset_password.php', 'verify_email.php']);

if ($isAuthFixedDark) {
    $dromaTheme = 'dark';
} else {
    $dromaTheme = $_COOKIE['droma_theme'] ?? 'dark';
    if ($dromaTheme !== 'light' && $dromaTheme !== 'dark') {
        $dromaTheme = 'dark';
    }
}
$activeFavicon = 'img/favicon-darkmode.png';
?>
    <!-- Script Anti-FOUC (Prevenção de Flash de Cor ao Carregar) -->
    <script>
    (function() {
        try {
            <?php if ($isAuthFixedDark): ?>
            document.documentElement.setAttribute('data-theme', 'dark');
            document.documentElement.setAttribute('data-theme-fixed', 'dark');
            <?php else: ?>
            var saved = localStorage.getItem('droma_theme') || (document.cookie.match(/(?:^|;\s*)droma_theme=([^;]+)/) || [])[1] || 'dark';
            document.documentElement.setAttribute('data-theme', saved);
            <?php endif; ?>
        } catch (e) {}
    })();
    </script>

    <!-- Favicons Adaptativos & Dinâmicos Droma (Sempre Ícone Escuro) -->
    <link id="droma-favicon" rel="icon" type="image/png" href="<?= $activeFavicon ?>">
    <link id="droma-favicon-ico" rel="icon" type="image/x-icon" href="favicon.ico">
    <link id="droma-apple-touch" rel="apple-touch-icon" sizes="180x180" href="<?= $activeFavicon ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?= ($dromaTheme === 'light') ? '#F8FAFC' : '#070A0E' ?>" id="droma-meta-theme-color">

    <!-- Motor JavaScript de Alternância de Tema & Favicon -->
    <script src="js/theme.js?v=<?= file_exists('js/theme.js') ? filemtime('js/theme.js') : '1.0' ?>"></script>

    <!-- CSS Crítico Anti-FOUC (Fundo, Tipografia e Ocultação Imediata da Barra Desktop em Mobile) -->
    <style>
        html, body {
            background-color: <?= ($dromaTheme === 'light') ? '#F8FAFC' : '#070A0E' ?>;
            color: <?= ($dromaTheme === 'light') ? '#0F172A' : '#F1F5F9' ?>;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        @media (max-width: 768px) {
            .app-sidebar {
                display: none !important;
            }
        }
        svg {
            max-width: 100%;
        }
    </style>
