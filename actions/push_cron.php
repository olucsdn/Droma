<?php
/**
 * Endpoint agendado (cron) para disparo diário de alertas Web Push (VAPID).
 * Protegido por CRON_SECRET via variável de ambiente (nunca hardcoded).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/WebPushService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$secret = trim((string)(getenv('CRON_SECRET') ?: ($_ENV['CRON_SECRET'] ?? '')));
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['error' => 'CRON_SECRET not configured']);
    exit;
}

$authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$provided = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $provided = trim($m[1]);
} elseif (!empty($_GET['token'])) {
    $provided = trim((string)$_GET['token']);
}

if ($provided === '' || !hash_equals($secret, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (!WebPushService::isConfigured()) {
    http_response_code(503);
    echo json_encode(['error' => 'VAPID keys not configured']);
    exit;
}

$result = WebPushService::dispatchDueAlerts($pdo);
echo json_encode(['ok' => true] + $result);
