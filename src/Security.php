<?php
/**
 * Classe de Segurança e Helpers Globais — Projeto Droma
 * Metodologia SDD — Fase 1 (Estabilização e Segurança)
 */
final class Security {

    /**
     * Detecta se a conexão atual é segura via HTTPS (incluindo proxies reversos e nuvem)
     */
    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Garante que a sessão PHP esteja inicializada com configurações seguras e persistentes.
     */
    public static function initSession(): void {
        self::applySecurityHeaders();

        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                ini_set('session.use_only_cookies', '1');
                ini_set('session.use_strict_mode', '1');
                ini_set('session.cookie_httponly', '1');
                ini_set('session.cookie_samesite', 'Lax');

                // Persistência de 30 dias para não deslogar ao fechar navegador ou app
                ini_set('session.cookie_lifetime', (string)(86400 * 30));
                ini_set('session.gc_maxlifetime', (string)(86400 * 30));

                if (self::isHttps()) {
                    ini_set('session.cookie_secure', '1');
                }

                session_start();
            } elseif (PHP_SAPI === 'cli') {
                @session_start();
            }
        }

        // Auto-login por cookie persistente seguro (Remember Me) caso a sessão em memória expire
        if (empty($_SESSION['user_id']) && !empty($_COOKIE['droma_remember'])) {
            self::attemptRememberLogin();
        }
    }

    /**
     * Aplica cabeçalhos HTTP de segurança de defesa em profundidade (CSP, HSTS, X-Frame-Options, etc.)
     */
    public static function applySecurityHeaders(): void {
        if (headers_sent()) {
            return;
        }

        // HSTS (HTTP Strict Transport Security) - 1 ano se HTTPS
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // Content Security Policy defensivo e compatível com o ecossistema do Droma
        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
             . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
             . "font-src 'self' https://fonts.gstatic.com data:; "
             . "img-src 'self' data: https: blob:; "
             . "connect-src 'self'; "
             . "frame-ancestors 'self'; "
             . "form-action 'self'; "
             . "base-uri 'self';";
        header("Content-Security-Policy: {$csp}");
    }

    /**
     * Auto-migração: garante que a coluna remember_token exista na tabela users
     */
    public static function ensureRememberColumn(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `remember_token` varchar(64) DEFAULT NULL");
        } catch (Throwable $e) {
            // Coluna já existe ou sem permissão de alteração
        }
        $checked = true;
    }

    /**
     * Restaura a autenticação do usuário a partir do token persistente do dispositivo
     */
    private static function attemptRememberLogin(): void {
        $cookie = (string)$_COOKIE['droma_remember'];
        if (!str_contains($cookie, ':')) {
            return;
        }

        [$userId, $token] = explode(':', $cookie, 2);
        if (!is_numeric($userId) || empty($token)) {
            return;
        }

        require_once __DIR__ . '/../config/database.php';
        global $pdo;
        if (!$pdo) {
            return;
        }

        try {
            self::ensureRememberColumn($pdo);
            $tokenHash = hash('sha256', $token);

            $stmt = $pdo->prepare("SELECT id, name, role, email_verified_at, remember_token, api_token FROM users WHERE id = ?");
            $stmt->execute([(int)$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                self::clearRememberCookie();
                return;
            }

            $isValid = false;

            // 1. Validação padrão: compara com o hash SHA-256 do remember_token isolado
            if (!empty($user['remember_token']) && hash_equals($user['remember_token'], $tokenHash)) {
                $isValid = true;
            } 
            // 2. Migração suave legada: se ainda usava api_token, migra automaticamente para remember_token hashed
            elseif (!empty($user['api_token']) && hash_equals($user['api_token'], $token)) {
                $isValid = true;
                self::setRememberCookie((int)$user['id'], null, $pdo);
            }

            if ($isValid) {
                if (!empty($user['email_verified_at'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id']           = (int)$user['id'];
                    $_SESSION['user_name']         = $user['name'];
                    $_SESSION['user_role']         = $user['role'] ?? 'user';
                    $_SESSION['email_verified_at'] = $user['email_verified_at'];
                }
            } else {
                // Token inválido ou alterado: limpa cookie
                self::clearRememberCookie();
            }
        } catch (Throwable $e) {
            error_log("[Remember Login Error] " . $e->getMessage());
        }
    }

    /**
     * Define o cookie de persistência seguro de longo prazo (60 dias) com token hashed isolado
     */
    public static function setRememberCookie(int $userId, ?string $legacyToken = null, ?PDO $pdo = null): void {
        if (!$pdo) {
            require_once __DIR__ . '/../config/database.php';
            global $pdo;
        }

        $rawToken = bin2hex(random_bytes(32));
        $isHttps = self::isHttps();

        if ($pdo) {
            try {
                self::ensureRememberColumn($pdo);
                $tokenHash = hash('sha256', $rawToken);
                $upd = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                $upd->execute([$tokenHash, $userId]);
            } catch (Throwable $e) {
                error_log("[Remember Cookie Error] " . $e->getMessage());
            }
        }

        $value = "{$userId}:{$rawToken}";
        setcookie('droma_remember', $value, [
            'expires'  => time() + (86400 * 60),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['droma_remember'] = $value;
    }

    /**
     * Remove o cookie de persistência ao deslogar
     */
    public static function clearRememberCookie(): void {
        $isHttps = self::isHttps();
        setcookie('droma_remember', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE['droma_remember']);
    }

    /**
     * Garante que o usuário esteja autenticado. Redireciona para o login caso contrário.
     * Retorna o ID do usuário autenticado.
     */
    public static function requireAuth(): int {
        self::initSession();

        if (empty($_SESSION['user_id'])) {
            header('Location: login');
            exit;
        }

        // Validação estrita de e-mail verificado
        if (empty($_SESSION['email_verified_at'])) {
            require_once __DIR__ . '/../config/database.php';
            global $pdo;
            if ($pdo) {
                $stmt = $pdo->prepare("SELECT email_verified_at FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $verifiedAt = $stmt->fetchColumn();

                if (empty($verifiedAt)) {
                    $_SESSION = [];
                    if (ini_get("session.use_cookies")) {
                        $params = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000,
                            $params["path"], $params["domain"],
                            $params["secure"], $params["httponly"]
                        );
                    }
                    session_destroy();
                    header('Location: login?erro=' . urlencode("A confirmação de e-mail é obrigatória para acessar o Droma. Por favor, confirme seu e-mail."));
                    exit;
                }
                $_SESSION['email_verified_at'] = $verifiedAt;
            }
        }

        return (int) $_SESSION['user_id'];
    }

    /**
     * Retorna ou gera o token CSRF armazenado na sessão.
     */
    public static function csrfToken(): string {
        self::initSession();

        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Gera o elemento HTML input oculto contendo o token CSRF.
     */
    public static function csrfField(): string {
        $token = self::csrfToken();
        return '<input type="hidden" name="csrf_token" value="' . self::escape($token) . '">';
    }

    /**
     * Valida se o token CSRF submetido confere com o token ativo da sessão.
     */
    public static function validateCsrf(?string $submittedToken): bool {
        self::initSession();

        if (empty($submittedToken) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], (string) $submittedToken);
    }

    /**
     * Valida se uma determinada conta pertence ao usuário autenticado.
     */
    public static function validateAccountOwnership(PDO $pdo, int $accountId, int $userId): bool {
        if ($accountId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM accounts WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$accountId, $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Erro ao validar propriedade da conta: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida se uma determinada categoria pertence ao usuário autenticado e é compatível com o tipo.
     */
    public static function validateCategoryOwnership(PDO $pdo, int $categoryId, int $userId, ?string $expectedType = null): bool {
        if ($categoryId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            if ($expectedType !== null && in_array($expectedType, ['income', 'expense'], true)) {
                $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? AND type = ? LIMIT 1');
                $stmt->execute([$categoryId, $userId, $expectedType]);
            } else {
                $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$categoryId, $userId]);
            }
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Erro ao validar propriedade da categoria: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida se um determinado cartão de crédito pertence ao usuário autenticado.
     */
    public static function validateCreditCardOwnership(PDO $pdo, int $cardId, int $userId): bool {
        if ($cardId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM credit_cards WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$cardId, $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Erro ao validar propriedade do cartão de crédito: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida se uma conta bancária NÃO possui nenhum cartão de crédito vinculado (regra: 1 cartão por conta).
     */
    public static function validateAccountHasNoCard(PDO $pdo, int $accountId, ?int $ignoreCardId = null): bool {
        if ($accountId <= 0) {
            return false;
        }

        try {
            if ($ignoreCardId !== null && $ignoreCardId > 0) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM credit_cards WHERE account_id = ? AND id != ?');
                $stmt->execute([$accountId, $ignoreCardId]);
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM credit_cards WHERE account_id = ?');
                $stmt->execute([$accountId]);
            }
            return ((int)$stmt->fetchColumn() === 0);
        } catch (PDOException $e) {
            error_log('Erro ao verificar cartão na conta: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Valida se um determinado investimento pertence ao usuário autenticado.
     */
    public static function validateInvestmentOwnership(PDO $pdo, int $investmentId, int $userId): bool {
        if ($investmentId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM investments WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$investmentId, $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Erro ao validar propriedade do investimento: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sanitiza saídas de texto para prevenir XSS em contextos HTML.
     */
    public static function escape(?string $value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitiza células para exportação CSV, prevenindo Formula / CSV Injection (DDE)
     */
    public static function sanitizeCsvCell(mixed $value): string {
        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        // Se o primeiro caractere for interpretável como fórmula por planilhas (Excel, Calc, Sheets)
        $firstChar = $str[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }

        return $str;
    }
}


