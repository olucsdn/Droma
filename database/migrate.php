<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);

$migrations = [
    '2026_11_01_form_nonces' => [
        "CREATE TABLE IF NOT EXISTS form_nonces (
            user_id INT UNSIGNED NOT NULL,
            nonce CHAR(32) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, nonce),
            KEY idx_form_nonces_created (created_at)
        ) ENGINE=InnoDB",
    ],
    '2026_11_02_transactions_client_id' => [
        "ALTER TABLE transactions ADD COLUMN client_id VARCHAR(64) DEFAULT NULL AFTER transfer_id",
        "ALTER TABLE transactions ADD UNIQUE KEY uq_transactions_user_client (user_id, client_id)",
    ],
    '2026_11_03_transactions_import_hash' => [
        "ALTER TABLE transactions ADD COLUMN import_hash CHAR(64) NULL",
        "ALTER TABLE transactions ADD UNIQUE KEY uq_transactions_import (user_id, account_id, import_hash)",
    ],
    '2026_11_04_users_api_token_hash' => [
        "ALTER TABLE users ADD COLUMN api_token_hash CHAR(64) NULL, ADD UNIQUE KEY uq_users_api_token_hash (api_token_hash)",
        "UPDATE users SET api_token_hash = SHA2(api_token, 256) WHERE api_token IS NOT NULL AND api_token <> '' AND api_token_hash IS NULL",
    ],
    '2026_11_05_users_verification_expiry' => [
        "ALTER TABLE users ADD COLUMN verification_expires_at DATETIME NULL, ADD COLUMN verification_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0",
    ],
    '2026_11_06_categories_unique' => [
        "ALTER TABLE categories ADD UNIQUE KEY uq_categories_user_name_type (user_id, name, type)",
    ],
    '2026_11_07_transactions_balance_idx' => [
        "ALTER TABLE transactions ADD INDEX idx_tx_account_status_visivel (account_id, status, visivel)",
    ],
    '2026_11_08_tags_and_splits' => [
        "CREATE TABLE IF NOT EXISTS tags (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(40) NOT NULL,
            color CHAR(7) NOT NULL DEFAULT '#d4af37',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tags_user_name (user_id, name),
            KEY idx_tags_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS transaction_tags (
            transaction_id INT UNSIGNED NOT NULL,
            tag_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (transaction_id, tag_id),
            KEY idx_tx_tags_tag (tag_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS transaction_splits (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            description VARCHAR(120) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_tx_splits_tx (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
    '2026_11_09_net_worth_snapshots' => [
        "CREATE TABLE IF NOT EXISTS net_worth_snapshots (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            snapshot_month CHAR(7) NOT NULL,
            accounts_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            investments_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            cards_debt DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            net_worth DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_nw_user_month (user_id, snapshot_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ],
];

$applied = array_flip($pdo->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN));

foreach ($migrations as $version => $statements) {
    if (isset($applied[$version])) {
        continue;
    }

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (
                str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key')
                || str_contains($msg, 'already exists')
            ) {
                continue;
            }
            fwrite(STDERR, "[migrate] Erro em {$version}: {$msg}\n");
            exit(1);
        }
    }

    $pdo->prepare("INSERT IGNORE INTO schema_migrations (version) VALUES (?)")->execute([$version]);
    echo "[migrate] Aplicada: {$version}\n";
}

echo "[migrate] Concluido.\n";
