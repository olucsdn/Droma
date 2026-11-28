-- Migration 006: Criação do Módulo de Investimentos e Vínculo com Transações
-- Metodologia SDD - Fase 7

CREATE TABLE IF NOT EXISTS investments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('renda_fixa', 'acoes', 'fiis', 'cripto', 'fundos', 'outros') NOT NULL DEFAULT 'renda_fixa',
    initial_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    start_date DATE NOT NULL,
    due_date DATE NULL,
    status ENUM('active', 'redeemed', 'cancelled') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_investments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_investments_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX idx_investments_user (user_id),
    INDEX idx_investments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adiciona investment_id na tabela transactions
ALTER TABLE transactions 
ADD COLUMN investment_id INT UNSIGNED NULL AFTER credit_card_id,
ADD CONSTRAINT fk_transactions_investment 
    FOREIGN KEY (investment_id) REFERENCES investments(id) ON DELETE SET NULL;
