<?php
/**
 * SMS Gateway — Shanfix Bulk SMS
 * Primelink Management System
 *
 * Ported from the Shanfix System `App\Services\Sms` service into Primelink's
 * procedural style.
 *
 * Authentication is a Client ID + API key pair from the portal's Developer/API
 * page, sent as X-Client-Id / X-Api-Key headers. Messages are charged in SMS
 * units against the account balance, and the sender ID must already be approved
 * on that account.
 *
 * Endpoints used (api/v1):
 *   sendsms.php   one recipient          60 requests/minute
 *   bulksend.php  up to 1 000 recipients 10 requests/minute
 *   balance.php   remaining SMS units
 *
 * Credentials live in system_settings: sms_client_id, sms_api_key,
 * sms_sender_id, sms_base_url, sms_enabled.
 */

require_once __DIR__ . '/settings.php';

const SMS_DEFAULT_BASE_URL = 'https://sms.shanfixtechnology.com';

/** The gateway rejects anything longer — six 160-character segments. */
const SMS_MAX_LENGTH = 918;

/** One bulksend.php call carries at most this many recipients. */
const SMS_BULK_LIMIT = 1000;

/* ═══════════════════════════════════════════════════════════════════════
   CONFIGURATION
   ═══════════════════════════════════════════════════════════════════════ */

/** All SMS settings in one array, read once per request. */
function smsConfig(PDO $pdo): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $base = trim(getSetting($pdo, 'sms_base_url', SMS_DEFAULT_BASE_URL));

    $cfg = [
        'enabled'   => getSetting($pdo, 'sms_enabled', '0') === '1',
        'client_id' => trim(getSetting($pdo, 'sms_client_id', '')),
        'api_key'   => trim(getSetting($pdo, 'sms_api_key',   '')),
        'sender_id' => trim(getSetting($pdo, 'sms_sender_id', '')),
        'base_url'  => rtrim($base !== '' ? $base : SMS_DEFAULT_BASE_URL, '/'),
    ];
    return $cfg;
}

/** Credentials present. Does not check whether sending is switched on. */
function smsIsConfigured(PDO $pdo): bool {
    $c = smsConfig($pdo);
    return $c['client_id'] !== '' && $c['api_key'] !== '';
}

/** Configured *and* switched on — the check to make before sending. */
function smsIsActive(PDO $pdo): bool {
    return smsIsConfigured($pdo) && smsConfig($pdo)['enabled'];
}

/* ═══════════════════════════════════════════════════════════════════════
   PHONE NUMBERS
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Normalise a Kenyan number to 254XXXXXXXXX, or null if it cannot be read.
 *
 *   0712345678  -> 254712345678
 *   712345678   -> 254712345678
 *   +254712345678 / 254712345678 -> 254712345678
 */
function normalizePhone(?string $phone): ?string {
    $digits = preg_replace('/\D+/', '', (string)$phone);

    if ($digits === '' || $digits === null) return null;

    if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
        return '254' . substr($digits, 1);
    }
    if (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
        return '254' . $digits;
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '254')) {
        return $digits;
    }
    return null;
}

/**
 * How many 160-character parts a message will be billed as.
 * Unicode messages (any non-GSM character) drop to 70 per part.
 */
function smsParts(string $message): int {
    $length  = mb_strlen($message);
    $unicode = preg_match('/[^\x20-\x7E\n\r]/', $message) === 1;

    $single = $unicode ? 70 : 160;
    $multi  = $unicode ? 67 : 153;

    return $length <= $single ? 1 : (int)ceil($length / $multi);
}

/** Trim, and keep the body inside the gateway's hard length limit. */
function smsPrepare(string $message): string {
    $message = trim($message);
    if (mb_strlen($message) > SMS_MAX_LENGTH) {
        $message = mb_substr($message, 0, SMS_MAX_LENGTH - 3) . '...';
    }
    return $message;
}

/* ═══════════════════════════════════════════════════════════════════════
   DELIVERY LOG
   ═══════════════════════════════════════════════════════════════════════ */

