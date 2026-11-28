-- Migration: 001_alter_accounts_default_type.sql
-- Objetivo: Definir valor default 'checking' para a coluna type da tabela accounts
-- Metodologia SDD - Tarefa 1.5

USE wert;

-- UP
ALTER TABLE accounts
MODIFY COLUMN type ENUM('checking','savings','cash','investment') NOT NULL DEFAULT 'checking';

-- DOWN (reversão caso necessário)
-- ALTER TABLE accounts MODIFY COLUMN type ENUM('checking','savings','cash','investment') NOT NULL;
