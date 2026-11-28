-- Migration 010: Adiciona identificador único de transferência interna (transfer_id)
-- 100% não-destrutivo: transações existentes permanecem intactas com transfer_id = NULL
ALTER TABLE `transactions` 
ADD COLUMN `transfer_id` varchar(36) DEFAULT NULL AFTER `credit_card_id`,
ADD INDEX `idx_transactions_transfer_id` (`transfer_id`),
ADD INDEX `idx_transactions_user_transfer_visivel` (`user_id`, `transfer_id`, `visivel`);
