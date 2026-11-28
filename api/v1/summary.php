<?php
/**
 * Endpoint REST: GET /api/v1/summary.php
 * Returns consolidated financial summary and indicators for the authenticated user
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/ApiAuth.php';
require_once __DIR__ . '/../../src/ApiResponse.php';
require_once __DIR__ . '/../../src/Security.php';

$userId = ApiAuth::authenticate($pdo);
Security::ensureTransferColumn($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Método não permitido. Utilize GET.', 405);
}

// 1. Saldo inicial somado das contas
$stmt = $pdo->prepare("SELECT COALESCE(SUM(initial_balance), 0) FROM accounts WHERE user_id = ?");
$stmt->execute([$userId]);
$saldoInicial = (float) $stmt->fetchColumn();

// 2. Total histórico de receitas realizadas
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'income' AND status = 'completed' AND visivel = 1");
$stmt->execute([$userId]);
$receitasHistorico = (float) $stmt->fetchColumn();

// 3. Total histórico de despesas realizadas
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'completed' AND visivel = 1");
$stmt->execute([$userId]);
$despesasHistorico = (float) $stmt->fetchColumn();

$saldoConsolidado = $saldoInicial + $receitasHistorico - $despesasHistorico;

// 4. Receitas do mês atual (exclui transferências internas)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM transactions
    WHERE user_id = ? AND type = 'income' AND status = 'completed' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')
      AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())
");
$stmt->execute([$userId]);
$receitasMes = (float) $stmt->fetchColumn();

// 5. Despesas do mês atual (exclui transferências internas)
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) FROM transactions
    WHERE user_id = ? AND type = 'expense' AND status = 'completed' AND visivel = 1 AND transfer_id IS NULL AND (category_name IS NULL OR category_name != 'Transferência')
      AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())
");
$stmt->execute([$userId]);
$despesasMes = (float) $stmt->fetchColumn();

$resultadoMes = $receitasMes - $despesasMes;
$taxaPoupancaMes = $receitasMes > 0 ? round(($resultadoMes / $receitasMes) * 100, 1) : 0.0;

// 6. Contagem de contas e cartões
$stmtContas = $pdo->prepare("SELECT COUNT(*) FROM accounts WHERE user_id = ?");
$stmtContas->execute([$userId]);
$totalContas = (int) $stmtContas->fetchColumn();

$stmtCartoes = $pdo->prepare("SELECT COUNT(*) FROM credit_cards WHERE user_id = ?");
$stmtCartoes->execute([$userId]);
$totalCartoes = (int) $stmtCartoes->fetchColumn();

// 7. Faturas pendentes de cartão de crédito (parcelas ativas + compras à vista pendentes)
$stmtFaturas = $pdo->prepare("
    SELECT (
        (SELECT COALESCE(SUM(i.amount), 0) FROM installments i JOIN transactions t ON t.id = i.transaction_id JOIN credit_cards c ON c.id = i.credit_card_id WHERE c.user_id = ? AND i.status = 'pending' AND t.visivel = 1) +
        (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = ? AND credit_card_id IS NOT NULL AND status = 'pending' AND visivel = 1 AND id NOT IN (SELECT transaction_id FROM installments))
    )
");
$stmtFaturas->execute([$userId, $userId]);
$faturasPendentes = (float) $stmtFaturas->fetchColumn();

ApiResponse::json([
    'periodo' => date('Y-m'),
    'saldo_consolidado' => $saldoConsolidado,
    'mes_atual' => [
        'receitas'       => $receitasMes,
        'despesas'       => $despesasMes,
        'saldo_operacional' => $resultadoMes,
        'taxa_poupanca_pct' => $taxaPoupancaMes,
        'status'         => ($resultadoMes >= 0 ? 'superavit' : 'deficit')
    ],
    'totais_gerais' => [
        'contas_cadastradas' => $totalContas,
        'cartoes_cadastrados' => $totalCartoes,
        'faturas_cartao_pendentes' => $faturasPendentes
    ]
], 200, null, ['timestamp' => time(), 'version' => '1.0']);
