<?php
/**
 * Mecanismo de Autenticação Segura para a API REST do Droma
 */
declare(strict_types=1);

require_once __DIR__ . '/ApiResponse.php';

final class ApiAuth {
    /**
     * Autentica o usuário por Bearer Token ou Sessão Web ativa
     * Retorna o ID do usuário autenticado ou finaliza com HTTP 401
     */
    public static function authenticate(PDO $pdo): int {
        ApiResponse::handlePreflight();

        $token = self::extractToken();

        if ($token !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE api_token = ? AND api_token IS NOT NULL AND api_token <> ''");
            $stmt->execute([$token]);
            $userId = $stmt->fetchColumn();

            if ($userId) {
                return (int) $userId;
            }
            ApiResponse::unauthorized('Token de API inválido ou revogado.');
        }

        // Tenta autenticação por sessão web ativa com mitigação rigorosa contra CSRF
        require_once __DIR__ . '/Security.php';
        Security::initSession();

        if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            // Se for método de alteração via sessão, exige cabeçalho customizado ou token CSRF para inviabilizar cross-origin CSRF
            if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
                $hasCustomHeader = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
                $hasCsrf = Security::validateCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null));
                if (!$hasCustomHeader && !$hasCsrf) {
                    ApiResponse::forbidden('Requisições via sessão web com métodos modificadores exigem cabeçalho X-Requested-With ou CSRF Token.');
                }
            }
            return (int) $_SESSION['user_id'];
        }

        ApiResponse::unauthorized('Autenticação obrigatória. Envie Authorization: Bearer <api_token> no header da requisição.');
        return 0; // Inalcançável devido ao exit no ApiResponse
    }

    /**
     * Extrai o token de API dos cabeçalhos HTTP ou query string
     */
    public static function extractToken(): string {
        $header = '';

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
        }

        if ($header !== '') {
            if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
                return trim($matches[1]);
            }
        }

        // Suporte alternativo ao header X-API-Key
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }

        return '';
    }

    /**
     * Gera um novo token seguro de 64 caracteres hexadecimais
     */
    public static function generateToken(): string {
        return bin2hex(random_bytes(32));
    }
}
