<?php
/**
 * Endpoint REST: /api/v1/transacoes.php
 * Operações CRUD completas para Transações Financeiras (GET, POST, DELETE)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/ApiAuth.php';
require_once __DIR__ . '/../../src/ApiResponse.php';
require_once __DIR__ . '/../../src/Security.php';

$userId = ApiAuth::authenticate($pdo);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------------------------------------------------------
// 1. GET: Consulta e Listagem de Transações
// ---------------------------------------------------------
if ($method === 'GET') {
    // Consulta por ID único
    if (!empty($_GET['id']) && ctype_digit((string)$_GET['id'])) {
        $txId = (int) $_GET['id'];
        $stmt = $pdo->prepare("
            SELECT t.*, a.name AS account_name, c.name AS category_name, cc.name AS credit_card_name
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            LEFT JOIN categories c ON c.id = t.category_id
            LEFT JOIN credit_cards cc ON cc.id = t.credit_card_id
            WHERE t.id = ? AND t.user_id = ?
        ");
        $stmt->execute([$txId, $userId]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            ApiResponse::notFound('Transação não encontrada ou você não tem permissão para acessá-la.');
        }

        ApiResponse::json($tx);
    }

    // Listagem com Filtros
    $where = ["t.user_id = ?"];
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
            c.name AS category_name,
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
    $input = json_decode(file_get_contents('php://input'), true);
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
    }

    if (!Security::validateAccountOwnership($pdo, $accountId, $userId)) {
        ApiResponse::forbidden('A conta bancária informada não pertence ao usuário autenticado.');
    }

    // Validação de Categoria (se informada)
    if ($categoryId !== null) {
        if (!Security::validateCategoryOwnership($pdo, $categoryId, $userId, $type)) {
            ApiResponse::forbidden('A categoria informada não existe, não pertence ao usuário ou não é do tipo correspondente.');
        }
    }

    // Validação de Cartão de Crédito (se informado)
    if ($creditCardId !== null) {
        if (!Security::validateCreditCardOwnership($pdo, $creditCardId, $userId)) {
            ApiResponse::forbidden('O cartão de crédito informado não pertence ao usuário autenticado.');
        }
    }

    try {
        $stmtInsert = $pdo->prepare("
            INSERT INTO transactions 
                (user_id, account_id, category_id, credit_card_id, type, amount, description, date, due_date, status, urgency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->execute([
            $userId, $accountId, $categoryId, $creditCardId, $type, $amount, $description, $date, $dueDate, $status, $urgency
        ]);
        $newId = (int) $pdo->lastInsertId();

        $stmtBusca = $pdo->prepare("
            SELECT t.*, a.name AS account_name, c.name AS category_name, cc.name AS credit_card_name
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
        error_log("Erro ao criar transação via API: " . $e->getMessage());
        ApiResponse::error('Erro interno ao persistir a transação.', 500);
    }
}

// ---------------------------------------------------------
// 3. DELETE: Exclusão Segura de Transação
// ---------------------------------------------------------
if ($method === 'DELETE') {
    $txId = null;
    if (!empty($_GET['id']) && ctype_digit((string)$_GET['id'])) {
        $txId = (int) $_GET['id'];
    } else {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!empty($body['id']) && is_numeric($body['id'])) {
            $txId = (int) $body['id'];
        }
    }

    if (!$txId) {
        ApiResponse::error('Informe o parâmetro "id" da transação a ser excluída.');
    }

    // Verifica se a transação pertence ao usuário autenticado
    $stmtVerifica = $pdo->prepare("SELECT id FROM transactions WHERE id = ? AND user_id = ?");
    $stmtVerifica->execute([$txId, $userId]);
    if (!$stmtVerifica->fetch()) {
        ApiResponse::notFound('Transação não encontrada ou você não tem permissão para excluí-la.');
    }

    try {
        $pdo->beginTransaction();

        // Remove parcelas vinculadas, se existirem
        $delParc = $pdo->prepare("DELETE FROM installments WHERE transaction_id = ?");
        $delParc->execute([$txId]);

        // Remove a transação
        $delTx = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
        $delTx->execute([$txId, $userId]);

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
