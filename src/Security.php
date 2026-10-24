<?php
/**
 * Classe de Segurança e Helpers Globais — Projeto Droma
 * Metodologia SDD — Fase 1 (Estabilização e Segurança)
 */
final class Security {

    /**
     * Garante que a sessão PHP esteja inicializada com configurações seguras.
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                ini_set('session.use_only_cookies', '1');
                ini_set('session.use_strict_mode', '1');
                ini_set('session.cookie_httponly', '1');
                ini_set('session.cookie_samesite', 'Lax');

                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
                    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

                if ($isHttps) {
                    ini_set('session.cookie_secure', '1');
                }

                session_start();
            } elseif (PHP_SAPI === 'cli') {
                @session_start();
            }
        }
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
}


