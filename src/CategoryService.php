<?php
/**
 * CategoryService.php — Gerenciador de Categorias e Object Pool
 * Projeto Droma Financy
 *
 * Implementa padrão Soft Delete e Reciclagem de Slots (Object Pool):
 * - Máximo de 8 categorias de despesa e 3 categorias de receita por usuário (total 11)
 * - Exclusão lógica com visivel = 0 (nunca executa DELETE)
 * - Criação com reaproveitamento de slots inativos para não inflar AUTO_INCREMENT
 * - Blindagem do histórico de transações via snapshot imutável em transactions.category_name
 */
final class CategoryService {
    public const MAX_EXPENSE_CATEGORIES = 8;
    public const MAX_INCOME_CATEGORIES  = 3;
    public const MIN_CATEGORIES_PER_TYPE = 1;
    public const SYSTEM_INCOME_CATEGORY  = 'Sistema (Entrada)';
    public const SYSTEM_EXPENSE_CATEGORY = 'Sistema (Saída)';

    /**
     * Verifica se o nome pertence a uma categoria reservada do sistema
     */
    public static function isSystemCategoryName(string $name): bool {
        $norm = mb_strtolower(trim($name), 'UTF-8');
        return in_array($norm, [
            'transferência',
            'transferencia',
            'pagamento de cartão',
            'pagamento de cartao',
            'sistema (entrada)',
            'sistema (saída)',
            'sistema (saida)',
            'sistema',
        ], true);
    }

    /**
     * Auto-migração resiliente: assegura que as colunas visivel e category_name existam
     */
    public static function ensureColumns(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            // 1. Coluna de controle de visibilidade em categories
            $pdo->exec("ALTER TABLE `categories` ADD COLUMN `visivel` TINYINT(1) NOT NULL DEFAULT 1 AFTER `budget_limit`");
        } catch (Throwable) {}

        try {
            // 2. Coluna anulável em transactions para permitir desvinculação em reciclagem
            $pdo->exec("ALTER TABLE `transactions` MODIFY COLUMN `category_id` INT UNSIGNED NULL");
        } catch (Throwable) {}

        try {
            // 3. Coluna de snapshot de nome de categoria histórico em transactions
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `category_name` VARCHAR(100) NULL AFTER `category_id`");
        } catch (Throwable) {}

