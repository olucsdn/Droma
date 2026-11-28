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
require_once __DIR__ . '/../../src/CategoryService.php';

$userId = ApiAuth::authenticate($pdo);
CategoryService::ensureColumns($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    ApiResponse::error('Método não permitido. Utilize POST para sincronizar transações offline.', 405);
}

$maxSyncPayload = 2 * 1024 * 1024; // Limite de 2MB para payload de sincronização
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > $maxSyncPayload) {
    ApiResponse::error('Payload de sincronização excede o limite máximo de 2MB.', 413);
}

$stream = fopen('php://input', 'rb');
$raw = $stream ? stream_get_contents($stream, $maxSyncPayload + 1) : false;
if ($stream) {
    fclose($stream);
}

if ($raw === false || strlen($raw) > $maxSyncPayload) {
    unset($raw);
    ApiResponse::error('Corpo da requisição inválido ou excede o limite de 2MB.', 413);
}

try {
    // Limite de profundidade (8) e tratamento estrito de JsonException
    $payload = json_decode((string)$raw, true, 8, JSON_THROW_ON_ERROR);
    unset($raw); // Desaloca imediatamente a string bruta da RAM
} catch (JsonException) {
    unset($raw);
    ApiResponse::error('Corpo da requisição inválido. Esperado payload JSON estruturado.', 400);
}

if (!is_array($payload)) {
    ApiResponse::error('Corpo da requisição inválido. Esperado payload JSON.', 400);
}

$transactions = $payload['transactions'] ?? $payload;
if (!is_array($transactions) || empty($transactions)) {
    ApiResponse::json(['synced_count' => 0, 'results' => []], 200, 'Nenhum lançamento pendente para sincronização.');
}

