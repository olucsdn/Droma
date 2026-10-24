<?php
$dromaTheme = $_COOKIE['droma_theme'] ?? 'dark';
if ($dromaTheme !== 'light' && $dromaTheme !== 'dark') {
    $dromaTheme = 'dark';
}
$activeFavicon = ($dromaTheme === 'light') ? 'img/favicon-darkmode.png' : 'img/favicon-default.png';
?>
    <!-- Script Anti-FOUC (Prevenção de Flash de Cor ao Carregar) -->
    <script>
    (function() {
        try {
            var saved = localStorage.getItem('droma_theme') || (document.cookie.match(/(?:^|;\s*)droma_theme=([^;]+)/) || [])[1] || 'dark';
            document.documentElement.setAttribute('data-theme', saved);
        } catch (e) {}
    })();
    </script>

    <!-- Favicons Adaptativos & Dinâmicos Droma -->
    <link id="droma-favicon" rel="icon" type="image/png" href="<?= $activeFavicon ?>">
    <link id="droma-favicon-ico" rel="icon" type="image/x-icon" href="favicon.ico">
    <link id="droma-apple-touch" rel="apple-touch-icon" sizes="180x180" href="<?= $activeFavicon ?>">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="<?= ($dromaTheme === 'light') ? '#F8FAFC' : '#070A0E' ?>" id="droma-meta-theme-color">

    <!-- Motor JavaScript de Alternância de Tema & Favicon -->
    <script src="js/theme.js?v=<?= file_exists('js/theme.js') ? filemtime('js/theme.js') : '1.0' ?>"></script>
