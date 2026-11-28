<?php
declare(strict_types=1);

require_once __DIR__ . '/AccountService.php';
require_once __DIR__ . '/Security.php';

/**
 * Serviço de Planejamento Financeiro Avançado:
 * - Saldo Projetado (30/60/90 dias)
 * - Patrimônio Líquido Consolidado + Snapshots Mensais
 * - Tags Transversais e Divisão de Lançamento (Splits)
 * - Parser de Lançamento por Texto Livre (PT-BR)
 */
class PlanningService {
    /**
     * Projeta o saldo diário para os próximos $dias dias cruzando:
     * saldo atual das contas + pendentes + recorrências ativas + parcelas de cartão.
     */
    public static function saldoProjetado(PDO $pdo, int $userId, int $dias = 30): array {
        $dias = max(7, min(90, $dias));
        $contas = AccountService::listarComSaldo($pdo, $userId);
        $saldoAtual = 0.0;
        foreach ($contas as $c) {
            $saldoAtual += (float)($c['saldo_atual'] ?? 0);
        }

        $hoje = new DateTimeImmutable('today');
        $fim  = $hoje->modify("+{$dias} days");
        $hojeStr = $hoje->format('Y-m-d');
        $fimStr  = $fim->format('Y-m-d');

        $deltas = [];
        $addDelta = static function (string $dt, float $val) use (&$deltas, $hojeStr, $fimStr): void {
            if ($dt < $hojeStr) {
                $dt = $hojeStr;
            }
            if ($dt > $fimStr) {
                return;
            }
            $deltas[$dt] = ($deltas[$dt] ?? 0.0) + $val;
        };

        // 1. Transações pendentes (não-cartão)
        $stPend = $pdo->prepare(
            "SELECT type, amount, COALESCE(due_date, date) AS ref_date
             FROM transactions
             WHERE user_id = ? AND visivel = 1 AND status = 'pending' AND credit_card_id IS NULL
               AND COALESCE(due_date, date) <= ?"
        );
        $stPend->execute([$userId, $fimStr]);
        foreach ($stPend->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sinal = ($row['type'] === 'income') ? 1.0 : -1.0;
            $addDelta((string)$row['ref_date'], $sinal * (float)$row['amount']);
        }

        // 2. Parcelas pendentes de cartão de crédito
        $stInst = $pdo->prepare(
            "SELECT i.amount, i.due_date
             FROM installments i
             JOIN transactions t ON t.id = i.transaction_id
             WHERE t.user_id = ? AND t.visivel = 1 AND i.status = 'pending' AND i.due_date <= ?"
        );
        $stInst->execute([$userId, $fimStr]);
        foreach ($stInst->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $addDelta((string)$row['due_date'], -(float)$row['amount']);
        }

        // 3. Recorrências ativas projetadas até $fimStr
        $stRec = $pdo->prepare(
            "SELECT type, amount, frequency, day_of_month, next_date
             FROM recurring_transactions
             WHERE user_id = ? AND active = 1 AND next_date <= ?"
        );
        $stRec->execute([$userId, $fimStr]);
        foreach ($stRec->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            $sinal = ($rec['type'] === 'income') ? 1.0 : -1.0;
            $cur = new DateTimeImmutable((string)$rec['next_date']);
            $guard = 0;
            while ($cur <= $fim && $guard < 16) {
                $guard++;
                $addDelta($cur->format('Y-m-d'), $sinal * (float)$rec['amount']);
                if ($rec['frequency'] === 'weekly') {
                    $cur = $cur->modify('+1 week');
                } elseif ($rec['frequency'] === 'yearly') {
                    $cur = $cur->modify('+1 year');
                } else {
                    $nextMonth = $cur->modify('first day of next month');
                    $diaAlvo = max(1, (int)($rec['day_of_month'] ?? $cur->format('j')));
                    $dia = min($diaAlvo, (int)$nextMonth->format('t'));
                    $cur = $nextMonth->setDate((int)$nextMonth->format('Y'), (int)$nextMonth->format('m'), $dia);
                }
            }
        }

        $series = [];
        $acumulado = $saldoAtual;
        $menorSaldo = $saldoAtual;
        $dataMenor = $hojeStr;

        for ($i = 0; $i <= $dias; $i++) {
            $d = $hoje->modify("+{$i} days")->format('Y-m-d');
            $delta = $deltas[$d] ?? 0.0;
            $acumulado = round($acumulado + $delta, 2);
            if ($acumulado < $menorSaldo) {
                $menorSaldo = $acumulado;
                $dataMenor = $d;
            }
            $series[] = [
                'date'  => $d,
                'delta' => round($delta, 2),
                'saldo' => $acumulado,
            ];
        }

        return [
            'dias'             => $dias,
            'saldo_atual'      => round($saldoAtual, 2),
            'saldo_final'      => $acumulado,
            'menor_saldo'      => round($menorSaldo, 2),
            'data_menor_saldo' => $dataMenor,
            'series'           => $series,
        ];
    }

