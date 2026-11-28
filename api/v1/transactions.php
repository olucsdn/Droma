<?php
/**
 * Endpoint REST: /api/v1/transactions.php
 * Full CRUD operations for Financial Transactions (GET, POST, DELETE)
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

// ---------------------------------------------------------
// 1. GET: Consulta e Listagem de Transações
// ---------------------------------------------------------
if ($method === 'GET') {
    // Consulta por ID único
    if (!empty($_GET['id']) && ctype_digit((string)$_GET['id'])) {
        $txId = (int) $_GET['id'];
        $stmt = $pdo->prepare("
            SELECT t.*, a.name AS account_name, COALESCE(NULLIF(t.category_name, ''), c.name) AS category_name, cc.name AS credit_card_name
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN credit_cards cc ON cc.id = t.credit_card_id
            WHERE t.id = ? AND t.user_id = ? AND t.visivel = 1
        ");
        $stmt->execute([$txId, $userId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            ApiResponse::notFound('Transação não encontrada ou você não tem permissão para acessá-la.');
        }

        ApiResponse::json($tx);
    }

    // Listagem com Filtros
    $where = ["t.user_id = ?", "t.visivel = 1"];
    $params = [$userId];

    if (!empty($_GET['type']) && in_array($_GET['type'], ['income', 'expense'], true)) {
        $where[] = "t.type = ?";
        $params[] = $_GET['type'];
    }

    if (!empty($_GET['status']) && in_array($_GET['status'], ['completed', 'pending', 'cancelled'], true)) {
        $where[] = "t.status = ?";
        $params[] = $_GET['status'];
    }

    if (!empty($_GET['account_id']) && ctype_digit((string)$_GET['account_id'])) {
        $where[] = "t.account_id = ?";
        $params[] = (int) $_GET['account_id'];
    }

    if (!empty($_GET['category_id']) && ctype_digit((string)$_GET['category_id'])) {
        $where[] = "t.category_id = ?";
        $params[] = (int) $_GET['category_id'];
    }

    if (!empty($_GET['credit_card_id']) && ctype_digit((string)$_GET['credit_card_id'])) {
        $where[] = "t.credit_card_id = ?";
        $params[] = (int) $_GET['credit_card_id'];
    }

    if (!empty($_GET['busca'])) {
        $where[] = "t.description LIKE ?";
        $params[] = '%' . trim($_GET['busca']) . '%';
    }

    if (!empty($_GET['data_inicio'])) {
        $where[] = "t.date >= ?";
        $params[] = $_GET['data_inicio'];
    }

    if (!empty($_GET['data_fim'])) {
        $where[] = "t.date <= ?";
        $params[] = $_GET['data_fim'];
    }

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
    $offset = isset($_GET['offset']) && is_numeric($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $whereSql = implode(" AND ", $where);

    // Contagem total para metadados de paginação
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE {$whereSql}");
    $stmtCount->execute($params);
    $totalRegistros = (int) $stmtCount->fetchColumn();

    // Consulta paginada
    $sql = "
        SELECT 
            t.id, 
            t.type, 
            t.amount, 
            t.description, 
            t.date, 
            t.due_date, 
            t.status, 
            t.urgency, 
            t.account_id,
            a.name AS account_name,
            t.category_id,
            COALESCE(NULLIF(t.category_name, ''), c.name) AS category_name,
            t.credit_card_id,
            cc.name AS credit_card_name,
            t.created_at
        FROM transactions t
        LEFT JOIN accounts a ON a.id = t.account_id
        LEFT JOIN categories c ON c.id = t.category_id
        LEFT JOIN credit_cards cc ON cc.id = t.credit_card_id
        WHERE {$whereSql}
        ORDER BY t.date DESC, t.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatação de valores numéricos
    foreach ($transacoes as &$t) {
        $t['id'] = (int) $t['id'];
        $t['amount'] = (float) $t['amount'];
        $t['account_id'] = $t['account_id'] ? (int) $t['account_id'] : null;
        $t['category_id'] = $t['category_id'] ? (int) $t['category_id'] : null;
        $t['credit_card_id'] = $t['credit_card_id'] ? (int) $t['credit_card_id'] : null;
    }
    unset($t);

    ApiResponse::json($transacoes, 200, null, [
        'total'  => $totalRegistros,
        'limit'  => $limit,
        'offset' => $offset
    ]);
}

// ---------------------------------------------------------
// 2. POST: Criação de Nova Transação
// ---------------------------------------------------------
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = !empty($rawInput) ? json_decode($rawInput, true, 8) : null;
    unset($rawInput);
    if (!is_array($input) || empty($input)) {
        $input = $_POST;
    }

    $description = trim($input['description'] ?? '');
    $amountRaw   = $input['amount'] ?? 0;
    $date        = trim($input['date'] ?? date('Y-m-d'));
    $type        = trim($input['type'] ?? 'expense');
    $status      = trim($input['status'] ?? 'completed');
    $urgency     = trim($input['urgency'] ?? 'media');
    $accountId   = !empty($input['account_id']) && is_numeric($input['account_id']) ? (int) $input['account_id'] : 0;
    $categoryId  = !empty($input['category_id']) && is_numeric($input['category_id']) ? (int) $input['category_id'] : null;
    $creditCardId = !empty($input['credit_card_id']) && is_numeric($input['credit_card_id']) ? (int) $input['credit_card_id'] : null;
    $dueDate     = !empty($input['due_date']) ? trim($input['due_date']) : null;

    $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($idempotencyKey !== '' && (!preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $idempotencyKey) || !Security::ensureClientSyncColumn($pdo))) {
        $idempotencyKey = '';
    }
    $clientIdApi = $idempotencyKey !== '' ? $idempotencyKey : null;

    if ($clientIdApi !== null) {
        $stmtDup = $pdo->prepare("
            SELECT t.*, a.name AS account_name, COALESCE(NULLIF(t.category_name, ''), c.name) AS category_name, cc.name AS credit_card_name
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN credit_cards cc ON cc.id = t.credit_card_id
            WHERE t.user_id = ? AND t.client_id = ?
            LIMIT 1
        ");
        $stmtDup->execute([$userId, $clientIdApi]);
        $existente = $stmtDup->fetch(PDO::FETCH_ASSOC);
        if ($existente) {
            ApiResponse::json($existente, 200, null, ['message' => 'Transação já registrada (Idempotency-Key).']);
        }
    }

    // Normaliza valor
    if (is_string($amountRaw)) {
        $amountRaw = str_replace(['R$', ' '], '', $amountRaw);
        if (str_contains($amountRaw, ',') && str_contains($amountRaw, '.')) {
            $amountRaw = str_replace('.', '', $amountRaw);
            $amountRaw = str_replace(',', '.', $amountRaw);
        } elseif (str_contains($amountRaw, ',')) {
            $amountRaw = str_replace(',', '.', $amountRaw);
        }
    }
    $amount = (float) $amountRaw;

    // Validações de Negócio
    if (mb_strlen($description) < 2 || mb_strlen($description) > 255) {
        ApiResponse::error('A descrição da transação deve conter entre 2 e 255 caracteres.');
    }
    if ($amount <= 0) {
        ApiResponse::error('O valor da transação deve ser maior que zero.');
    }
    if (!in_array($type, ['income', 'expense'], true)) {
        ApiResponse::error('O tipo de transação deve ser "income" ou "expense".');
    }
    if (!in_array($status, ['completed', 'pending', 'cancelled'], true)) {
        ApiResponse::error('O status deve ser "completed", "pending" ou "cancelled".');
    }
    if (!in_array($urgency, ['baixa', 'media', 'alta'], true)) {
        ApiResponse::error('A urgência deve ser "baixa", "media" ou "alta".');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        ApiResponse::error('A data da transação deve estar no formato AAAA-MM-DD.');
    }

    // Validação de Conta Obrigatória (ou conta padrão do usuário)
    if ($accountId <= 0) {
        $stmtDefault = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $stmtDefault->execute([$userId]);
        $accountId = (int) $stmtDefault->fetchColumn();

        if ($accountId <= 0) {
            $stmtNewAcc = $pdo->prepare("INSERT INTO accounts (user_id, name, type, initial_balance) VALUES (?, 'Carteira Principal', 'checking', 0.00)");
            $stmtNewAcc->execute([$userId]);
            $accountId = (int) $pdo->lastInsertId();
        }
    }

    if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
        ApiResponse::forbidden('A conta bancária informada não pertence ao usuário autenticado.');
    }

    // Validação e Seleção Automática de Categoria (se não informada)
    if ($categoryId === null || $categoryId <= 0) {
        $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE user_id = ? AND type = ? ORDER BY id ASC LIMIT 1");
        $stmtCat->execute([$userId, $type]);
        $categoryId = (int) $stmtCat->fetchColumn();

        if ($categoryId <= 0) {
            $stmtNewCat = $pdo->prepare("INSERT INTO categories (user_id, name, type) VALUES (?, 'Outros', ?)");
            $stmtNewCat->execute([$userId, $type]);
            $categoryId = (int) $pdo->lastInsertId();
        }
    } else {
        if (!Security::validateCategoryOwnership($pdo, $categoryId, $userId, $type)) {
            ApiResponse::forbidden('A categoria informada não existe, não pertence ao usuário ou não é do tipo correspondente.');
        }
    }

    // Receitas nunca são vinculadas a cartões de crédito e possuem urgência padrão normalizada
    if ($type === 'income') {
        $creditCardId = null;
        $urgency = 'media';
    }

    // Validação de Cartão de Crédito (se informado para despesa)
    if ($creditCardId !== null) {
        if (!Security::validateCreditCardOwnership($pdo, $creditCardId, $userId)) {
            ApiResponse::forbidden('O cartão de crédito informado não pertence ao usuário autenticado.');
        }
    }

    try {
        // Snapshot imutável da categoria para proteger histórico contra reciclagem de slots
        $stmtCatName = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $stmtCatName->execute([$categoryId]);
        $catSnapshot = (string)($stmtCatName->fetchColumn() ?: '');

        $stmtInsert = $pdo->prepare("
            INSERT INTO transactions 
                (user_id, account_id, category_id, category_name, credit_card_id, type, amount, description, date, due_date, status, urgency, client_id, visivel)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtInsert->execute([
            $userId, $accountId, $categoryId, $catSnapshot, $creditCardId, $type, $amount, $description, $date, $dueDate, $status, $urgency, $clientIdApi
        ]);
        $newId = (int) $pdo->lastInsertId();

        $stmtBusca = $pdo->prepare("
            SELECT t.*, a.name AS account_name, COALESCE(NULLIF(t.category_name, ''), c.name) AS category_name, cc.name AS credit_card_name
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN credit_cards cc ON cc.id = t.credit_card_id
            WHERE t.id = ?
        ");
        $stmtBusca->execute([$newId]);
        $novoRegistro = $stmtBusca->fetch(PDO::FETCH_ASSOC);

        ApiResponse::json($novoRegistro, 201, null, ['message' => 'Transação criada com sucesso!']);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000' && $clientIdApi !== null) {
            $stmtDup = $pdo->prepare("
                SELECT t.*, a.name AS account_name, COALESCE(NULLIF(t.category_name, ''), c.name) AS category_name, cc.name AS credit_card_name
                FROM transactions t
                LEFT JOIN accounts a ON a.id = t.account_id
                LEFT JOIN categories c ON c.id = t.category_id
                LEFT JOIN credit_cards cc ON cc.id = t.credit_card_id
                WHERE t.user_id = ? AND t.client_id = ?
                LIMIT 1
            ");
            $stmtDup->execute([$userId, $clientIdApi]);
            $existente = $stmtDup->fetch(PDO::FETCH_ASSOC);
            if ($existente) {
                ApiResponse::json($existente, 200, null, ['message' => 'Transação já registrada (Idempotency-Key).']);
            }
        }
        error_log("Erro ao criar transação via API: " . $e->getMessage());
        ApiResponse::error('Erro interno ao persistir a transação.', 500);
    }
}

// ---------------------------------------------------------
// 3. DELETE: Exclusão Segura de Transação (Soft Delete)
// ---------------------------------------------------------
if ($method === 'DELETE') {
    $txId = null;
    if (!empty($_GET['id']) && ctype_digit((string)$_GET['id'])) {
        $txId = (int) $_GET['id'];
    } else {
        $rawDelete = file_get_contents('php://input');
        $body = !empty($rawDelete) ? json_decode($rawDelete, true, 8) : null;
        unset($rawDelete);
        if (!empty($body['id']) && is_numeric($body['id'])) {
            $txId = (int) $body['id'];
        }
    }

    if (!$txId) {
        ApiResponse::error('Informe o parâmetro "id" da transação a ser excluída.');
    }

    // Verifica se a transação existe, pertence ao usuário e está ativa
    $stmtVerifica = $pdo->prepare("SELECT id, transfer_id, goal_id, type, amount FROM transactions WHERE id = ? AND user_id = ? AND visivel = 1");
    $stmtVerifica->execute([$txId, $userId]);
    $tx = $stmtVerifica->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        ApiResponse::notFound('Transação não encontrada ou já excluída.');
    }

    try {
        $pdo->beginTransaction();

        $transferId = $tx['transfer_id'] ?? null;
        $goalId     = !empty($tx['goal_id']) ? (int)$tx['goal_id'] : null;
        $txAmount   = (float)$tx['amount'];
        $txType     = $tx['type'];

        if (!empty($transferId)) {
            $delTx = $pdo->prepare("UPDATE transactions SET visivel = 0 WHERE transfer_id = ? AND user_id = ?");
            $delTx->execute([$transferId, $userId]);
        } else {
            // Soft delete em conformidade com o padrão do sistema (preserva integridade e histórico)
            $delTx = $pdo->prepare("UPDATE transactions SET visivel = 0 WHERE id = ? AND user_id = ?");
            $delTx->execute([$txId, $userId]);
        }

        // Cancela parcelas pendentes se for compra parcelada
        $stmtParc = $pdo->prepare("UPDATE installments SET status = 'cancelled' WHERE transaction_id = ? AND status = 'pending'");
        $stmtParc->execute([$txId]);

        // Ajusta a meta financeira se aplicável
        if ($goalId !== null) {
            if ($txType === 'expense') {
                $stmtGoal = $pdo->prepare("UPDATE goals SET current_amount = GREATEST(0, current_amount - ?) WHERE id = ? AND user_id = ?");
                $stmtGoal->execute([$txAmount, $goalId, $userId]);
            } elseif ($txType === 'income') {
                $stmtGoal = $pdo->prepare("UPDATE goals SET current_amount = current_amount + ? WHERE id = ? AND user_id = ?");
                $stmtGoal->execute([$txAmount, $goalId, $userId]);
            }
        }

        $pdo->commit();

        ApiResponse::json([
            'message' => 'Transação excluída com sucesso.',
            'id'      => $txId
        ], 200);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Erro ao excluir transação via API: " . $e->getMessage());
        ApiResponse::error('Erro interno ao excluir a transação.', 500);
    }
}

ApiResponse::error('Método HTTP não suportado neste endpoint. Métodos aceitos: GET, POST, DELETE.', 405);