if (count($transactions) > 250) {
    ApiResponse::error('O lote de sincronização excede o limite de 250 itens por envio. Fracione a sincronização.', 422);
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

// Validação estrita de integridade de schema: se a migração falhar, aborta a sincronização para impedir duplicações (Fail-Secure)
if (!Security::ensureClientSyncColumn($pdo)) {
    ApiResponse::error('Serviço de sincronização temporariamente indisponível: o schema de transações requer atualização de idempotência.', 503);
}

$pdo->beginTransaction();

try {
    // Busca conta e categoria padrão do usuário para fallback seguro
    $stmtAccDef = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $stmtAccDef->execute([$userId]);
    $defaultAccountId = (int)$stmtAccDef->fetchColumn();

    // Se o usuário não tiver conta cadastrada, cria a Carteira Principal para evitar falha de FK
    if ($defaultAccountId === 0) {
        $insAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Carteira Principal', 'checking', 0.00)");
        $insAcc->execute([$userId]);
        $defaultAccountId = (int)$pdo->lastInsertId();
    }

    $stmtCatDefInc = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = 'income' ORDER BY id ASC LIMIT 1");
    $stmtCatDefInc->execute([$userId]);
    $defaultCatIncome = (int)$stmtCatDefInc->fetchColumn();

    $stmtCatDefExp = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = 'expense' ORDER BY id ASC LIMIT 1");
    $stmtCatDefExp->execute([$userId]);
    $defaultCatExpense = (int)$stmtCatDefExp->fetchColumn();

    // Se faltarem categorias padrão, cria categorias essenciais automaticamente
    if ($defaultCatExpense === 0 || $defaultCatIncome === 0) {
        $defaultsCat = [
            ['Alimentação', 'expense'],
            ['Transporte', 'expense'],
            ['Moradia', 'expense'],
            ['Outros', 'expense'],
            ['Salário', 'income'],
            ['Investimentos', 'income']
        ];
        $insCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)");
        foreach ($defaultsCat as [$cName, $cType]) {
            try { $insCat->execute([$userId, $cName, $cType]); } catch (Throwable $e) {}
        }
        if ($defaultCatIncome === 0) {
            $stmtCatDefInc->execute([$userId]);
            $defaultCatIncome = (int)$stmtCatDefInc->fetchColumn();
        }
        if ($defaultCatExpense === 0) {
            $stmtCatDefExp->execute([$userId]);
            $defaultCatExpense = (int)$stmtCatDefExp->fetchColumn();
        }
    }

    foreach ($transactions as $item) {
        if (!is_array($item)) continue;

        $clientIdRaw = trim((string)($item['client_id'] ?? ''));
        $clientId = $clientIdRaw !== '' ? $clientIdRaw : null;
        $clientEcho = $clientId ?? ('off_' . bin2hex(random_bytes(6)));

        // Idempotência prévia: se client_id já foi sincronizado anteriormente, recupera server_id sem duplicar
        if ($clientId !== null) {
            $stmtCheck = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND client_id = ? LIMIT 1");
            $stmtCheck->execute([$userId, $clientId]);
            $existingId = $stmtCheck->fetchColumn();
            if ($existingId) {
                $results[] = [
                    'client_id' => $clientId,
                    'server_id' => (int)$existingId,
                    'status'    => 'already_synced'
                ];
                continue;
            }
        }

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
                'client_id' => $clientEcho,
                'status'    => 'error',
                'error'     => 'Valor monetário inválido ou menor/igual a zero.'
            ];
            continue;
        }

        $description = Security::sanitizeText($item['description'] ?? '', 255, 'Lançamento Sincronizado');

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

        // Snapshot do nome da categoria para proteção do histórico
        $stmtCatName = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $stmtCatName->execute([$categoryId]);
        $catSnapshot = (string)($stmtCatName->fetchColumn() ?: '');

        // Savepoint por item para resiliência transacional e absorção de colisões concorrentes
        $spName = 'sp_' . bin2hex(random_bytes(4));
        $pdo->exec("SAVEPOINT {$spName}");

        try {
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
                            (user_id, account_id, credit_card_id, client_id, investment_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, visivel)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?, 1)"
                    );
                    $stmtIns->execute([
                        $userId, $accountId, $creditCardId, $clientId, $investmentId, $categoryId, $catSnapshot, $amount,
                        $description !== '' ? $description : null, $date, $vencPrimeiraFatura, $urgency
                    ]);
                    $insertedId = (int)$pdo->lastInsertId();
                } else {
                    $descPrincipal = $description !== '' ? "{$description} (Parcelado em {$installments}x)" : "Compra parcelada em {$installments}x";
                    $stmtIns = $pdo->prepare(
                        "INSERT INTO transactions
                            (user_id, account_id, credit_card_id, client_id, investment_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, visivel)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'expense', ?, ?, ?, ?, 'pending', ?, 1)"
                    );
                    $stmtIns->execute([
                        $userId, $accountId, $creditCardId, $clientId, $investmentId, $categoryId, $catSnapshot, $amount,
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
                        (user_id, account_id, credit_card_id, client_id, investment_id, category_id, category_name, type, amount, description, date, due_date, status, urgency, visivel)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
                );
                $stmtIns->execute([
                    $userId, $accountId, $clientId, $investmentId, $categoryId, $catSnapshot, $type, $amount,
                    $description !== '' ? $description : null, $date, $dueDate, $status, $urgency
                ]);
                $insertedId = (int)$pdo->lastInsertId();
            }

            $pdo->exec("RELEASE SAVEPOINT {$spName}");

            $results[] = [
                'client_id' => $clientEcho,
                'server_id' => $insertedId,
                'status'    => 'synced'
            ];
            $syncedCount++;
        } catch (Throwable $e) {
            $pdo->exec("ROLLBACK TO SAVEPOINT {$spName}");

            // Em caso de colisão concorrente por UNIQUE (user_id, client_id), recupera a transação existente
            if ($clientId !== null) {
                $stmtCheck = $pdo->prepare("SELECT id FROM transactions WHERE user_id = ? AND client_id = ? LIMIT 1");
                $stmtCheck->execute([$userId, $clientId]);
                $existingId = $stmtCheck->fetchColumn();
                if ($existingId) {
                    $results[] = [
                        'client_id' => $clientId,
                        'server_id' => (int)$existingId,
                        'status'    => 'already_synced'
                    ];
                    continue;
                }
            }

            error_log("[Droma Sync Item Error] " . $e->getMessage());
            $results[] = [
                'client_id' => $clientEcho,
                'status'    => 'error',
                'error'     => 'Erro interno ao processar transação offline.'
            ];
        }
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
    ApiResponse::error('Ocorreu um erro interno ao processar o lote de sincronização offline. Tente novamente mais tarde.', 500);
}
