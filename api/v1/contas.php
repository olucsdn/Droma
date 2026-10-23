<?php
/**
 * Endpoint REST: GET /api/v1/contas.php
 * Retorna as contas bancárias do usuário autenticado com os respectivos saldos atualizados
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/ApiAuth.php';
require_once __DIR__ . '/../../src/ApiResponse.php';

$userId = ApiAuth::authenticate($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Método não permitido. Utilize GET.', 405);
}

$stmt = $pdo->prepare("
    SELECT 
        a.id, 
        a.name, 
        a.type, 
        a.initial_balance,
        a.created_at,
        COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_receitas,
        COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' THEN t.amount ELSE 0 END), 0) AS total_despesas,
        COUNT(t.id) AS total_transacoes
    FROM accounts a
    LEFT JOIN transactions t ON t.account_id = a.id AND t.user_id = a.user_id
    WHERE a.user_id = ?
    GROUP BY a.id, a.name, a.type, a.initial_balance, a.created_at
    ORDER BY a.name ASC
");
$stmt->execute([$userId]);
$contas = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lista = [];
$saldoTotal = 0.0;

foreach ($contas as $c) {
    $ini = (float) $c['initial_balance'];
    $rec = (float) $c['total_receitas'];
    $des = (float) $c['total_despesas'];
    $atual = $ini + $rec - $des;
    $saldoTotal += $atual;

    $lista[] = [
        'id'               => (int) $c['id'],
        'name'             => $c['name'],
        'type'             => $c['type'],
        'initial_balance'  => $ini,
        'total_receitas'   => $rec,
        'total_despesas'   => $des,
        'current_balance'  => $atual,
        'total_transacoes' => (int) $c['total_transacoes'],
        'created_at'       => $c['created_at']
    ];
}

ApiResponse::json([
    'contas'      => $lista,
    'total_saldo' => $saldoTotal,
    'quantidade'  => count($lista)
]);
