<?php
/**
 * Endpoint REST: GET /api/v1/categorias.php
 * Retorna as categorias de receitas e despesas do usuário
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/ApiAuth.php';
require_once __DIR__ . '/../../src/ApiResponse.php';

$userId = ApiAuth::authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Método não permitido. Utilize GET.', 405);
}

$typeFilter = $_GET['type'] ?? '';
$where = ["c.user_id = ?"];
$params = [$userId];

if (in_array($typeFilter, ['income', 'expense'], true)) {
    $where[] = "c.type = ?";
    $params[] = $typeFilter;
}

$whereSql = implode(" AND ", $where);

$stmt = $pdo->prepare("
    SELECT 
        c.id, 
        c.name, 
        c.type, 
        c.budget_limit,
        c.created_at,
        COUNT(t.id) AS total_transacoes,
        COALESCE(SUM(t.amount), 0) AS volume_financeiro
    FROM categories c
    LEFT JOIN transactions t ON t.category_id = c.id AND t.user_id = c.user_id
    WHERE {$whereSql}
    GROUP BY c.id, c.name, c.type, c.budget_limit, c.created_at
    ORDER BY c.type DESC, c.name ASC
");
$stmt->execute($params);
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formatadas = array_map(static fn($c) => [
    'id'                => (int) $c['id'],
    'name'              => $c['name'],
    'type'              => $c['type'],
    'budget_limit'      => !empty($c['budget_limit']) ? (float) $c['budget_limit'] : null,
    'total_transacoes'  => (int) $c['total_transacoes'],
    'volume_financeiro' => (float) $c['volume_financeiro'],
    'created_at'        => $c['created_at']
], $categorias);

ApiResponse::json([
    'categorias' => $formatadas,
    'total'      => count($formatadas)
]);
