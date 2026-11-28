-- Migration 008: Adiciona registro de aceite dos Termos de Uso e Política de Privacidade (LGPD)
-- Autor: SDD Engine
-- Data: 2026-11-02

ALTER TABLE users 
ADD COLUMN terms_accepted_at TIMESTAMP NULL DEFAULT NULL AFTER email_verified_at;