    /**
     * Calcula Patrimônio Líquido (Contas + Investimentos - Faturas abertas)
     * e registra snapshot mensal em net_worth_snapshots.
     */
    public static function patrimonioLiquido(PDO $pdo, int $userId): array {
        $contas = AccountService::listarComSaldo($pdo, $userId);
        $accountsTotal = 0.0;
        foreach ($contas as $c) {
            $accountsTotal += (float)($c['saldo_atual'] ?? 0);
        }

        $stInv = $pdo->prepare(
            "SELECT i.id, i.initial_amount,
                    COALESCE(SUM(CASE WHEN t.type = 'expense' AND t.status = 'completed' AND t.visivel = 1 THEN t.amount ELSE 0 END), 0) AS aportes,
                    COALESCE(SUM(CASE WHEN t.type = 'income' AND t.status = 'completed' AND t.visivel = 1 THEN t.amount ELSE 0 END), 0) AS retornos
             FROM investments i
             LEFT JOIN transactions t ON t.investment_id = i.id AND t.user_id = i.user_id
             WHERE i.user_id = ? AND i.status = 'active'
             GROUP BY i.id, i.initial_amount"
        );
        $stInv->execute([$userId]);
        $investmentsTotal = 0.0;
        foreach ($stInv->fetchAll(PDO::FETCH_ASSOC) as $inv) {
            $aportes = (float)$inv['aportes'];
            if ($aportes == 0.0 && (float)$inv['initial_amount'] > 0) {
                $aportes = (float)$inv['initial_amount'];
            }
            $investmentsTotal += max(0.0, $aportes - (float)$inv['retornos']);
        }

        $stDebt = $pdo->prepare(
            "SELECT
                COALESCE((SELECT SUM(i.amount) FROM installments i JOIN transactions t ON t.id = i.transaction_id WHERE t.user_id = ? AND t.visivel = 1 AND i.status = 'pending'), 0) +
                COALESCE((SELECT SUM(amount) FROM transactions WHERE user_id = ? AND visivel = 1 AND status = 'pending' AND credit_card_id IS NOT NULL AND id NOT IN (SELECT transaction_id FROM installments)), 0) AS divida"
        );
        $stDebt->execute([$userId, $userId]);
        $cardsDebt = (float)$stDebt->fetchColumn();

        $netWorth = round($accountsTotal + $investmentsTotal - $cardsDebt, 2);
        $mesAtual = date('Y-m');
        $historico = [];

        try {
            $up = $pdo->prepare(
                "INSERT INTO net_worth_snapshots (user_id, snapshot_month, accounts_total, investments_total, cards_debt, net_worth)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE accounts_total = VALUES(accounts_total), investments_total = VALUES(investments_total), cards_debt = VALUES(cards_debt), net_worth = VALUES(net_worth)"
            );
            $up->execute([$userId, $mesAtual, round($accountsTotal, 2), round($investmentsTotal, 2), round($cardsDebt, 2), $netWorth]);

            $stHist = $pdo->prepare(
                "SELECT snapshot_month, net_worth FROM net_worth_snapshots WHERE user_id = ? ORDER BY snapshot_month DESC LIMIT 6"
            );
            $stHist->execute([$userId]);
            $historico = array_reverse($stHist->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            // Tabela opcional ainda não migrada
        }

        return [
            'accounts_total'    => round($accountsTotal, 2),
            'investments_total' => round($investmentsTotal, 2),
            'cards_debt'        => round($cardsDebt, 2),
            'net_worth'         => $netWorth,
            'historico'         => $historico,
        ];
    }

    /**
     * Interpreta frase natural em PT-BR para pré-preencher um lançamento.
     * Ex.: "Gastei 45,90 no mercado ontem no Nubank"
     */
    public static function parseTextoLancamento(string $texto, array $contas = [], array $categorias = []): array {
        $raw = trim($texto);
        $lower = mb_strtolower($raw, 'UTF-8');

        $type = preg_match('/\b(recebi|ganhei|sal[áa]rio|freela|reembolso|dividendo|rendimento|entrada)\b/u', $lower)
            ? 'income'
            : 'expense';

        $amount = null;
        if (preg_match('/(?:r\$\s*)?(\d{1,3}(?:\.\d{3})*,\d{1,2}|\d+(?:[.,]\d{1,2})?)/u', $lower, $m)) {
            $amount = Security::parseMoney($m[1]);
        }

        $date = date('Y-m-d');
        if (str_contains($lower, 'ontem')) {
            $date = date('Y-m-d', strtotime('-1 day'));
        } elseif (str_contains($lower, 'anteontem')) {
            $date = date('Y-m-d', strtotime('-2 days'));
        } elseif (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $lower, $dm)) {
            $y = !empty($dm[3]) ? (strlen($dm[3]) === 2 ? '20' . $dm[3] : $dm[3]) : date('Y');
            $cand = sprintf('%04d-%02d-%02d', (int)$y, (int)$dm[2], (int)$dm[1]);
            if (Security::sanitizeDate($cand, null) !== null) {
                $date = $cand;
            }
        }

        $accountId = null;
        foreach ($contas as $c) {
            $cName = mb_strtolower((string)($c['name'] ?? ''), 'UTF-8');
            if ($cName !== '' && str_contains($lower, $cName)) {
                $accountId = (int)$c['id'];
                break;
            }
        }

        $categoryId = null;
        foreach ($categorias as $cat) {
            if (($cat['type'] ?? '') !== $type) {
                continue;
            }
            $catName = mb_strtolower((string)($cat['name'] ?? ''), 'UTF-8');
            if ($catName !== '' && str_contains($lower, $catName)) {
                $categoryId = (int)$cat['id'];
                break;
            }
        }

        $desc = preg_replace('/\b(gastei|paguei|comprei|recebi|ganhei|ontem|anteontem|hoje|no|na|em|de|r\$)\b/u', ' ', $raw);
        $desc = preg_replace('/\d{1,3}(?:\.\d{3})*,\d{1,2}|\d+(?:[.,]\d{1,2})?/', ' ', (string)$desc);
        $desc = trim((string)preg_replace('/\s+/', ' ', (string)$desc));
        if ($desc === '') {
            $desc = mb_substr($raw, 0, 80, 'UTF-8');
        }

        return [
            'type'        => $type,
            'amount'      => $amount,
            'date'        => $date,
            'account_id'  => $accountId,
            'category_id' => $categoryId,
            'description' => $desc,
        ];
    }

