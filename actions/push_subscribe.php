<?php
/**
 * Gerencia inscrições Web Push (VAPID) do usuário autenticado.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/WebPushService.php';

$userId = Security::requireAuth();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $configured = WebPushService::isConfigured();
    echo json_encode([
        'configured' => $configured,
        'publicKey'  => $configured ? WebPushService::getPublicKey() : null,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$csrfToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['csrf_token'] ?? ''));
if (!Security::validateCsrf($csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$action = (string)($body['action'] ?? 'subscribe');

if ($action === 'unsubscribe') {
    $endpoint = (string)($body['endpoint'] ?? '');
    WebPushService::deleteSubscription($pdo, $userId, $endpoint);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'test') {
    if (!WebPushService::isConfigured()) {
        echo json_encode(['ok' => false, 'configured' => false]);
        exit;
    }
    $res = WebPushService::sendToUser($pdo, $userId, [
        'title' => 'Droma — Teste Web Push',
        'body'  => 'Notificação recebida em segundo plano com sucesso!',
        'url'   => 'calendar',
        'tag'   => 'droma-push-test',
    ]);
    echo json_encode(['ok' => $res['sent'] > 0, 'configured' => true] + $res);
    exit;
}

$subscription = $body['subscription'] ?? $body;
if (!is_array($subscription) || !WebPushService::saveSubscription($pdo, $userId, $subscription)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid push subscription']);
    exit;
}

echo json_encode(['ok' => true]);
