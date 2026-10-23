-- Migration 004: Adiciona suporte a API Token para integração RESTful
-- Autor: SDD Engine
-- Data: 2026-10-22

ALTER TABLE users 
ADD COLUMN api_token VARCHAR(64) NULL UNIQUE AFTER password;
