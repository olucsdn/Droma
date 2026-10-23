-- Migration 007: Adiciona teto orçamentário mensal para categorias
ALTER TABLE `categories`
ADD COLUMN `budget_limit` DECIMAL(10,2) NULL DEFAULT NULL AFTER `type`;
