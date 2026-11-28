<?php
/**
 * Legacy redirect: /api/v1/transacoes.php → /api/v1/transactions.php
 * Mantido para compatibilidade retroativa com integrações existentes.
 */
require __DIR__ . '/transactions.php';
