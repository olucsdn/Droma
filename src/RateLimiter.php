<?php
/**
 * Droma Financy — Mecanismo de Rate Limiting e Proteção contra Força Bruta
 * 
 * Protege contra:
 * 1. Ataques de força bruta em senhas (Login)
 * 2. Enumeração e adivinhação do código de 6 dígitos (Verificação de Conta)
 * 3. E-mail Bombing e exaustão de cota SMTP (Recuperação de Senha)
 * 4. Sobrecarga e flood de requisições por IP
 */
declare(strict_types=1);

final class RateLimiter {
    private static bool $tableChecked = false;

    /**
     * Garante que a tabela rate_limits exista no banco de dados (auto-migração resiliente)
     */
    public static function ensureTable(PDO $pdo): void {
        if (self::$tableChecked) {
            return;
        }

        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `rate_limits` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `rate_key` varchar(128) NOT NULL,
                    `action` varchar(32) NOT NULL,
                    `attempts` int unsigned NOT NULL DEFAULT 1,
                    `first_attempt_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_attempt_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `blocked_until` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_rate_key` (`rate_key`),
                    KEY `idx_blocked_until` (`blocked_until`),
                    KEY `idx_action_last` (`action`, `last_attempt_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
            self::$tableChecked = true;
        } catch (Throwable $e) {
            error_log("[RateLimiter EnsureTable Error] " . $e->getMessage());
        }
    }

    /**
     * Resolve o IP público real do cliente considerando Cloudflare, proxies reversos e Render
     */
    public static function getClientIp(): string {
        $ipSources = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_REAL_IP',        // Nginx / Proxies
            'HTTP_X_FORWARDED_FOR',  // Load Balancers / Render
            'REMOTE_ADDR'            // Conexão TCP direta
        ];

        foreach ($ipSources as $source) {
            if (!empty($_SERVER[$source])) {
                $rawIp = (string)$_SERVER[$source];
                // X-Forwarded-For pode conter múltiplos IPs (client, proxy1, proxy2); o primeiro é o do cliente
                if (str_contains($rawIp, ',')) {
                    $ips = explode(',', $rawIp);
                    $rawIp = trim($ips[0]);
                }
                $filtered = filter_var($rawIp, FILTER_VALIDATE_IP);
                if ($filtered !== false) {
                    return $filtered;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Gera uma chave única e segura de rastreamento
     */
    private static function makeKey(string $action, string $identifier): string {
        $norm = strtolower(trim($identifier));
        return hash('sha256', "droma:rl:{$action}:{$norm}");
    }

    /**
     * Verifica se o identificador está atualmente bloqueado ou se excedeu o limite
     * 
     * @return array{allowed: bool, attempts: int, remaining: int, retry_after: int, message: string}
     */
    public static function check(
        PDO $pdo,
        string $action,
        string $identifier,
        int $maxAttempts = 5,
        int $windowSeconds = 300,
        int $blockSeconds = 900
    ): array {
        self::ensureTable($pdo);

        $key = self::makeKey($action, $identifier);
        $now = time();

        try {
            $stmt = $pdo->prepare("SELECT attempts, UNIX_TIMESTAMP(first_attempt_at) as first_ts, UNIX_TIMESTAMP(blocked_until) as block_ts FROM rate_limits WHERE rate_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return [
                    'allowed'     => true,
                    'attempts'    => 0,
                    'remaining'   => $maxAttempts,
                    'retry_after' => 0,
                    'message'     => ''
                ];
            }

            $attempts = (int)$row['attempts'];
            $firstTs  = (int)($row['first_ts'] ?? $now);
            $blockTs  = !empty($row['block_ts']) ? (int)$row['block_ts'] : null;

            // 1. Está atualmente sob bloqueio ativo?
            if ($blockTs !== null && $blockTs > $now) {
                $retryAfter = $blockTs - $now;
                $minutos = max(1, (int)ceil($retryAfter / 60));
                return [
                    'allowed'     => false,
                    'attempts'    => $attempts,
                    'remaining'   => 0,
                    'retry_after' => $retryAfter,
                    'message'     => "Muitas tentativas detectadas. Por segurança, aguarde {$minutos} minuto(s) antes de tentar novamente."
                ];
            }

            // 2. Se o bloqueio anterior já expirou, reseta o estado
            if ($blockTs !== null && $blockTs <= $now) {
                self::clear($pdo, $action, $identifier);
                return [
                    'allowed'     => true,
                    'attempts'    => 0,
                    'remaining'   => $maxAttempts,
                    'retry_after' => 0,
                    'message'     => ''
                ];
            }

            // 3. Se a janela de tempo expirou, o contador deve ser reiniciado
            if (($now - $firstTs) >= $windowSeconds) {
                self::clear($pdo, $action, $identifier);
                return [
                    'allowed'     => true,
                    'attempts'    => 0,
                    'remaining'   => $maxAttempts,
                    'retry_after' => 0,
                    'message'     => ''
                ];
            }

            // 4. Ainda dentro da janela: avalia se já atingiu o teto
            $remaining = max(0, $maxAttempts - $attempts);
            $isAllowed = $attempts < $maxAttempts;

            return [
                'allowed'     => $isAllowed,
                'attempts'    => $attempts,
                'remaining'   => $remaining,
                'retry_after' => 0,
                'message'     => $isAllowed ? '' : "Limite de tentativas atingido. Aguarde alguns minutos."
            ];

        } catch (Throwable $e) {
            error_log("[RateLimiter Check Error] " . $e->getMessage());
            // Em caso de falha transitória de banco, permite a requisição por resiliência
            return [
                'allowed'     => true,
                'attempts'    => 0,
                'remaining'   => $maxAttempts,
                'retry_after' => 0,
                'message'     => ''
            ];
        }
    }

    /**
     * Registra uma falha / tentativa incorreta e aplica bloqueio imediato se o limite for atingido
     * 
     * @return array{allowed: bool, attempts: int, remaining: int, retry_after: int, message: string}
     */
    public static function hit(
        PDO $pdo,
        string $action,
        string $identifier,
        int $maxAttempts = 5,
        int $windowSeconds = 300,
        int $blockSeconds = 900
    ): array {
        self::ensureTable($pdo);

        // Limpeza oportunística com 5% de chance
        if (random_int(1, 20) === 1) {
            self::cleanup($pdo);
        }

        $key = self::makeKey($action, $identifier);
        $now = time();

        try {
            $stmt = $pdo->prepare("SELECT attempts, UNIX_TIMESTAMP(first_attempt_at) as first_ts, UNIX_TIMESTAMP(blocked_until) as block_ts FROM rate_limits WHERE rate_key = ? LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // Primeira tentativa incorreta
                $ins = $pdo->prepare("INSERT INTO rate_limits (rate_key, action, attempts, first_attempt_at, last_attempt_at) VALUES (?, ?, 1, NOW(), NOW())");
                $ins->execute([$key, $action]);

                return [
                    'allowed'     => true,
                    'attempts'    => 1,
                    'remaining'   => max(0, $maxAttempts - 1),
                    'retry_after' => 0,
                    'message'     => ''
                ];
            }

            $firstTs = (int)($row['first_ts'] ?? $now);
            $windowExpired = ($now - $firstTs) >= $windowSeconds;

            if ($windowExpired) {
                // Reinicia a janela
                $upd = $pdo->prepare("UPDATE rate_limits SET attempts = 1, first_attempt_at = NOW(), last_attempt_at = NOW(), blocked_until = NULL WHERE rate_key = ?");
                $upd->execute([$key]);

                return [
                    'allowed'     => true,
                    'attempts'    => 1,
                    'remaining'   => max(0, $maxAttempts - 1),
                    'retry_after' => 0,
                    'message'     => ''
                ];
            }

            // Incrementa tentativa dentro da janela
            $newAttempts = (int)$row['attempts'] + 1;

            if ($newAttempts >= $maxAttempts) {
                // Bloqueia o acesso imediatamente
                $upd = $pdo->prepare("UPDATE rate_limits SET attempts = ?, last_attempt_at = NOW(), blocked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE rate_key = ?");
                $upd->execute([$newAttempts, $blockSeconds, $key]);

                $minutos = max(1, (int)ceil($blockSeconds / 60));
                return [
                    'allowed'     => false,
                    'attempts'    => $newAttempts,
                    'remaining'   => 0,
                    'retry_after' => $blockSeconds,
                    'message'     => "Muitas tentativas incorretas. Por segurança, seu acesso foi temporariamente suspenso por {$minutos} minutos."
                ];
            }

            // Apenas incrementa contador
            $upd = $pdo->prepare("UPDATE rate_limits SET attempts = ?, last_attempt_at = NOW() WHERE rate_key = ?");
            $upd->execute([$newAttempts, $key]);

            return [
                'allowed'     => true,
                'attempts'    => $newAttempts,
                'remaining'   => max(0, $maxAttempts - $newAttempts),
                'retry_after' => 0,
                'message'     => ''
            ];

        } catch (Throwable $e) {
            error_log("[RateLimiter Hit Error] " . $e->getMessage());
            return [
                'allowed'     => true,
                'attempts'    => 1,
                'remaining'   => $maxAttempts,
                'retry_after' => 0,
                'message'     => ''
            ];
        }
    }

    /**
     * Remove o registro após uma ação bem-sucedida (ex: login com sucesso)
     */
    public static function clear(PDO $pdo, string $action, string $identifier): void {
        self::ensureTable($pdo);

        try {
            $key = self::makeKey($action, $identifier);
            $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE rate_key = ?");
            $stmt->execute([$key]);
        } catch (Throwable $e) {
            error_log("[RateLimiter Clear Error] " . $e->getMessage());
        }
    }

    /**
     * Limpeza automática de registros antigos (mantém a tabela minúscula, menos de 50 KB)
     */
    public static function cleanup(PDO $pdo): void {
        self::ensureTable($pdo);

        try {
            $pdo->exec("
                DELETE FROM rate_limits 
                WHERE (blocked_until IS NULL AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                   OR (blocked_until IS NOT NULL AND blocked_until < DATE_SUB(NOW(), INTERVAL 1 HOUR))
            ");
        } catch (Throwable $e) {
            error_log("[RateLimiter Cleanup Error] " . $e->getMessage());
        }
    }
}
