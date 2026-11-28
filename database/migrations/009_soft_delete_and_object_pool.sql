-- ============================================================================
-- Migration 009: Soft Delete com Reciclagem de Registros (Object Pool)
-- Droma Financy — Limites Rígidos: 8 Despesas e 3 Receitas (Máx 11 Slots)
-- ============================================================================

-- 1. Categorias: adiciona coluna de controle de visibilidade (Soft Delete / Pool)
ALTER TABLE `categories` 
ADD COLUMN `visivel` TINYINT(1) NOT NULL DEFAULT 1 AFTER `budget_limit`;

-- Índice para busca ultra-rápida de slots no pool por usuário, tipo e visibilidade
CREATE INDEX `idx_categories_user_pool` ON `categories` (`user_id`, `type`, `visivel`);

-- 2. Transações: torna category_id anulável para desvinculação em reciclagem
ALTER TABLE `transactions` 
MODIFY COLUMN `category_id` INT UNSIGNED NULL;

-- 3. Transações: adiciona snapshot de nome de categoria histórico
ALTER TABLE `transactions` 
ADD COLUMN `category_name` VARCHAR(100) NULL AFTER `category_id`;

-- Preenche retroativamente o snapshot histórico com os nomes atuais das categorias
UPDATE `transactions` t
JOIN `categories` c ON c.id = t.category_id
SET t.category_name = c.name
WHERE t.category_name IS NULL OR t.category_name = '';

-- 4. Transações: adiciona coluna de visibilidade para Soft Delete de lançamentos
ALTER TABLE `transactions` 
ADD COLUMN `visivel` TINYINT(1) NOT NULL DEFAULT 1 AFTER `urgency`;

-- Índice composto para consultas ativas de extrato, relatórios e dashboard
CREATE INDEX `idx_transactions_user_visivel_date` ON `transactions` (`user_id`, `visivel`, `date`);
