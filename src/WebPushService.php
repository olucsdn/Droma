<?php
/**
 * WebPushService.php — Envio de Notificações Web Push (RFC 8291 aes128gcm + RFC 8292 VAPID ES256)
 * Projeto Droma Financy
 *
 * Lê exclusivamente variáveis de ambiente (VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT).
 * Zero dependências externas (usa OpenSSL + cURL nativos do PHP 8.4).
 */
final class WebPushService {
    private const P256_SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    public static function getPublicKey(): string {
        return trim((string)(getenv('VAPID_PUBLIC_KEY') ?: ($_ENV['VAPID_PUBLIC_KEY'] ?? '')));
    }

    private static function getPrivateKey(): string {
        return trim((string)(getenv('VAPID_PRIVATE_KEY') ?: ($_ENV['VAPID_PRIVATE_KEY'] ?? '')));
    }

    private static function getSubject(): string {
        $sub = trim((string)(getenv('VAPID_SUBJECT') ?: ($_ENV['VAPID_SUBJECT'] ?? '')));
        return $sub !== '' ? $sub : 'mailto:suporte@droma.app';
    }

    public static function isConfigured(): bool {
        return self::getPublicKey() !== '' && self::getPrivateKey() !== ''
            && function_exists('openssl_pkey_derive') && function_exists('curl_init');
    }

