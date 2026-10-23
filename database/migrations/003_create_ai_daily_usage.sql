-- Migration 003: Tabela de auditoria e rate-limit diário para o Assistente IA
-- Autor: SDD Engine
-- Data: 2026-10-22

CREATE TABLE IF NOT EXISTS ai_daily_usage (
    user_id INT UNSIGNED NOT NULL,
    usage_date DATE NOT NULL,
    requests TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, usage_date),
    CONSTRAINT fk_ai_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
