<?php
/**
 * Helper de Respostas JSON Padronizadas para a API REST do Droma
 */
declare(strict_types=1);

final class ApiResponse {
    /**
     * Envia resposta JSON estruturada
     */
    public static function json(
        mixed $data = null,
        int $status = 200,
        array|string|null $errors = null,
        array $meta = []
    ): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            self::applyCorsHeaders();
        }

        $payload = [
            'success' => ($status >= 200 && $status < 300),
            'status'  => $status,
            'data'    => $data,
            'errors'  => $errors,
        ];

        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Resposta de erro genérica (4xx ou 5xx)
     */
    public static function error(string $message, int $status = 400, mixed $details = null): void {
        self::json(null, $status, [
            'message' => $message,
            'details' => $details
        ]);
    }

    /**
     * Resposta 401 Não Autorizado
     */
    public static function unauthorized(string $message = 'Autenticação necessária. Forneça um Bearer Token válido no header Authorization ou uma sessão ativa.'): void {
        self::json(null, 401, ['message' => $message]);
    }

    /**
     * Resposta 403 Proibido / Acesso negado
     */
    public static function forbidden(string $message = 'Acesso negado. Você não tem permissão para acessar ou modificar este recurso.'): void {
        self::json(null, 403, ['message' => $message]);
    }

    /**
     * Resposta 404 Não Encontrado
     */
    public static function notFound(string $message = 'Recurso não encontrado.'): void {
        self::json(null, 404, ['message' => $message]);
    }

    /**
     * Trata requisições OPTIONS (CORS preflight)
     */
    public static function handlePreflight(): void {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            if (!headers_sent()) {
                http_response_code(204);
                self::applyCorsHeaders();
            }
            exit;
        }
    }

    /**
     * Aplica cabeçalhos CORS restritivos (apenas mesma origem ou localhost)
     */
    private static function applyCorsHeaders(): void {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host   = $_SERVER['HTTP_HOST'] ?? '';

        if ($origin !== '' && $host !== '') {
            $parsedHost = parse_url($origin, PHP_URL_HOST);
            if ($parsedHost === $host || $parsedHost === 'localhost' || $parsedHost === '127.0.0.1') {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Access-Control-Allow-Credentials: true');
            }
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-API-Key, X-CSRF-Token');
    }
}
