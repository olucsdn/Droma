-- Migration 005: Vinculação de Cartão de Crédito à Conta Bancária (Hierarquia Classe-Subclasse)
-- Metodologia SDD - Fase 7

-- 1. Adiciona coluna temporariamente como NULL
ALTER TABLE credit_cards 
ADD COLUMN account_id INT UNSIGNED NULL AFTER user_id;

-- 2. Vincula cartões existentes à primeira conta do respectivo usuário
UPDATE credit_cards c
JOIN (
    SELECT user_id, MIN(id) AS first_account_id 
    FROM accounts 
    GROUP BY user_id
) a ON a.user_id = c.user_id
SET c.account_id = a.first_account_id
WHERE c.account_id IS NULL;

-- 3. Define a coluna como NOT NULL, adiciona Foreign Key e restrição de unicidade (1 cartão por conta)
ALTER TABLE credit_cards 
MODIFY COLUMN account_id INT UNSIGNED NOT NULL,
ADD CONSTRAINT fk_credit_cards_account 
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
ADD CONSTRAINT uq_credit_cards_account 
    UNIQUE (account_id);