    public static function ensureTable(PDO $pdo): void {
        static $created = false;
        if ($created) {
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `push_subscriptions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `endpoint` TEXT NOT NULL,
                `endpoint_hash` CHAR(64) NOT NULL,
                `p256dh` VARCHAR(255) NOT NULL,
                `auth` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_push_user_endpoint` (`user_id`, `endpoint_hash`),
                KEY `idx_push_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $created = true;
    }

    public static function saveSubscription(PDO $pdo, int $userId, array $sub): bool {
        self::ensureTable($pdo);

        $endpoint = trim((string)($sub['endpoint'] ?? ''));
        $p256dh   = trim((string)($sub['keys']['p256dh'] ?? ''));
        $auth     = trim((string)($sub['keys']['auth'] ?? ''));

        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL) || !str_starts_with($endpoint, 'https://')) {
            return false;
        }
        if ($p256dh === '' || $auth === '') {
            return false;
        }

        $hash = hash('sha256', $endpoint);
        $stmt = $pdo->prepare("
            INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                endpoint = VALUES(endpoint),
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
                updated_at = CURRENT_TIMESTAMP
        ");
        return $stmt->execute([$userId, $endpoint, $hash, $p256dh, $auth]);
    }

    public static function deleteSubscription(PDO $pdo, int $userId, string $endpoint): void {
        self::ensureTable($pdo);
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return;
        }
        $hash = hash('sha256', $endpoint);
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?");
        $stmt->execute([$userId, $hash]);
    }

    /**
     * Envia uma notificação Web Push para todas as inscrições ativas de um usuário
     *
     * @return array{sent: int, failed: int, expired_removed: int}
     */
    public static function sendToUser(PDO $pdo, int $userId, array $payload): array {
        self::ensureTable($pdo);
        if (!self::isConfigured()) {
            return ['sent' => 0, 'failed' => 0, 'expired_removed' => 0];
        }

        $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        $failed = 0;
        $expiredRemoved = 0;

        foreach ($subs as $sub) {
            $res = self::sendNotification($sub, $payload);
            if ($res['ok']) {
                $sent++;
            } else {
                $failed++;
                if (!empty($res['expired'])) {
                    $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ? AND user_id = ?");
                    $del->execute([(int)$sub['id'], $userId]);
                    $expiredRemoved++;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'expired_removed' => $expiredRemoved];
    }

    /**
     * Dispara alertas diários para todos os usuários inscritos com vencimentos hoje ou nos próximos 3 dias
     *
     * @return array{users: int, sent: int, failed: int, expired_removed: int}
     */
    public static function dispatchDueAlerts(PDO $pdo): array {
        self::ensureTable($pdo);
        if (!self::isConfigured()) {
            return ['users' => 0, 'sent' => 0, 'failed' => 0, 'expired_removed' => 0];
        }

        $usersStmt = $pdo->query("SELECT DISTINCT user_id FROM push_subscriptions");
        $userIds = $usersStmt->fetchAll(PDO::FETCH_COLUMN);

        $hoje   = date('Y-m-d');
        $limite = date('Y-m-d', strtotime('+3 days'));

        $dueStmt = $pdo->prepare("
            SELECT
                (SELECT COUNT(*) FROM transactions
                 WHERE user_id = ? AND visivel = 1 AND type = 'expense' AND status = 'pending'
                   AND id NOT IN (SELECT transaction_id FROM installments)
                   AND COALESCE(due_date, date) BETWEEN ? AND ?)
                +
                (SELECT COUNT(*) FROM installments i
                 JOIN transactions t ON t.id = i.transaction_id
                 JOIN credit_cards c ON c.id = i.credit_card_id
                 WHERE c.user_id = ? AND t.visivel = 1 AND i.status = 'pending'
                   AND i.due_date BETWEEN ? AND ?) AS count_due,
                (SELECT COALESCE(SUM(amount), 0) FROM transactions
                 WHERE user_id = ? AND visivel = 1 AND type = 'expense' AND status = 'pending'
                   AND id NOT IN (SELECT transaction_id FROM installments)
                   AND COALESCE(due_date, date) BETWEEN ? AND ?)
                +
                (SELECT COALESCE(SUM(i.amount), 0) FROM installments i
                 JOIN transactions t ON t.id = i.transaction_id
                 JOIN credit_cards c ON c.id = i.credit_card_id
                 WHERE c.user_id = ? AND t.visivel = 1 AND i.status = 'pending'
                   AND i.due_date BETWEEN ? AND ?) AS total_due
        ");

        $totalUsers = 0;
        $totalSent = 0;
        $totalFailed = 0;
        $totalExpired = 0;

        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            $dueStmt->execute([$uid, $hoje, $limite, $uid, $hoje, $limite, $uid, $hoje, $limite, $uid, $hoje, $limite]);
            $row = $dueStmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['count_due'] ?? 0);
            $total = (float)($row['total_due'] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $totalUsers++;
            $valorFmt = 'R$ ' . number_format($total, 2, ',', '.');
            $title = $count === 1 ? '1 conta vence em breve' : "{$count} contas vencem em breve";
            $body  = "Total pendente nos próximos 3 dias: {$valorFmt}. Toque para abrir o calendário.";

            $res = self::sendToUser($pdo, $uid, [
                'title' => $title,
                'body'  => $body,
                'url'   => 'calendar',
                'tag'   => 'droma-due-alerts',
            ]);

            $totalSent += $res['sent'];
            $totalFailed += $res['failed'];
            $totalExpired += $res['expired_removed'];
        }

        return [
            'users'           => $totalUsers,
            'sent'            => $totalSent,
            'failed'          => $totalFailed,
            'expired_removed' => $totalExpired,
        ];
    }

    /**
     * @return array{ok: bool, status: int, expired: bool, error?: string}
     */
    public static function sendNotification(array $sub, array $payload): array {
        $endpoint = (string)($sub['endpoint'] ?? '');
        $p256dh   = (string)($sub['p256dh'] ?? '');
        $auth     = (string)($sub['auth'] ?? '');

        $parsed = parse_url($endpoint);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            return ['ok' => false, 'status' => 0, 'expired' => true, 'error' => 'Invalid endpoint'];
        }
        $aud = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');

        $jwt = self::createVapidJwt($aud);
        if ($jwt === null) {
            return ['ok' => false, 'status' => 0, 'expired' => false, 'error' => 'Failed to sign VAPID JWT'];
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return ['ok' => false, 'status' => 0, 'expired' => false, 'error' => 'Invalid payload'];
        }

        $encrypted = self::encryptPayload($jsonPayload, $p256dh, $auth);
        if ($encrypted === null) {
            return ['ok' => false, 'status' => 0, 'expired' => false, 'error' => 'Encryption failed'];
        }

        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . self::getPublicKey(),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Urgency: normal',
            'Content-Length: ' . strlen($encrypted),
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encrypted,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'expired' => false];
        }

        $expired = in_array($status, [404, 410], true);
        if ($status >= 400 || $status === 0) {
            error_log("[WebPushService] Push failed HTTP {$status}: {$err}");
        }
        return ['ok' => false, 'status' => $status, 'expired' => $expired, 'error' => $err];
    }

    private static function createVapidJwt(string $aud): ?string {
        $pubRaw  = self::base64UrlDecode(self::getPublicKey());
        $privRaw = self::base64UrlDecode(self::getPrivateKey());
        if (strlen($pubRaw) !== 65 || strlen($privRaw) !== 32) {
            return null;
        }

        $sec1Der = "\x30\x77\x02\x01\x01\x04\x20"
            . $privRaw
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            . "\xa1\x44\x03\x42\x00"
            . $pubRaw;

        $pem = "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($sec1Der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";

        $pkey = openssl_pkey_get_private($pem);
        if ($pkey === false) {
            return null;
        }

        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $aud,
            'exp' => time() + 43200,
            'sub' => self::getSubject(),
        ]));

        $signingInput = $header . '.' . $claims;
        $derSig = '';
        if (!openssl_sign($signingInput, $derSig, $pkey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $rawSig = self::derToRawSignature($derSig);
        if ($rawSig === null) {
            return null;
        }

        return $signingInput . '.' . self::base64UrlEncode($rawSig);
    }

    private static function encryptPayload(string $plaintext, string $clientPubB64, string $authB64): ?string {
        $clientPubRaw = self::base64UrlDecode($clientPubB64);
        $authSecret   = self::base64UrlDecode($authB64);
        if (strlen($clientPubRaw) !== 65 || strlen($authSecret) !== 16) {
            return null;
        }

        $clientPubPem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(self::P256_SPKI_PREFIX . $clientPubRaw), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $clientPubKey = openssl_pkey_get_public($clientPubPem);
        if ($clientPubKey === false) {
            return null;
        }

        $ephemeralKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if ($ephemeralKey === false) {
            return null;
        }

        $details = openssl_pkey_get_details($ephemeralKey);
        if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
            return null;
        }
        $ephemeralPubRaw = "\x04"
            . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $sharedSecret = openssl_pkey_derive($clientPubKey, $ephemeralKey, 32);
        if ($sharedSecret === false || strlen($sharedSecret) !== 32) {
            return null;
        }

        $salt = random_bytes(16);
        $ikm  = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\x00" . $clientPubRaw . $ephemeralPubRaw, $authSecret);
        $cek  = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            return null;
        }

        return $salt . pack('N', 4096) . chr(strlen($ephemeralPubRaw)) . $ephemeralPubRaw . $ciphertext . $tag;
    }

    private static function derToRawSignature(string $der): ?string {
        $len = strlen($der);
        if ($len < 8 || ord($der[0]) !== 0x30) {
            return null;
        }
        $offset = 2;
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += (ord($der[1]) & 0x7f);
        }
        if ($offset + 2 >= $len || ord($der[$offset]) !== 0x02) {
            return null;
        }
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        $offset += 2 + $rLen;

        if ($offset + 2 >= $len || ord($der[$offset]) !== 0x02) {
            return null;
        }
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
        if (strlen($r) !== 32 || strlen($s) !== 32) {
            return null;
        }
        return $r . $s;
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
