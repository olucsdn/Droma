<?php
/**
 * Endpoint de Sincronização Offline — Droma
 * Rota: POST /api/v1/sync.php
 * Recebe lotes de transações registradas localmente no IndexedDB durante modo offline.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/ApiAuth.php';
require_once __DIR__ . '/../../src/ApiResponse.php';
require_once __DIR__ . '/../../src/Security.php';

$userId = ApiAuth::authenticate($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    ApiResponse::error('Método não permitido. Utilize POST para sincronizar transações offline.', 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    ApiResponse::error('Corpo da requisição inválido. Esperado payload JSON.', 400);
}

$transactions = $payload['transactions'] ?? $payload;
if (!is_array($transactions) || empty($transactions)) {
    ApiResponse::json(['synced_count' => 0, 'results' => []], 200, 'Nenhum lançamento pendente para sincronização.');
}

if (!function_exists('calcularVencimentoFaturaSync')) {
    function calcularVencimentoFaturaSync(string $dataCompra, int $diaFechamento, int $diaVencimento, int $mesesAdicionais = 0): string {
        $ts = strtotime($dataCompra) ?: time();
        $diaCompra = (int)date('j', $ts);
        $mes = (int)date('n', $ts);
        $ano = (int)date('Y', $ts);

        if ($diaCompra >= $diaFechamento) {
            $mesesAdicionais += 1;
        }
        if ($diaVencimento < $diaFechamento) {
            $mesesAdicionais += 1;
        }

        $tsVencimento = strtotime("+{$mesesAdicionais} month", mktime(0, 0, 0, $mes, 1, $ano));
        $anoVenc = (int)date('Y', $tsVencimento);
        $mesVenc = (int)date('m', $tsVencimento);
        $diasNoMes = (int)date('t', $tsVencimento);
        $diaReal = min($diaVencimento, $diasNoMes);

        return sprintf('%04d-%02d-%02d', $anoVenc, $mesVenc, $diaReal);
    }
}

$results = [];
$syncedCount = 0;

$pdo->beginTransaction();

try {
    // Busca conta e categoria padrão do usuário para fallback seguro
    $stmtAccDef = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $stmtAccDef->execute([$userId]);
    $defaultAccountId = (int)$stmtAccDef->fetchColumn();

    $stmtCatDefInc = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = 'income' ORDER BY id ASC LIMIT 1");
    $stmtCatDefInc->execute([$userId]);
    $defaultCatIncome = (int)$stmtCatDefInc->fetchColumn();

    $stmtCatDefExp = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = 'expense' ORDER BY id ASC LIMIT 1");
    $stmtCatDefExp->execute([$userId]);
    $defaultCatExpense = (int)$stmtCatDefExp->fetchColumn();

    foreach ($transactions as $item) {
        if (!is_array($item)) continue;

        $clientId = trim((string)($item['client_id'] ?? 'off_' . bin2hex(random_bytes(6))));
        $type = in_array($item['type'] ?? '', ['income', 'expense'], true) ? $item['type'] : 'expense';

        // Normalização de valor monetário
        $amountRaw = $item['amount'] ?? 0;
        if (is_string($amountRaw)) {
            $amountRaw = str_replace(['R$', ' '], '', $amountRaw);
            if (str_contains($amountRaw, ',') && str_contains($amountRaw, '.')) {
                $amountRaw = str_replace('.', '', $amountRaw);
                $amountRaw = str_replace(',', '.', $amountRaw);
            } elseif (str_contains($amountRaw, ',')) {
                $amountRaw = str_replace(',', '.', $amountRaw);
            }
        }
        $amount = (float)$amountRaw;
        if ($amount <= 0) {
            $results[] = [
                'client_id' => $clientId,
                'status'    => 'error',
                'error'     => 'Valor monetário inválido ou menor/igual a zero.'
            ];
            continue;
        }

        $description = trim((string)($item['description'] ?? ''));
        if (mb_strlen($description) > 255) {
            $description = mb_substr($description, 0, 255);
        }

        $date = trim((string)($item['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        $dueDate = !empty($item['due_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item['due_date']) ? $item['due_date'] : null;
        $status = in_array($item['status'] ?? '', ['completed', 'pending', 'cancelled'], true) ? $item['status'] : 'completed';
        $urgency = in_array($item['urgency'] ?? '', ['baixa', 'media', 'alta'], true) ? $item['urgency'] : 'media';

        $accountId = !empty($item['account_id']) && is_numeric($item['account_id']) ? (int)$item['account_id'] : $defaultAccountId;
        if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
            $accountId = $defaultAccountId;
        }

        $categoryId = !empty($item['category_id']) && is_numeric($item['category_id']) ? (int)$item['category_id'] : 0;
        if (!Security::validateCategoryOwnership($pdo, $categoryId, $userId, $type)) {
            $categoryId = ($type === 'income') ? $defaultCatIncome : $defaultCatExpense;
        }

        $creditCardId = !empty($item['credit_card_id']) && is_numeric($item['credit_card_id']) ? (int)$item['credit_card_id'] : null;
        $installments = max(1, min(24, (int)($item['installments'] ?? 1)));
        $investmentId = !empty($item['investment_id']) && is_numeric($item['investment_id']) ? (int)$item['investment_id'] : null;

        if ($investmentId !== null && !Security::validateInvestmentOwnership($pdo, $investmentId, $userId)) {
            $investmentId = null;
        }

        // Receita nunca é via cartão nem possui urgência
        if ($type === 'income') {
            $creditCardId = null;
            $installments = 1;
            $urgency = 'media';
        }

        $usaCartao = ($creditCardId !== null && $type === 'expense');
        if ($usaCartao && !Security::validateCreditCardOwnership($pdo, $creditCardId, $userId)) {
            $usaCartao = false;
            $creditCardId = null;
            $installments = 1;
        }

        if ($usaCartao) {
            $stmtCard = $pdo->prepare("SELECT closing_day, due_day FROM credit_cards WHERE id = ?");
            $stmtCard->execute([$creditCardId]);
            $cardInfo = $stmtCard->fetch(PDO::FETCH_ASSOC);
            $closingDay = (int)($cardInfo['closing_day'] ?? 1);
            $dueDay     = (int)($cardInfo['due_day'] ?? 10);
            $vencPrimeiraFatura = calcularVencimentoFaturaSync($date, $closingDay, $dueDay, 0);

            if ($installments <= 1) {
                $stmtIns = $pdo->prepare(
                    "INSERT INTO transactions
                        (user_id, account_id, credit_card_id, investment_id, category_id, type, amount, description, date, due_date, status, urgency)
                     VALUES (?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?)"
                );
                $stmtIns->execute([
                    $userId, $accountId, $creditCardId, $investmentId, $categoryId, $amount,
                    $description !== '' ? $description : null, $date, $vencPrimeiraFatura, $urgency
                ]);
                $insertedId = (int)$pdo->lastInsertId();
            } else {
                $descPrincipal = $description !== '' ? "{$description} (Parcelado em {$installments}x)" : "Compra parcelada em {$installments}x";
                $stmtIns = $pdo->prepare(
                    "INSERT INTO transactions
                        (user_id, account_id, credit_card_id, investment_id, category_id, type, amount, description, date, due_date, status, urgency)
                     VALUES (?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?)"
                );
                $stmtIns->execute([
                    $userId, $accountId, $creditCardId, $investmentId, $categoryId, $amount,
                    $descPrincipal, $date, $vencPrimeiraFatura, $urgency
                ]);
                $insertedId = (int)$pdo->lastInsertId();

                $valorBase = round($amount / $installments, 2);
                $diferencaCentavos = round($amount - ($valorBase * $installments), 2);

                $stmtParc = $pdo->prepare(
                    "INSERT INTO installments
                        (transaction_id, credit_card_id, installment_number, total_installments, due_date, amount, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );

                for ($num = 1; $num <= $installments; $num++) {
                    $valParc = $valorBase + ($num === 1 ? $diferencaCentavos : 0);
                    $vencParc = calcularVencimentoFaturaSync($date, $closingDay, $dueDay, $num - 1);
                    $stmtParc->execute([$insertedId, $creditCardId, $num, $installments, $vencParc, $valParc]);
                }
            }
        } else {
            $stmtIns = $pdo->prepare(
                "INSERT INTO transactions
                    (user_id, account_id, credit_card_id, investment_id, category_id, type, amount, description, date, due_date, status, urgency)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmtIns->execute([
                $userId, $accountId, $investmentId, $categoryId, $type, $amount,
                $description !== '' ? $description : null, $date, $dueDate, $status, $urgency
            ]);
            $insertedId = (int)$pdo->lastInsertId();
        }

        $results[] = [
            'client_id' => $clientId,
            'server_id' => $insertedId,
            'status'    => 'synced'
        ];
        $syncedCount++;
    }

    $pdo->commit();

    ApiResponse::json([
        'synced_count' => $syncedCount,
        'results'      => $results,
        'timestamp'    => time()
    ], 200, "{$syncedCount} lançamento(s) offline sincronizado(s) com sucesso.");

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("[Droma Sync Error] " . $e->getMessage());
    ApiResponse::error('Erro interno ao sincronizar transações offline: ' . $e->getMessage(), 500);
}
