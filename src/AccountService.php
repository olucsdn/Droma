<?php
declare(strict_types=1);

/**
 * Serviço centralizado de cálculo de saldos de contas bancárias.
 */
final class AccountService {

    /**
     * Retorna o saldo atual consolidado de uma conta específica pertencente ao usuário.
     */
    public static function saldo(PDO $pdo, int $userId, int $accountId): float {
        $stmt = $pdo->prepare(
            "SELECT
                a.initial_balance,
                COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' AND t.visivel = 1 AND t.transfer_id IS NULL THEN t.amount ELSE 0 END), 0) AS receitas,
                COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' AND t.visivel = 1 AND t.transfer_id IS NULL THEN t.amount ELSE 0 END), 0) AS despesas,
                COALESCE(SUM(CASE WHEN t.transfer_id IS NOT NULL AND t.status = 'completed' AND t.visivel = 1 THEN (CASE WHEN t.type = 'income' THEN t.amount ELSE -t.amount END) ELSE 0 END), 0) AS transferencias
             FROM accounts a
             LEFT JOIN transactions t ON t.account_id = a.id AND t.user_id = a.user_id
             WHERE a.id = ? AND a.user_id = ?
             GROUP BY a.id, a.initial_balance"
        );
        $stmt->execute([$accountId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0.0;
        }
        return (float)$row['initial_balance'] + (float)$row['receitas'] - (float)$row['despesas'] + (float)$row['transferencias'];
    }
}
