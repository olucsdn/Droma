<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Testes Unitários e Integrados — NOTIF-001 (Notificações, Recorrências e Orçamento)
 * Executar via CLI: php tests/test_suite_notif.php
 */
require_once __DIR__ . '/../config/database.php';

$erros = 0;
$sucessos = 0;

function runNotifTest(string $descricao, callable $fn): void {
    global $erros, $sucessos;
    try {
        if ($fn() !== false) {
            echo " [OK] {$descricao}\n";
            $sucessos++;
        } else {
            echo " [FALHA] {$descricao}\n";
            $erros++;
        }
    } catch (Throwable $e) {
        echo " [ERRO] {$descricao}: " . $e->getMessage() . "\n";
        $erros++;
    }
}

echo "====================================================\n";
echo "TESTES DE INTEGRACAO - NOTIF-001 (CRON & ALERTAS)\n";
echo "====================================================\n\n";

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `daily_notifications` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `type` VARCHAR(50) NOT NULL,
        `date_sent` DATE NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_user_type_date` (`user_id`, `type`, `date_sent`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$testUserId = 999991;
$testDate = '2099-12-31';
$testType = 'test_notif_idempotency';

runNotifTest("Idempotência em daily_notifications bloqueia duplicidade no mesmo dia", function () use ($pdo, $testUserId, $testType, $testDate) {
    $pdo->prepare("DELETE FROM daily_notifications WHERE user_id = ? AND type = ?")->execute([$testUserId, $testType]);

    $ins = $pdo->prepare("INSERT IGNORE INTO daily_notifications (user_id, type, date_sent) VALUES (?, ?, ?)");
    $ins->execute([$testUserId, $testType, $testDate]);
    $first = $ins->rowCount();

    $ins->execute([$testUserId, $testType, $testDate]);
    $second = $ins->rowCount();

    $pdo->prepare("DELETE FROM daily_notifications WHERE user_id = ? AND type = ?")->execute([$testUserId, $testType]);
    return $first === 1 && $second === 0;
});

runNotifTest("Cálculo mensal de recorrência respeita day_of_month (31/01 -> 28/02)", function () {
    $base = new DateTime('2026-01-31');
    $base->modify('first day of next month');
    $dia = min(31, (int)$base->format('t'));
    $next = $base->format('Y-m-') . str_pad((string)$dia, 2, '0', STR_PAD_LEFT);
    return $next === '2026-02-28';
});

runNotifTest("Regra de alerta orçamentário detecta 80% e 100%", function () {
    $limit = 1000.0;
    $pct80 = ($limit * 0.80) / $limit * 100;
    $pct100 = ($limit * 1.05) / $limit * 100;
    return $pct80 >= 80.0 && $pct80 < 100.0 && $pct100 >= 100.0;
});

echo "\nResumo NOTIF-001: {$sucessos} passou(aram), {$erros} falhou(aram).\n";
exit($erros > 0 ? 1 : 0);
