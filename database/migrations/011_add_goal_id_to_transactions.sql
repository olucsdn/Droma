-- Migration 011: Adiciona relacionamento entre metas financeiras e transações (goal_id)
-- 100% não-destrutivo: transações existentes permanecem intactas com goal_id = NULL
ALTER TABLE `transactions` 
ADD COLUMN `goal_id` int unsigned DEFAULT NULL AFTER `investment_id`,
ADD INDEX `idx_transactions_goal_id` (`goal_id`),
ADD CONSTRAINT `fk_transactions_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE SET NULL;