    /**
     * Associa tags (CSV) e divisão de categorias (splits) a uma transação.
     */
    public static function salvarTagsESplits(PDO $pdo, int $userId, int $txId, string $tagsCsv, array $splits = []): void {
        $tags = array_unique(array_filter(array_map(
            static fn(string $t): string => mb_substr(ltrim(trim($t), '#'), 0, 40, 'UTF-8'),
            explode(',', $tagsCsv)
        )));

        if (!empty($tags)) {
            try {
                $insTag = $pdo->prepare("INSERT IGNORE INTO tags (user_id, name) VALUES (?, ?)");
                $selTag = $pdo->prepare("SELECT id FROM tags WHERE user_id = ? AND name = ? LIMIT 1");
                $link   = $pdo->prepare("INSERT IGNORE INTO transaction_tags (transaction_id, tag_id) VALUES (?, ?)");
                foreach ($tags as $tagName) {
                    if ($tagName === '') {
                        continue;
                    }
                    $insTag->execute([$userId, $tagName]);
                    $selTag->execute([$userId, $tagName]);
                    $tagId = (int)$selTag->fetchColumn();
                    if ($tagId > 0) {
                        $link->execute([$txId, $tagId]);
                    }
                }
            } catch (Throwable $e) {
                // Tabela tags opcional antes da migração
            }
        }

        if (!empty($splits)) {
            try {
                $insSplit = $pdo->prepare(
                    "INSERT INTO transaction_splits (transaction_id, category_id, amount, description) VALUES (?, ?, ?, ?)"
                );
                foreach ($splits as $sp) {
                    $catId = (int)($sp['category_id'] ?? 0);
                    $amt   = (float)($sp['amount'] ?? 0);
                    $sDesc = Security::sanitizeText((string)($sp['description'] ?? ''), 120, '');
                    if ($catId > 0 && $amt > 0) {
                        $insSplit->execute([$txId, $catId, $amt, $sDesc ?: null]);
                    }
                }
            } catch (Throwable $e) {
                // Tabela transaction_splits opcional antes da migração
            }
        }
    }
}
