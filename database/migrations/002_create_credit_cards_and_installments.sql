-- Migration: 002_create_credit_cards_and_installments.sql
-- Objetivo: Criar tabelas de cartões de crédito, parcelamentos e lançamentos recorrentes
-- Metodologia SDD - Tarefa 3.1

USE wert;

-- 1. Tabela de Cartões de Crédito
CREATE TABLE IF NOT EXISTS credit_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    limit_amount DECIMAL(12,2) NOT NULL DEFAULT '0.00',
    closing_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    due_day TINYINT UNSIGNED NOT NULL DEFAULT 10,
    color VARCHAR(20) NOT NULL DEFAULT '#D4AF6C',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_credit_cards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Vínculo de Transações ao Cartão de Crédito
SET @col_exists := (
    SELECT COUNT(*) 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = 'wert' 
      AND TABLE_NAME = 'transactions' 
      AND COLUMN_NAME = 'credit_card_id'
);

SET @sql := IF(@col_exists = 0, 
    'ALTER TABLE transactions ADD COLUMN credit_card_id INT UNSIGNED NULL AFTER account_id, ADD CONSTRAINT fk_transactions_credit_card FOREIGN KEY (credit_card_id) REFERENCES credit_cards(id) ON DELETE SET NULL;', 
    'SELECT "Coluna credit_card_id ja existe";'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Tabela de Parcelas (Installments)
CREATE TABLE IF NOT EXISTS installments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    credit_card_id INT UNSIGNED NOT NULL,
    installment_number TINYINT UNSIGNED NOT NULL,
    total_installments TINYINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending', 'completed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_installments_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_installments_card FOREIGN KEY (credit_card_id) REFERENCES credit_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabela de Lançamentos Recorrentes
CREATE TABLE IF NOT EXISTS recurring_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NULL,
    frequency ENUM('weekly', 'monthly', 'yearly') NOT NULL DEFAULT 'monthly',
    day_of_month TINYINT UNSIGNED NOT NULL DEFAULT 1,
    next_date DATE NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recurring_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_recurring_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