/** Create the SMS delivery log if it does not exist yet. */
function ensureSmsSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sms_log` (
                `id`           VARCHAR(36) PRIMARY KEY,
                `tenant_id`    VARCHAR(36)  NULL,
                `phone`        VARCHAR(20)  NULL,
                `message`      TEXT         NULL,
                `parts`        INT          NOT NULL DEFAULT 1,
                `status`       VARCHAR(20)  NOT NULL DEFAULT 'Pending',
                `provider_ref` VARCHAR(120) NULL,
                `units`        DECIMAL(10,4) NULL,
                `error`        TEXT         NULL,
                `context`      VARCHAR(60)  NULL,
                `sent_by`      VARCHAR(36)  NULL,
                `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_smslog_tenant`  (`tenant_id`),
                INDEX `idx_smslog_created` (`created_at`),
                INDEX `idx_smslog_status`  (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {
        // Non-fatal — logging must never block a send
    }
}

/** Record one SMS attempt. Never throws. */
function logSms(
    PDO $pdo,
    ?string $tenantId,
    string $phone,
    string $message,
    bool $ok,
    ?string $ref = null,
    ?float $units = null,
    ?string $error = null,
    string $context = ''
): void {
    ensureSmsSchema($pdo);
    try {
        $pdo->prepare("
            INSERT INTO sms_log (id, tenant_id, phone, message, parts, status, provider_ref, units, error, context, sent_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            generateUUID(),
            $tenantId ?: null,
            $phone,
            $message,
            smsParts($message),
            $ok ? 'Sent' : 'Failed',
            $ref ?: null,
            $units,
            $error ?: null,
            $context ?: null,
            $_SESSION['user_id'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Non-fatal
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   TRANSPORT
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * POST a JSON body to an api/v1 endpoint with the credential headers.
 *
 * @return array{ok:bool, json?:array, error?:string}
 */
function smsPost(PDO $pdo, string $script, array $payload): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL is not available on this server.'];
    }

    $c = smsConfig($pdo);

    if ($c['sender_id'] !== '' && !isset($payload['sender_id'])
        && in_array($script, ['sendsms.php', 'bulksend.php'], true)) {
        $payload['sender_id'] = $c['sender_id'];
    }

    $url = $c['base_url'] . '/api/v1/' . $script;
    $ch  = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,      // a full bulk batch takes a while
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // Without this cURL sends no User-Agent, which some edge
        // protection answers with a bare 403.
        CURLOPT_USERAGENT      => 'Primelink/1.0 (+PHP cURL)',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Client-Id: ' . $c['client_id'],
            'X-Api-Key: '   . $c['api_key'],
        ],
    ]);

    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log("Primelink SMS: gateway unreachable ({$url}): {$err}");
        return ['ok' => false, 'error' => 'Could not reach the SMS gateway: ' . $err];
    }

    $json = json_decode((string)$body, true);

    if (!is_array($json)) {
        error_log("Primelink SMS: non-JSON response from {$url} (HTTP {$status})");
        return ['ok' => false, 'error' => 'The SMS gateway returned an unreadable response (HTTP ' . $status . ').'];
    }

    // The API reports failure both by HTTP status and by success:false.
    if ($status < 200 || $status >= 300 || ($json['success'] ?? false) !== true) {
        $message = (string)($json['error'] ?? '');

        if ($message === '') {
            $message = match ($status) {
                401     => 'The gateway rejected your credentials. Check the Client ID and API key.',
                403     => 'Your Shanfix Bulk SMS account is suspended.',
                429     => 'Sending too fast — the gateway rate limit was hit. Try again shortly.',
                default => 'SMS gateway returned HTTP ' . $status . '.',
            };
        }

        error_log("Primelink SMS: gateway error ({$url}, HTTP {$status}): {$message}");
        return ['ok' => false, 'error' => $message];
    }

    return ['ok' => true, 'json' => $json];
}

/* ═══════════════════════════════════════════════════════════════════════
   SENDING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Send to one recipient.
 *
 * @return array{ok:bool, error?:string, ref?:string, cost?:float|null, status?:string, balance?:string|null}
 */
function sendSms(PDO $pdo, string $phone, string $message, ?string $tenantId = null, string $context = ''): array {
    if (!smsIsConfigured($pdo)) {
        return ['ok' => false, 'error' => 'SMS is not configured. Add your Shanfix Bulk SMS credentials in Settings.'];
    }
    if (!smsConfig($pdo)['enabled']) {
        return ['ok' => false, 'error' => 'SMS sending is switched off in Settings.'];
    }

    $normalised = normalizePhone($phone);
    if ($normalised === null) {
        $err = 'Invalid phone number: ' . $phone;
        logSms($pdo, $tenantId, (string)$phone, $message, false, null, null, $err, $context);
        return ['ok' => false, 'error' => $err];
    }

    $message = smsPrepare($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Message body is empty.'];
    }

    $response = smsPost($pdo, 'sendsms.php', [
        'to'      => $normalised,
        'message' => $message,
    ]);

    if (!$response['ok']) {
        logSms($pdo, $tenantId, $normalised, $message, false, null, null, $response['error'], $context);
        return ['ok' => false, 'error' => $response['error']];
    }

    $json = $response['json'];
    $ref  = (string)($json['message_id'] ?? '');
    $cost = isset($json['units_charged']) ? (float)$json['units_charged'] : null;

    logSms($pdo, $tenantId, $normalised, $message, true, $ref, $cost, null, $context);

    return [
        'ok'      => true,
        'ref'     => $ref,
        'cost'    => $cost,
        'status'  => 'Submitted',
        'balance' => isset($json['remaining_units']) ? (string)$json['remaining_units'] : null,
    ];
}

/**
 * Send the same message to many recipients in one call. The gateway
 * de-duplicates numbers and reports invalid ones back rather than failing
 * the whole batch.
 *
 * @param array<int,string> $phones Raw numbers; normalised here
 *
 * @return array{ok:bool, error?:string, submitted?:int, sent?:int, failed?:int,
 *               invalid?:array<int,string>, cost?:float, balance?:string|null}
 */
function sendBulkSms(PDO $pdo, array $phones, string $message, string $context = ''): array {
    if (!smsIsConfigured($pdo)) {
        return ['ok' => false, 'error' => 'SMS is not configured. Add your Shanfix Bulk SMS credentials in Settings.'];
    }
    if (!smsConfig($pdo)['enabled']) {
        return ['ok' => false, 'error' => 'SMS sending is switched off in Settings.'];
    }

    $message = smsPrepare($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Message body is empty.'];
    }

    $recipients = [];
    $invalid    = [];

    foreach ($phones as $phone) {
        $normalised = normalizePhone((string)$phone);
        if ($normalised === null) {
            $invalid[] = (string)$phone;
        } else {
            $recipients[$normalised] = true;   // key de-dupes
        }
    }
    $recipients = array_keys($recipients);

    if ($recipients === []) {
        return ['ok' => false, 'error' => 'No valid phone numbers to send to.', 'invalid' => $invalid];
    }

    $submitted = 0;
    $sent      = 0;
    $failed    = 0;
    $cost      = 0.0;
    $balance   = null;

    // Chunk so a list longer than the gateway's per-call ceiling still goes.
    foreach (array_chunk($recipients, SMS_BULK_LIMIT) as $chunk) {
        $response = smsPost($pdo, 'bulksend.php', [
            'to'      => $chunk,
            'message' => $message,
        ]);

        if (!$response['ok']) {
            foreach ($chunk as $p) {
                logSms($pdo, null, $p, $message, false, null, null, $response['error'], $context);
            }
            // Anything already sent stands; report the failure with the tally.
            return [
                'ok'        => false,
                'error'     => $response['error'],
                'submitted' => $submitted,
                'sent'      => $sent,
                'failed'    => $failed + count($chunk),
                'invalid'   => $invalid,
                'cost'      => $cost,
            ];
        }

        $json = $response['json'];

        $submitted += (int)($json['total_submitted'] ?? count($chunk));
        $sent      += (int)($json['sent'] ?? 0);
        $failed    += (int)($json['failed'] ?? 0);
        $cost      += (float)($json['units_charged'] ?? 0);
        $balance    = $json['remaining_units'] ?? $balance;

        $badNumbers = array_map('strval', (array)($json['invalid_numbers'] ?? []));
        foreach ($badNumbers as $bad) {
            $invalid[] = $bad;
        }

        // Log per recipient so each tenant's history shows the message
        $unitsEach = count($chunk) > 0 ? round((float)($json['units_charged'] ?? 0) / count($chunk), 4) : null;
        foreach ($chunk as $p) {
            $bad = in_array($p, $badNumbers, true);
            logSms($pdo, null, $p, $message, !$bad, (string)($json['batch_id'] ?? ''), $bad ? null : $unitsEach,
                $bad ? 'Rejected by gateway' : null, $context);
        }
    }

    return [
        'ok'        => true,
        'submitted' => $submitted,
        'sent'      => $sent,
        'failed'    => $failed,
        'invalid'   => $invalid,
        'cost'      => round($cost, 4),
        'balance'   => $balance !== null ? (string)$balance : null,
    ];
}

/**
 * Remaining SMS units on the account. Doubles as the credentials check —
 * it costs nothing to call.
 *
 * @return array{ok:bool, error?:string, balance?:float|null, client_name?:string, message?:string}
 */
function smsBalance(PDO $pdo): array {
    if (!smsIsConfigured($pdo)) {
        return ['ok' => false, 'error' => 'SMS is not configured.'];
    }

    $response = smsPost($pdo, 'balance.php', []);
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error']];
    }

    $json  = $response['json'];
    $units = isset($json['sms_units']) ? (float)$json['sms_units'] : null;
    $name  = (string)($json['client_name'] ?? '');

    return [
        'ok'          => true,
        'balance'     => $units,
        'client_name' => $name,
        'message'     => 'Connected as ' . ($name !== '' ? $name : 'your account')
                       . ($units !== null ? '. Balance: ' . rtrim(rtrim(number_format($units, 2), '0'), '.') . ' SMS units' : '.'),
    ];
}