        try {
            // 4. Preenche retroativamente o snapshot histórico caso vazio
            $pdo->exec("
                UPDATE `transactions` t
                JOIN `categories` c ON c.id = t.category_id
                SET t.category_name = c.name
                WHERE t.category_name IS NULL OR t.category_name = ''
            ");
        } catch (Throwable) {}

        try {
            // 5. Coluna de visibilidade (Soft Delete) em transactions
            $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `visivel` TINYINT(1) NOT NULL DEFAULT 1 AFTER `urgency`");
        } catch (Throwable) {}

        try {
            // 6. Coluna que marca categorias protegidas do sistema (não contam no limite e não são editáveis/excluíveis)
            $pdo->exec("ALTER TABLE `categories` ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `visivel`");
        } catch (Throwable) {}

        try {
            // 7. Garante marcação de sistema em categorias técnicas já existentes
            $pdo->exec("
                UPDATE `categories`
                SET `is_system` = 1
                WHERE `is_system` = 0
                  AND `name` IN ('Transferência', 'Pagamento de Cartão', 'Sistema (Entrada)', 'Sistema (Saída)')
            ");
        } catch (Throwable) {}

        $checked = true;
    }

    /**
     * Garante a existência da categoria protegida do sistema para o tipo informado
     *
     * @return array{id: int, name: string}
     */
    public static function ensureSystemCategory(PDO $pdo, int $userId, string $tipo): array {
        self::ensureColumns($pdo);
        $nome = ($tipo === 'income') ? self::SYSTEM_INCOME_CATEGORY : self::SYSTEM_EXPENSE_CATEGORY;
        $id = Security::getOrCreateCategory($pdo, $userId, $nome, $tipo, true);
        return ['id' => $id, 'name' => $nome];
    }

    /**
     * Garante as categorias padrão de sistema (entrada e saída) para o usuário
     */
    public static function ensureSystemCategories(PDO $pdo, int $userId): void {
        self::ensureSystemCategory($pdo, $userId, 'income');
        self::ensureSystemCategory($pdo, $userId, 'expense');
    }

    /**
     * Criação de categoria com reaproveitamento de slots invisíveis (Object Pool)
     *
     * @return array{sucesso: bool, erro?: string, id?: int, acao?: string, nome?: string}
     */
    public static function criarOuReciclar(
        PDO $pdo, 
        int $userId, 
        string $nome, 
        string $tipo, 
        ?float $budgetLimit = null
    ): array {
        self::ensureColumns($pdo);

        $nome = trim($nome);
        $maxPermitido = ($tipo === 'expense') ? self::MAX_EXPENSE_CATEGORIES : self::MAX_INCOME_CATEGORIES;
        $tipoLabel    = ($tipo === 'income') ? 'receita' : 'despesa';

        if (empty($nome) || mb_strlen($nome) > 100) {
            return ['sucesso' => false, 'erro' => 'Informe um nome de categoria válido com até 100 caracteres.'];
        }

        if (self::isSystemCategoryName($nome)) {
            return ['sucesso' => false, 'erro' => "O nome '{$nome}' é reservado para uso interno do sistema."];
        }

        if (!in_array($tipo, ['income', 'expense'], true)) {
            return ['sucesso' => false, 'erro' => 'Tipo de categoria inválido.'];
        }

        $budgetLimit = ($tipo === 'expense' && $budgetLimit !== null && $budgetLimit > 0) ? round($budgetLimit, 2) : null;

        $pdo->beginTransaction();
        try {
            // 1. Bloqueia se já existir categoria ATIVA com esse mesmo nome e tipo
            $stmtAtiva = $pdo->prepare("
                SELECT id FROM categories 
                WHERE user_id = ? AND type = ? AND name = ? AND visivel = 1
                LIMIT 1 FOR UPDATE
            ");
            $stmtAtiva->execute([$userId, $tipo, $nome]);
            if ($stmtAtiva->fetch()) {
                $pdo->rollBack();
                return ['sucesso' => false, 'erro' => "Já existe uma categoria ativa de {$tipoLabel} chamada '{$nome}'."];
            }

            // 2. Verifica se já existe um slot INVISÍVEL com esse MESMO nome (Reativação Simples)
            $stmtReativar = $pdo->prepare("
                SELECT id FROM categories 
                WHERE user_id = ? AND type = ? AND name = ? AND visivel = 0 AND is_system = 0
                LIMIT 1 FOR UPDATE
            ");
            $stmtReativar->execute([$userId, $tipo, $nome]);
            $slotMesmoNome = $stmtReativar->fetch(PDO::FETCH_ASSOC);

            if ($slotMesmoNome) {
                $slotId = (int)$slotMesmoNome['id'];
                $stmtUpd = $pdo->prepare("
                    UPDATE categories 
                    SET visivel = 1, budget_limit = ? 
                    WHERE id = ? AND user_id = ?
                ");
                $stmtUpd->execute([$budgetLimit, $slotId, $userId]);
                $pdo->commit();
                return [
                    'sucesso' => true, 
                    'id'      => $slotId, 
                    'acao'    => 'reativado',
                    'nome'    => $nome
                ];
            }

            // 3. CENÁRIO A: Procura QUALQUER slot invisível do mesmo tipo para RECICLAGEM
            $stmtPool = $pdo->prepare("
                SELECT id, name FROM categories 
                WHERE user_id = ? AND type = ? AND visivel = 0 AND is_system = 0 
                ORDER BY id ASC 
                LIMIT 1 FOR UPDATE
            ");
            $stmtPool->execute([$userId, $tipo]);
            $slotLivre = $stmtPool->fetch(PDO::FETCH_ASSOC);

            if ($slotLivre) {
                $slotId = (int)$slotLivre['id'];
                $nomeAntigo = (string)$slotLivre['name'];

                // BLINDAGEM DO HISTÓRICO:
                // a) Assegura que todos os lançamentos passados tenham o snapshot do nome gravado
                $stmtSnap = $pdo->prepare("
                    UPDATE transactions 
                    SET category_name = ? 
                    WHERE category_id = ? AND user_id = ? AND (category_name IS NULL OR category_name = '')
                ");
                $stmtSnap->execute([$nomeAntigo, $slotId, $userId]);

                // b) Desvincula o category_id das transações antigas para não poluírem o novo teto de gastos
                $stmtUnlink = $pdo->prepare("
                    UPDATE transactions 
                    SET category_id = NULL 
                    WHERE category_id = ? AND user_id = ?
                ");
                $stmtUnlink->execute([$slotId, $userId]);

                // c) Recicla o registro existente no banco com o novo nome e dados
                $stmtReciclar = $pdo->prepare("
                    UPDATE categories 
                    SET name = ?, budget_limit = ?, visivel = 1 
                    WHERE id = ? AND user_id = ?
                ");
                $stmtReciclar->execute([$nome, $budgetLimit, $slotId, $userId]);

                $pdo->commit();
                return [
                    'sucesso' => true, 
                    'id'      => $slotId, 
                    'acao'    => 'reciclado',
                    'nome'    => $nome
                ];
            }

            // 4. CENÁRIO B & C: Não há slot livre invisível. Verifica quantidade de ativas do usuário
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*) FROM categories 
                WHERE user_id = ? AND type = ? AND visivel = 1 AND is_system = 0
            ");
            $stmtCount->execute([$userId, $tipo]);
            $totalAtivas = (int)$stmtCount->fetchColumn();

            // Cenário C: Todos os slots estão em uso (8 ou 3)
            if ($totalAtivas >= $maxPermitido) {
                $pdo->rollBack();
                return [
                    'sucesso' => false, 
                    'erro'    => "Limite atingido: você pode cadastrar no máximo {$maxPermitido} categorias de {$tipoLabel} ativas."
                ];
            }

            // Cenário B: Há espaço para novo slot (INSERT inicial)
            $stmtIns = $pdo->prepare("
                INSERT INTO categories (user_id, name, type, budget_limit, visivel) 
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmtIns->execute([$userId, $nome, $tipo, $budgetLimit]);
            $novoId = (int)$pdo->lastInsertId();

            $pdo->commit();
            return [
                'sucesso' => true, 
                'id'      => $novoId, 
                'acao'    => 'inserido',
                'nome'    => $nome
            ];

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("[CategoryService::criarOuReciclar Error] " . $e->getMessage());
            return ['sucesso' => false, 'erro' => 'Erro interno ao processar a categoria. Tente novamente.'];
        }
    }

    /**
     * Edição de categoria ativa (UPDATE por ID)
     *
     * @return array{sucesso: bool, erro?: string, nome?: string}
     */
    public static function editar(
        PDO $pdo, 
        int $userId, 
        int $categoryId, 
        string $nome, 
        ?float $budgetLimit = null
    ): array {
        self::ensureColumns($pdo);

        $nome = trim($nome);
        if ($categoryId <= 0 || empty($nome) || mb_strlen($nome) > 100) {
            return ['sucesso' => false, 'erro' => 'Informe um nome de categoria válido.'];
        }

        if (self::isSystemCategoryName($nome)) {
            return ['sucesso' => false, 'erro' => "O nome '{$nome}' é reservado para uso interno do sistema."];
        }

        $stmt = $pdo->prepare("SELECT type, name, is_system FROM categories WHERE id = ? AND user_id = ? AND visivel = 1");
        $stmt->execute([$categoryId, $userId]);
        $catExistente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$catExistente) {
            return ['sucesso' => false, 'erro' => 'Categoria não encontrada ou não autorizada.'];
        }

        if ((int)($catExistente['is_system'] ?? 0) === 1 || self::isSystemCategoryName((string)$catExistente['name'])) {
            return ['sucesso' => false, 'erro' => 'Categorias do sistema são protegidas e não podem ser editadas.'];
        }

        $tipo = (string)$catExistente['type'];

        $tipoLabel = ($tipo === 'income') ? 'receita' : 'despesa';
        $budgetLimit = ($tipo === 'expense' && $budgetLimit !== null && $budgetLimit > 0) ? round($budgetLimit, 2) : null;

        // Verifica unicidade com outras categorias ativas
        $stmtCheck = $pdo->prepare("
            SELECT id FROM categories 
            WHERE user_id = ? AND name = ? AND type = ? AND id != ? AND visivel = 1
        ");
        $stmtCheck->execute([$userId, $nome, $tipo, $categoryId]);
        if ($stmtCheck->fetch()) {
            return ['sucesso' => false, 'erro' => "Você já possui outra categoria ativa de {$tipoLabel} chamada '{$nome}'."];
        }

        try {
            $stmtUpd = $pdo->prepare("
                UPDATE categories 
                SET name = ?, budget_limit = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmtUpd->execute([$nome, $budgetLimit, $categoryId, $userId]);

            // Atualiza também o snapshot das transações vinculadas a este ID
            $stmtSnap = $pdo->prepare("UPDATE transactions SET category_name = ? WHERE category_id = ? AND user_id = ?");
            $stmtSnap->execute([$nome, $categoryId, $userId]);

            return ['sucesso' => true, 'nome' => $nome];
        } catch (Throwable $e) {
            error_log("[CategoryService::editar Error] " . $e->getMessage());
            return ['sucesso' => false, 'erro' => 'Erro ao salvar a alteração da categoria.'];
        }
    }

    /**
     * Exclusão Lógica de Categoria (Soft Delete — visivel = 0)
     * NUNCA executa comando DELETE. Permite que o slot fique livre para reciclagem.
     *
     * @return array{sucesso: bool, erro?: string, mensagem?: string}
     */
    public static function excluir(PDO $pdo, int $userId, int $categoryId): array {
        self::ensureColumns($pdo);

        $stmt = $pdo->prepare("SELECT id, type, name, is_system FROM categories WHERE id = ? AND user_id = ? AND visivel = 1");
        $stmt->execute([$categoryId, $userId]);
        $cat = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cat) {
            return ['sucesso' => false, 'erro' => 'Categoria não encontrada ou já excluída.'];
        }

        if ((int)($cat['is_system'] ?? 0) === 1 || self::isSystemCategoryName((string)$cat['name'])) {
            return ['sucesso' => false, 'erro' => 'Categorias do sistema são protegidas e não podem ser excluídas.'];
        }

        $tipoCat = (string)$cat['type'];
        $tipoLabel = ($tipoCat === 'income') ? 'receita' : 'despesa';

        // Regra de Negócio: Garante no mínimo 1 categoria ativa de usuário por tipo
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE user_id = ? AND type = ? AND visivel = 1 AND is_system = 0");
        $stmtCount->execute([$userId, $tipoCat]);
        $qtdRestante = (int)$stmtCount->fetchColumn();

        if ($qtdRestante <= self::MIN_CATEGORIES_PER_TYPE) {
            return [
                'sucesso' => false, 
                'erro' => "Não é permitido excluir esta categoria. O sistema exige no mínimo 1 categoria de {$tipoLabel} ativa."
            ];
        }

        try {
            // Assegura snapshot de nome para qualquer transação vinculada antes de ocultar
            $stmtSnap = $pdo->prepare("
                UPDATE transactions 
                SET category_name = ? 
                WHERE category_id = ? AND user_id = ? AND (category_name IS NULL OR category_name = '')
            ");
            $stmtSnap->execute([$cat['name'], $categoryId, $userId]);

            // Soft Delete puro: altera visivel para 0
            $upd = $pdo->prepare("UPDATE categories SET visivel = 0 WHERE id = ? AND user_id = ?");
            $upd->execute([$categoryId, $userId]);

            return [
                'sucesso' => true, 
                'mensagem' => "Categoria '{$cat['name']}' excluída com sucesso (slot liberado para reaproveitamento)."
            ];
        } catch (Throwable $e) {
            error_log("[CategoryService::excluir Error] " . $e->getMessage());
            return ['sucesso' => false, 'erro' => 'Erro ao processar a exclusão da categoria.'];
        }
    }

    /**
     * Lista apenas categorias ativas do usuário (visivel = 1)
     */
    public static function listarAtivas(PDO $pdo, int $userId, ?string $tipo = null): array {
        self::ensureColumns($pdo);

        if ($tipo !== null && in_array($tipo, ['income', 'expense'], true)) {
            $stmt = $pdo->prepare("
                SELECT id, name, type, budget_limit 
                FROM categories 
                WHERE user_id = ? AND type = ? AND visivel = 1 
                ORDER BY name ASC
            ");
            $stmt->execute([$userId, $tipo]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, name, type, budget_limit 
                FROM categories 
                WHERE user_id = ? AND visivel = 1 
                ORDER BY name ASC
            ");
            $stmt->execute([$userId]);
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
