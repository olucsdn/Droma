<?php
header('Content-Type: application/json; charset=UTF-8');

$host = 'smtp.gmail.com';
$port = 465;
$t0 = microtime(true);
$socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 5);
$dt = round(microtime(true) - $t0, 3);

if (!$socket) {
    echo json_encode([
        'success' => false,
        'error' => "Falha ao conectar via socket: [{$errno}] {$errstr}",
        'elapsed_seconds' => $dt,
        'blocked_by_host' => ($errno === 110 || $errno === 0 || str_contains($errstr, 'timed out') || str_contains($errstr, 'Connection refused')),
        'php_version' => PHP_VERSION,
        'openssl_loaded' => extension_loaded('openssl'),
    ]);
} else {
    $banner = fgets($socket, 512);
    fclose($socket);
    echo json_encode([
        'success' => true,
        'banner' => trim($banner),
        'elapsed_seconds' => $dt,
        'php_version' => PHP_VERSION,
        'openssl_loaded' => extension_loaded('openssl'),
    ]);
}
