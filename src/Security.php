<?php
@ini_set('expose_php', 'off');

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
                // Parâmetros de cookie de sessão sem mutação de ini global do worker
                session_set_cookie_params([
                    'lifetime' => 86400 * 30,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => self::isHttps(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
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

        @header_remove('X-Powered-By');

        $headersList = headers_list();
        $hasHeader = static function (string $name) use ($headersList): bool {
            $prefix = strtolower($name) . ':';
            foreach ($headersList as $h) {
                if (str_starts_with(strtolower(trim($h)), $prefix)) {
                    return true;
                }
            }
            return false;
        };

        // Cache-Control estrito para páginas dinâmicas (previne vazamento de dados em máquinas compartilhadas pós-logout)
        if (!$hasHeader('Cache-Control')) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        // Se o servidor for Apache ou compatível (onde o .htaccess centraliza os cabeçalhos de segurança
        // para arquivos estáticos, respostas dinâmicas e páginas de erro), evita redeclaração via PHP
        // para impedir cabeçalhos duplicados concatenados por vírgula no navegador (ex: nosniff,nosniff).
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
        $isApache = (stripos($serverSoftware, 'Apache') !== false || stripos($serverSoftware, 'LiteSpeed') !== false);
        if ($isApache) {
            return;
        }

        // HSTS (HTTP Strict Transport Security) - 1 ano se HTTPS
        if (!$hasHeader('Strict-Transport-Security') && self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        if (!$hasHeader('X-Frame-Options')) {
            header('X-Frame-Options: SAMEORIGIN');
        }
        if (!$hasHeader('X-Content-Type-Options')) {
            header('X-Content-Type-Options: nosniff');
        }
        if (!$hasHeader('Referrer-Policy')) {
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
        if (!$hasHeader('Permissions-Policy')) {
            header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        }

        // Content Security Policy defensivo e compatível com o ecossistema do Droma
        if (!$hasHeader('Content-Security-Policy')) {
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
     * Auto-migração: garante que a coluna terms_accepted_at exista na tabela users
     */
    public static function ensureTermsColumn(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `terms_accepted_at` timestamp NULL DEFAULT NULL");
        } catch (Throwable $e) {
            // Coluna já existe ou sem permissão de alteração
        }
        $checked = true;
    }

    /**
     * Auto-migração resiliente: padroniza e-mails já gravados para caixa baixa (minúsculas)
     * e garante a integridade estrita do índice UNIQUE na tabela users (impossibilitando contas duplicadas).
     */
    public static function ensureNormalizedEmails(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            // 1. Padroniza e-mails existentes (lowercase e trim)
            $pdo->exec("UPDATE users SET email = LOWER(TRIM(email)) WHERE email IS NOT NULL AND email != LOWER(TRIM(email))");

            // 2. Se existirem contas duplicadas legadas com o mesmo e-mail, preserva a principal e desambigua as duplicatas
            $dupsStmt = $pdo->query("SELECT LOWER(TRIM(email)) AS mail, COUNT(*) AS cnt FROM users WHERE email IS NOT NULL AND email != '' GROUP BY LOWER(TRIM(email)) HAVING cnt > 1");
            if ($dupsStmt) {
                $dups = $dupsStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($dups as $dup) {
                    $m = $dup['mail'];
                    // Ordena: contas verificadas primeiro, depois pela mais antiga (menor id)
                    $stmtIds = $pdo->prepare("SELECT id FROM users WHERE LOWER(TRIM(email)) = ? ORDER BY (email_verified_at IS NOT NULL) DESC, id ASC");
                    $stmtIds->execute([$m]);
                    $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);

                    if (count($ids) > 1) {
                        array_shift($ids); // Mantém a conta principal ativa intacta
                        foreach ($ids as $dupId) {
                            $newMail = "dup_" . $dupId . "_" . $m;
                            if (mb_strlen($newMail) > 150) {
                                $newMail = substr($newMail, 0, 150);
                            }
                            $stmtFix = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
                            $stmtFix->execute([$newMail, $dupId]);
                        }
                    }
                }
            }

            // 3. Garante que o índice UNIQUE existe no banco de dados para que o MySQL/TiDB impeça duplicações no nível físico
            $indexExists = false;
            $idxStmt = $pdo->query("SHOW INDEX FROM `users` WHERE Column_name = 'email'");
            if ($idxStmt) {
                while ($idxRow = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($idxRow['Non_unique']) && (int)$idxRow['Non_unique'] === 0) {
                        $indexExists = true;
                        break;
                    }
                }
            }

            if (!$indexExists) {
                $pdo->exec("ALTER TABLE `users` ADD UNIQUE KEY `uq_users_email` (`email`)");
            }
        } catch (Throwable $e) {
            error_log("[Security::ensureNormalizedEmails] Aviso de migração: " . $e->getMessage());
        }

        $checked = true;
    }

    /**
     * Auto-migração resiliente: garante que a coluna transfer_id exista na tabela transactions
     */
    public static function ensureTransferColumn(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `transfer_id` varchar(36) DEFAULT NULL AFTER `credit_card_id`");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD INDEX `idx_transactions_transfer_id` (`transfer_id`)");
        } catch (Throwable $e) {}
        $checked = true;
    }

    /**
     * Auto-migração resiliente: garante que a coluna goal_id exista na tabela transactions
     */
    public static function ensureGoalColumn(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `goal_id` int unsigned DEFAULT NULL AFTER `investment_id`");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD INDEX `idx_transactions_goal_id` (`goal_id`)");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD CONSTRAINT `fk_transactions_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE SET NULL");
        } catch (Throwable $e) {}
        $checked = true;
    }

    /**
     * Garante a existência da coluna client_id e o índice UNIQUE (user_id, client_id) para idempotência no sync offline
     */
    /**
     * Garante a existência da coluna client_id e o índice UNIQUE (user_id, client_id) para idempotência no sync offline.
     * Retorna true somente se a estrutura estiver íntegra e confirmada; caso contrário, retorna false (Fail-Secure).
     */
    public static function ensureClientSyncColumn(PDO $pdo): bool {
        static $ready = false;
        if ($ready) {
            return true;
        }

        $colOk = false;
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `client_id` VARCHAR(64) DEFAULT NULL AFTER `transfer_id`");
            $colOk = true;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate column')) {
                $colOk = true;
            } else {
                error_log("[Security Migration Error] ensureClientSyncColumn add column: " . $e->getMessage());
            }
        }

        $idxOk = false;
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD UNIQUE KEY `uq_transactions_user_client` (`user_id`, `client_id`)");
            $idxOk = true;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate key') || str_contains($e->getMessage(), 'already exists')) {
                $idxOk = true;
            } else {
                error_log("[Security Migration Error] ensureClientSyncColumn add unique index: " . $e->getMessage());
            }
        }

        // Validação profunda e estrutural dos artefatos:
        // 1. Confirma que a coluna 'client_id' existe na tabela transactions
        // 2. Confirma que o índice 'uq_transactions_user_client' é estritamente UNIQUE (Non_unique = 0) e cobre exatamente (user_id, client_id)
        try {
            $chkCol = $pdo->query("SHOW COLUMNS FROM `transactions` LIKE 'client_id'")->fetch(PDO::FETCH_ASSOC);
            $colValid = ($chkCol && strtolower($chkCol['Field'] ?? '') === 'client_id');

            $chkIdxRows = $pdo->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'uq_transactions_user_client' ORDER BY Seq_in_index ASC")->fetchAll(PDO::FETCH_ASSOC);
            $idxValid = false;

            if (count($chkIdxRows) === 2) {
                $r1 = $chkIdxRows[0];
                $r2 = $chkIdxRows[1];
                if (
                    (int)($r1['Non_unique'] ?? 1) === 0 &&
                    strtolower($r1['Column_name'] ?? '') === 'user_id' &&
                    (int)($r1['Seq_in_index'] ?? 0) === 1 &&
                    (int)($r2['Non_unique'] ?? 1) === 0 &&
                    strtolower($r2['Column_name'] ?? '') === 'client_id' &&
                    (int)($r2['Seq_in_index'] ?? 0) === 2
                ) {
                    $idxValid = true;
                }
            }

            if ($colValid && $idxValid) {
                $ready = true;
                return true;
            }

            error_log("[Security Migration Validation Failed] Coluna client_id: " . ($colValid ? 'OK' : 'AUSENTE') . " | Índice UNIQUE (user_id, client_id): " . ($idxValid ? 'OK' : 'INVALIDO/AUSENTE'));
        } catch (Throwable $chkErr) {
            error_log("[Security Migration Check Error] " . $chkErr->getMessage());
        }

        return false;
    }

    /**
     * Verifica se o usuário autenticado já aceitou os Termos de Uso e Política de Privacidade
     */
    public static function hasAcceptedTerms(PDO $pdo, int $userId): bool {
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT terms_accepted_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $accepted = $stmt->fetchColumn();
            return !empty($accepted);
        } catch (Throwable $e) {
            // Em caso de coluna inexistente no schema local, dispara auto-migração resiliente e tenta nova consulta
            self::ensureTermsColumn($pdo);
            try {
                $stmtRetry = $pdo->prepare("SELECT terms_accepted_at FROM users WHERE id = ?");
                $stmtRetry->execute([$userId]);
                $acceptedRetry = $stmtRetry->fetchColumn();
                return !empty($acceptedRetry);
            } catch (Throwable $eRetry) {
                error_log("[Security Error] hasAcceptedTerms indisponível: " . $eRetry->getMessage());
                return false; // Fail-secure: bloqueia em falhas irrecuperáveis até a integridade do banco ser restaurada
            }
        }
    }

    /**
     * Registra o aceite dos Termos de Uso e Política de Privacidade para o usuário
     */
    public static function recordTermsAcceptance(PDO $pdo, int $userId): bool {
        if ($userId <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("UPDATE users SET terms_accepted_at = NOW() WHERE id = ?");
            return $stmt->execute([$userId]);
        } catch (Throwable $e) {
            self::ensureTermsColumn($pdo);
            try {
                $stmt = $pdo->prepare("UPDATE users SET terms_accepted_at = NOW() WHERE id = ?");
                return $stmt->execute([$userId]);
            } catch (Throwable $e2) {
                error_log("[Terms Acceptance Error] " . $e2->getMessage());
                return false;
            }
        }
    }

    /**
     * Retorna o melhor algoritmo criptográfico de hash de senha disponível (Argon2id ou Bcrypt com custo 12)
     */
    public static function getPasswordAlgo(): string|int {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    /**
     * Gera o hash seguro da senha com Argon2id ou Bcrypt com custo elevado
     */
    public static function hashPassword(string $password): string {
        $algo = self::getPasswordAlgo();
        $options = [];
        if ($algo === PASSWORD_BCRYPT) {
            $options['cost'] = 12;
        }
        return password_hash($password, $algo, $options);
    }

    /**
     * Valida os requisitos modernos de força de senha (OWASP e NIST):
     * - Mínimo de 8 caracteres
     * - Pelo menos 1 letra maiúscula
     * - Pelo menos 1 letra minúscula
     * - Pelo menos 1 número
     * - Bloqueio de senhas comuns/óbvias ou com dados pessoais
     */
    public static function validatePasswordStrength(string $password, string $userName = '', string $userEmail = ''): array {
        if (mb_strlen($password) < 8) {
            return ['valido' => false, 'erro' => 'A senha deve conter no mínimo 8 caracteres.'];
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return ['valido' => false, 'erro' => 'A senha deve conter pelo menos uma letra maiúscula.'];
        }

        if (!preg_match('/[a-z]/', $password)) {
            return ['valido' => false, 'erro' => 'A senha deve conter pelo menos uma letra minúscula.'];
        }

        if (!preg_match('/[0-9]/', $password)) {
            return ['valido' => false, 'erro' => 'A senha deve conter pelo menos um número.'];
        }

        // Bloqueio de senhas sequenciais ou muito óbvias
        $comuns = ['12345678', '123456789', 'password', 'senha123', 'qwerty123', 'droma123', 'droma2026', 'admin123', 'mudar123', 'brasil123'];
        if (in_array(strtolower($password), $comuns, true)) {
            return ['valido' => false, 'erro' => 'Esta senha é muito comum e previsível. Escolha uma senha mais forte.'];
        }

        // Não permite que a senha contenha o primeiro nome do usuário
        if ($userName !== '') {
            $primeiroNome = trim(explode(' ', $userName)[0] ?? '');
            if (mb_strlen($primeiroNome) >= 3 && str_contains(strtolower($password), strtolower($primeiroNome))) {
                return ['valido' => false, 'erro' => 'Por segurança, a senha não pode conter o seu nome.'];
            }
        }

        // Não permite que a senha contenha o prefixo do e-mail
        if ($userEmail !== '') {
            $emailPrefix = trim(explode('@', $userEmail)[0] ?? '');
            if (mb_strlen($emailPrefix) >= 4 && str_contains(strtolower($password), strtolower($emailPrefix))) {
                return ['valido' => false, 'erro' => 'Por segurança, a senha não pode conter partes do seu e-mail.'];
            }
        }

        return ['valido' => true, 'erro' => ''];
    }

    /**
     * Invalida todas as sessões ativas e tokens remember me do usuário ao alterar a senha
     */
    public static function revokeAllSessions(PDO $pdo, int $userId): void {
        if ($userId <= 0) {
            return;
        }
        try {
            self::ensureRememberColumn($pdo);
            $stmt = $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
            $stmt->execute([$userId]);
        } catch (Throwable $e) {
            error_log("[Revoke Sessions Error] " . $e->getMessage());
        }
        self::clearRememberCookie();
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

            $stmt = $pdo->prepare("SELECT id, name, role, email_verified_at, remember_token FROM users WHERE id = ?");
            $stmt->execute([(int)$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                self::clearRememberCookie();
                return;
            }

            $isValid = false;

            // Validação estrita: compara com o hash SHA-256 do remember_token isolado
            if (!empty($user['remember_token']) && hash_equals($user['remember_token'], $tokenHash)) {
                $isValid = true;
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
     * Garante a existência da coluna import_hash e do índice único uq_transactions_import para deduplicação de extratos.
     */
    public static function ensureImportHashColumn(PDO $pdo): bool {
        static $ready = false;
        if ($ready) {
            return true;
        }
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `import_hash` CHAR(64) NULL");
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) {
                error_log("[Security Migration Error] ensureImportHashColumn add column: " . $e->getMessage());
            }
        }
        try {
            $pdo->exec("ALTER TABLE `transactions` ADD UNIQUE KEY `uq_transactions_import` (`user_id`, `account_id`, `import_hash`)");
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key') && !str_contains($e->getMessage(), 'already exists')) {
                error_log("[Security Migration Error] ensureImportHashColumn add index: " . $e->getMessage());
            }
        }
        $chkCol = $pdo->query("SHOW COLUMNS FROM `transactions` LIKE 'import_hash'")->fetch(PDO::FETCH_ASSOC);
        if ($chkCol) {
            $ready = true;
            return true;
        }
        return false;
    }

    /**
     * Garante a existência da coluna api_token_hash e migra tokens em texto puro para SHA-256.
     */
    public static function ensureApiTokenHashColumn(PDO $pdo): bool {
        static $ready = false;
        if ($ready) {
            return true;
        }
        try {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `api_token_hash` CHAR(64) NULL, ADD UNIQUE KEY `uq_users_api_token_hash` (`api_token_hash`)");
            $pdo->exec("UPDATE `users` SET `api_token_hash` = SHA2(`api_token`, 256) WHERE `api_token` IS NOT NULL AND `api_token` <> '' AND `api_token_hash` IS NULL");
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column') && !str_contains($e->getMessage(), 'Duplicate key') && !str_contains($e->getMessage(), 'already exists')) {
                error_log("[Security Migration Error] ensureApiTokenHashColumn: " . $e->getMessage());
            }
        }
        $chkCol = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'api_token_hash'")->fetch(PDO::FETCH_ASSOC);
        if ($chkCol) {
            $ready = true;
            return true;
        }
        return false;
    }

    /**
     * Garante categoria única por (user_id, name, type) usando INSERT ... ON DUPLICATE KEY UPDATE.
     */
    public static function getOrCreateCategory(PDO $pdo, int $userId, string $name, string $type, bool $isSystem = true): int {
        static $uniqueChecked = false;
        static $hasUnique = false;

        if (class_exists('CategoryService')) {
            CategoryService::ensureColumns($pdo);
        }
        $sysFlag = $isSystem ? 1 : 0;

        if (!$uniqueChecked) {
            $uniqueChecked = true;
            try {
                $pdo->exec("ALTER TABLE `categories` ADD UNIQUE KEY `uq_categories_user_name_type` (`user_id`, `name`, `type`)");
                $hasUnique = true;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'Duplicate key name') || str_contains($msg, 'already exists')) {
                    $hasUnique = true;
                } else {
                    error_log("[Security] ensure categories unique index: " . $msg);
                }
            }
        }

        if ($hasUnique) {
            $stmt = $pdo->prepare(
                "INSERT INTO categories (user_id, name, type, visivel, is_system)
                 VALUES (?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), visivel = 1, is_system = GREATEST(is_system, VALUES(is_system))"
            );
            $stmt->execute([$userId, $name, $type, $sysFlag]);
            return (int)$pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND name = ? AND type = ? AND visivel = 1 LIMIT 1");
        $stmt->execute([$userId, $name, $type]);
        $catId = $stmt->fetchColumn();
        if ($catId) {
            if ($sysFlag === 1) {
                $pdo->prepare("UPDATE categories SET is_system = 1 WHERE id = ? AND user_id = ?")->execute([(int)$catId, $userId]);
            }
            return (int)$catId;
        }
        $ins = $pdo->prepare("INSERT INTO categories (user_id, name, type, visivel, is_system) VALUES (?, ?, ?, 1, ?)");
        $ins->execute([$userId, $name, $type, $sysFlag]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Garante que o usuário esteja autenticado. Redireciona para a landing page caso contrário.
     * Retorna o ID do usuário autenticado.
     */
    public static function requireAuth(): int {
        self::initSession();

        if (empty($_SESSION['user_id'])) {
            if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Sessão expirada. Faça login para sincronizar.']);
                exit;
            }
            header('Location: landing');
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
     * Gera os elementos HTML input ocultos contendo o token CSRF e um form_nonce de uso único.
     */
    public static function csrfField(): string {
        $token = self::csrfToken();
        $nonce = bin2hex(random_bytes(16));
        return '<input type="hidden" name="csrf_token" value="' . self::escape($token) . '">'
             . '<input type="hidden" name="form_nonce" value="' . $nonce . '">';
    }

    /**
     * Garante uso único de formulário (true = primeiro uso; false = reenvio do mesmo formulário).
     */
    public static function claimFormNonce(PDO $pdo, int $userId, ?string $nonce): bool {
        if ($userId <= 0 || $nonce === null || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            return true;
        }
        try {
            $pdo->prepare("INSERT INTO form_nonces (user_id, nonce) VALUES (?, ?)")->execute([$userId, $nonce]);
            return true;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            if ($e->getCode() === '42S02') {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS form_nonces (
                        user_id INT UNSIGNED NOT NULL,
                        nonce CHAR(32) NOT NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (user_id, nonce),
                        KEY idx_form_nonces_created (created_at)
                    ) ENGINE=InnoDB"
                );
                try {
                    $pdo->prepare("INSERT INTO form_nonces (user_id, nonce) VALUES (?, ?)")->execute([$userId, $nonce]);
                    return true;
                } catch (PDOException $e2) {
                    if ($e2->getCode() === '23000') {
                        return false;
                    }
                    throw $e2;
                }
            }
            throw $e;
        }
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
     * Valida titularidade estrita de uma meta financeira (Anti-IDOR / Multi-tenant)
     */
    public static function validateGoalOwnership(PDO $pdo, int $goalId, int $userId): bool {
        if ($goalId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT id FROM goals WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$goalId, $userId]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('Erro ao validar propriedade da meta: ' . $e->getMessage());
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
     * Normaliza e sanitiza endereço de e-mail (lowercase UTF-8 e trim).
     */
    public static function normalizeEmail(?string $email): string {
        return mb_strtolower(trim((string)$email), 'UTF-8');
    }

    /**
     * Sanitiza células para exportação CSV, prevenindo Formula / CSV Injection (DDE)
     */
    public static function sanitizeCsvCell(mixed $value): string {
        $str = (string) $value;
        $trimmed = ltrim($str);
        if ($trimmed === '') {
            return '';
        }

        // Se o primeiro caractere visível for interpretável como fórmula por planilhas (Excel, Calc, Sheets)
        $firstChar = $trimmed[0];
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }

        return $str;
    }

    /**
     * Sanitiza strings textuais para persistência segura no banco de dados.
     * Remove tags HTML/scripts, caracteres invisíveis de controle e limita o tamanho da coluna.
     */
    public static function sanitizeText(mixed $val, int $maxLength, string $default = ''): string {
        $str = trim((string)$val);
        // Remove blocos de script e style por completo incluindo seu conteúdo interno
        $str = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $str) ?? $str;
        $str = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $str) ?? $str;
        $str = strip_tags($str);
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $str) ?? '';
        $str = mb_substr(trim($str), 0, $maxLength);
        return $str !== '' ? $str : $default;
    }

    /**
     * Valida e sanitiza valores monetários e numéricos.
     * Garante tipo float finito, limite decimal seguro e controle de sinal.
     */
    public static function sanitizeAmount(mixed $val, float $default = 0.0, bool $allowNegative = false): float {
        if (!is_numeric($val)) {
            return $default;
        }
        $f = (float)$val;
        if (!is_finite($f)) {
            return $default;
        }
        if (!$allowNegative && $f < 0) {
            $f = abs($f);
        }
        if ($f > 999999999.99) $f = 999999999.99;
        if ($f < -999999999.99) $f = -999999999.99;
        return round($f, 2);
    }

    /**
     * Valida e formata datas no padrão estrito YYYY-MM-DD.
     * Retorna fallback caso a data seja inválida no calendário gregoriano.
     */
    public static function sanitizeDate(?string $val, ?string $fallback = null): ?string {
        if (empty($val) || !is_string($val)) {
            return $fallback;
        }
        $val = trim($val);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $val, $m)) {
            return $fallback;
        }
        $y = (int)$m[1];
        $mon = (int)$m[2];
        $d = (int)$m[3];
        if (!checkdate($mon, $d, $y)) {
            return $fallback;
        }
        return sprintf('%04d-%02d-%02d', $y, $mon, $d);
    }

    /**
     * Valida integralmente um arquivo de backup JSON enviado por upload.
     * Protege contra arquivos compactados (zip/tar/gz), binários, injeção de código,
     * scripts SQL, caracteres nulos, XSS, estouro de memória (OOM) e JSON malformado.
     *
     * @param array $file Matriz $_FILES correspondente ao upload
     * @param int $maxBytes Tamanho máximo permitido (padrão 4MB)
     * @return array{valid: bool, error: string, payload: ?array}
     */
    public static function validateBackupUpload(array $file, int $maxBytes = 4194304): array {
        if (empty($file) || !isset($file['error'])) {
            return ['valid' => false, 'error' => 'Nenhum arquivo de backup foi enviado.', 'payload' => null];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msg = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite máximo permitido de 4MB.',
                UPLOAD_ERR_PARTIAL => 'O envio do arquivo foi interrompido. Tente novamente.',
                UPLOAD_ERR_NO_FILE => 'Por favor, selecione um arquivo JSON de backup para restaurar.',
                default => 'Ocorreu uma falha no upload do arquivo. Código: ' . $file['error'],
            };
            return ['valid' => false, 'error' => $msg, 'payload' => null];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        $isSafeFile = (PHP_SAPI === 'cli') ? file_exists($tmpName) : is_uploaded_file($tmpName);
        if ($tmpName === '' || !$isSafeFile) {
            return ['valid' => false, 'error' => 'Origem do arquivo de upload inválida ou insegura.', 'payload' => null];
        }

        $origName = mb_strtolower((string)($file['name'] ?? ''));
        $ext = pathinfo($origName, PATHINFO_EXTENSION);

        // Bloqueia extensões de compactação ou scripts perigosos
        $compressedExts = ['zip', 'rar', 'tar', 'gz', 'gzip', 'bz2', '7z', 'xz', 'zst', 'tgz', 'tbz2'];
        if (in_array($ext, $compressedExts, true)) {
            return [
                'valid' => false,
                'error' => 'Arquivos compactados (.zip, .rar, .gz, etc.) não são aceitos diretamente. Por favor, descompacte o arquivo e envie o arquivo .json do backup.',
                'payload' => null
            ];
        }

        $forbiddenExts = ['sql', 'php', 'phtml', 'sh', 'exe', 'bat', 'cmd', 'bin', 'dll', 'so'];
        if (in_array($ext, $forbiddenExts, true)) {
            return [
                'valid' => false,
                'error' => 'Tipo de arquivo não permitido. Apenas arquivos no formato .json gerados pelo Droma são aceitos.',
                'payload' => null
            ];
        }

        if ($ext !== 'json') {
            return [
                'valid' => false,
                'error' => 'O arquivo de backup deve ter estritamente a extensão .json.',
                'payload' => null
            ];
        }

        $realSize = (int)@filesize($tmpName);
        if ($realSize <= 20) {
            return ['valid' => false, 'error' => 'O arquivo enviado está vazio ou incompleto.', 'payload' => null];
        }
        if ($realSize > $maxBytes) {
            return ['valid' => false, 'error' => 'O arquivo de backup excede o limite máximo de 4MB.', 'payload' => null];
        }

        // Inspeção de Magic Bytes (Assinaturas Binárias) nos primeiros 16 bytes
        $fh = @fopen($tmpName, 'rb');
        if (!$fh) {
            return ['valid' => false, 'error' => 'Falha ao acessar o arquivo temporário enviado.', 'payload' => null];
        }
        $headerBytes = (string)fread($fh, 16);
        fclose($fh);

        // Assinaturas de arquivos compactados e binários conhecidos
        if (str_starts_with($headerBytes, "PK\x03\x04") || str_starts_with($headerBytes, "PK\x05\x06") || str_starts_with($headerBytes, "PK\x07\x08")) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado é um arquivo ZIP compactado. Envie apenas o arquivo .json descompactado de backup.',
                'payload' => null
            ];
        }
        if (str_starts_with($headerBytes, "\x1F\x8B")) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado é um pacote GZIP compactado. Envie apenas o arquivo .json descompactado de backup.',
                'payload' => null
            ];
        }
        if (str_starts_with($headerBytes, "BZ")) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado é um pacote BZIP2 compactado. Envie apenas o arquivo .json descompactado.',
                'payload' => null
            ];
        }
        if (str_starts_with($headerBytes, "7z\xBC\xAF\x27\x1C")) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado é um pacote 7-Zip compactado. Envie apenas o arquivo .json descompactado.',
                'payload' => null
            ];
        }
        if (str_starts_with($headerBytes, "Rar!\x1A\x07") || str_starts_with($headerBytes, "Rar!")) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado é um arquivo RAR compactado. Envie apenas o arquivo .json descompactado.',
                'payload' => null
            ];
        }
        if (str_starts_with($headerBytes, "MZ") || str_starts_with($headerBytes, "\x7FELF")) {
            return [
                'valid' => false,
                'error' => 'Arquivos executáveis binários são proibidos por motivos de segurança.',
                'payload' => null
            ];
        }
        if (str_starts_with($headerBytes, "%PDF")) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado é um documento PDF e não um backup JSON.',
                'payload' => null
            ];
        }

        // Inspeção de MIME Type via finfo se disponível
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = (string)finfo_file($finfo, $tmpName);
                if (PHP_VERSION_ID < 80500) {
                    @finfo_close($finfo);
                }

                // Bloqueia explicitamente MIMEs de compactação e binários
                $blockedMimes = [
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/x-rar',
                    'application/x-rar-compressed',
                    'application/x-7z-compressed',
                    'application/gzip',
                    'application/x-gzip',
                    'application/x-tar',
                    'application/x-bzip2',
                    'application/x-executable',
                    'application/x-dosexec'
                ];
                foreach ($blockedMimes as $bm) {
                    if (str_contains(strtolower($mime), $bm)) {
                        return [
                            'valid' => false,
                            'error' => 'Tipo de arquivo compactado ou binário detectado (' . htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') . '). Envie apenas o arquivo .json descompactado.',
                            'payload' => null
                        ];
                    }
                }
            }
        }

        // Leitura controlada do arquivo completo
        $content = @file_get_contents($tmpName, false, null, 0, $maxBytes + 1);
        if ($content === false || strlen($content) > $maxBytes) {
            return ['valid' => false, 'error' => 'Falha ao ler o conteúdo do arquivo ou tamanho excedido.', 'payload' => null];
        }

        // Bloqueia arquivos contendo caracteres nulos (\0)
        if (str_contains($content, "\0")) {
            return [
                'valid' => false,
                'error' => 'Arquivo contém caracteres binários nulos inválidos. Envie um arquivo JSON em texto UTF-8 puro.',
                'payload' => null
            ];
        }

        // Validação de encoding UTF-8 estrito
        if (!mb_check_encoding($content, 'UTF-8')) {
            return [
                'valid' => false,
                'error' => 'O arquivo enviado não possui codificação de caracteres UTF-8 válida.',
                'payload' => null
            ];
        }

        // Detecção de injeção direta de scripts SQL ou scripts executáveis
        $trimmedPrefix = ltrim($content);
        $upperPrefix = strtoupper(substr($trimmedPrefix, 0, 100));
        if (
            str_starts_with($trimmedPrefix, '--') ||
            str_starts_with($trimmedPrefix, '/*') ||
            str_starts_with($upperPrefix, 'CREATE TABLE') ||
            str_starts_with($upperPrefix, 'INSERT INTO') ||
            str_starts_with($upperPrefix, 'DROP TABLE') ||
            str_starts_with($upperPrefix, 'SELECT ') ||
            str_starts_with($trimmedPrefix, '<?php') ||
            str_starts_with($trimmedPrefix, '<html') ||
            str_starts_with($trimmedPrefix, '<script')
        ) {
            return [
                'valid' => false,
                'error' => 'O arquivo contém comandos SQL ou código executável direto. Por segurança, apenas arquivos de backup JSON do Droma são aceitos.',
                'payload' => null
            ];
        }

        // Decodificação JSON com limite rigoroso de profundidade (máximo 8 níveis para proteção anti-DoS/stack overflow)
        try {
            $payload = json_decode($content, true, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $je) {
            return [
                'valid' => false,
                'error' => 'O arquivo não é um JSON válido: ' . $je->getMessage(),
                'payload' => null
            ];
        }

        if (!is_array($payload)) {
            return ['valid' => false, 'error' => 'Estrutura JSON inválida (raiz deve ser um objeto).', 'payload' => null];
        }

        // Validação do Schema Droma
        $appName = (string)($payload['app'] ?? '');
        if (!in_array($appName, ['DromaFinancy', 'WertFinancy'], true)) {
            return [
                'valid' => false,
                'error' => 'O arquivo selecionado não foi gerado pelo Droma ou possui assinatura incompatível.',
                'payload' => null
            ];
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            return [
                'valid' => false,
                'error' => 'O arquivo de backup não contém a seção de dados ("data") necessária para a restauração.',
                'payload' => null
            ];
        }

        $data = $payload['data'];

        // Limites máximos por entidade para mitigar Denial of Service e esgotamento de memória
        $limits = [
            'accounts'               => 50,
            'categories'             => 200,
            'credit_cards'           => 50,
            'goals'                  => 100,
            'investments'            => 200,
            'recurring_transactions' => 100,
            'transactions'           => 50000,
            'installments'           => 50000,
        ];

        foreach ($limits as $entity => $maxCount) {
            if (isset($data[$entity])) {
                if (!is_array($data[$entity])) {
                    return [
                        'valid' => false,
                        'error' => "A seção '{$entity}' do arquivo de backup possui estrutura inválida.",
                        'payload' => null
                    ];
                }
                if (count($data[$entity]) > $maxCount) {
                    return [
                        'valid' => false,
                        'error' => "A seção '{$entity}' excede a quantidade máxima permitida ({$maxCount} registros).",
                        'payload' => null
                    ];
                }
            }
        }

        return ['valid' => true, 'error' => '', 'payload' => $payload];
    }

    /**
     * Converte valores monetários em formatos BR/US ("1.234,56", "1234,56", "1234.56", "R$ 10") para float.
     */
    public static function parseMoney(string $raw): ?float {
        $clean = trim(str_replace(['R$', 'r$', ' ', "\u{00A0}"], '', $raw));
        if ($clean === '') {
            return null;
        }
        $neg = false;
        if (str_starts_with($clean, '-')) {
            $neg = true;
            $clean = substr($clean, 1);
        } elseif (str_starts_with($clean, '+')) {
            $clean = substr($clean, 1);
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            if (strrpos($clean, ',') > strrpos($clean, '.')) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);
        }

        if (!is_numeric($clean)) {
            return null;
        }
        $val = round((float)$clean, 2);
        return $neg ? -$val : $val;
    }

    /**
     * Armazena mensagem flash na sessão sem redirecionar (ex.: respostas AJAX).
     */
    public static function setFlash(string $type, string $msg): void {
        self::initSession();
        $_SESSION['droma_flash'] = ['type' => $type, 'message' => $msg];
    }

    /**
     * Armazena mensagem flash na sessão e redireciona sem poluir a URL.
     */
    public static function flashRedirect(string $url, string $type, string $msg): never {
        self::setFlash($type, $msg);
        header("Location: {$url}");
        exit;
    }

    /**
     * Lê e apaga a mensagem flash da sessão.
     */
    public static function consumeFlash(): array {
        self::initSession();
        $flash = $_SESSION['droma_flash'] ?? null;
        unset($_SESSION['droma_flash']);
        return is_array($flash) ? $flash : [];
    }

    /**
     * Retorna uma cor fixa e consistente para uma categoria pelo nome.
     */
    public static function categoryColor(string $name): string {
        static $palette = [
            '#D4AF6C', '#10B981', '#3B82F6', '#F59E0B', '#EC4899',
            '#8B5CF6', '#14B8A6', '#F97316', '#06B6D4', '#84CC16',
        ];
        $idx = abs(crc32(mb_strtolower(trim($name)))) % count($palette);
        return $palette[$idx];
    }
}


