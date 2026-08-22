<?php
/**
 * Token Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/bank_accounts.php';

ensureBankAccountSchema($pdo);

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';

/**
 * Generate a random utility token code
 */
function generateTokenCode($type) {
    $prefix = ($type === 'Electricity') ? 'KPLC' : 'WT';
    $segments = [];
    for ($i = 0; $i < 4; $i++) {
        $segments[] = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    return "$prefix-" . implode('-', $segments);
}

/**
 * Initiate Safaricom Daraja STK Push
 * Returns true on success, false/error string on failure
 */
function initiateStkPush($phone, $amount, $accountRef) {
    global $pdo;
    // DB settings take precedence; fall back to env vars for backwards-compat
    $consumerKey    = getSetting($pdo, 'mpesa_consumer_key',    getenv('MPESA_CONSUMER_KEY')    ?: '');
    $consumerSecret = getSetting($pdo, 'mpesa_consumer_secret', getenv('MPESA_CONSUMER_SECRET') ?: '');
    $shortcode      = getSetting($pdo, 'mpesa_shortcode',       getenv('MPESA_SHORTCODE')       ?: '174379');
    $passkey        = getSetting($pdo, 'mpesa_passkey',         getenv('MPESA_PASSKEY')         ?: '');
    $callbackUrl    = getSetting($pdo, 'mpesa_callback_url',    getenv('MPESA_CALLBACK_URL')    ?: '');
    $environment    = getSetting($pdo, 'mpesa_environment',     'sandbox');

    // If credentials not set, skip (request is still saved as Pending)
    if (empty($consumerKey) || empty($consumerSecret) || empty($passkey) || empty($callbackUrl)) {
        return false; // Graceful no-op — admin will confirm manually
    }

    $baseUrl = $environment === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';

    // Get OAuth token
    $credentials = base64_encode("$consumerKey:$consumerSecret");
    $ch = curl_init("$baseUrl/oauth/v1/generate?grant_type=client_credentials");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Basic $credentials"],
    ]);
    $tokenRes = json_decode(curl_exec($ch), true);
    curl_close($ch);
    $accessToken = $tokenRes['access_token'] ?? null;
    if (!$accessToken) return false;

    // Build STK Push payload
    $timestamp = date('YmdHis');
    $password  = base64_encode($shortcode . $passkey . $timestamp);
    $payload   = [
        'BusinessShortCode' => $shortcode,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => (int)$amount,
        'PartyA'            => $phone,
        'PartyB'            => $shortcode,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => $callbackUrl,
        'AccountReference'  => $accountRef,
        'TransactionDesc'   => 'Primelink Token Purchase',
    ];

    $ch = curl_init("$baseUrl/mpesa/stkpush/v1/processrequest");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
        ],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return ($res['ResponseCode'] ?? '') === '0';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── TENANT: Request Token Purchase ───────────────────────────────────────
    if ($action === 'purchase') {
        requireRole(['tenant']);

        // Resolve tenant
        $stmtT = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
        $stmtT->execute([$_SESSION['user_id']]);
        $tenantId = $stmtT->fetchColumn();

        $token_type     = $_POST['token_type'] ?? 'Electricity';
        $amount         = (float)$_POST['amount'];
        $meter_number   = trim($_POST['meter_number'] ?? '');
        $payment_method = $_POST['payment_method'] ?? 'M-Pesa STK';
        $reference      = trim($_POST['reference'] ?? '');
        $raw_phone      = trim($_POST['phone_number'] ?? '');

        // Normalize phone to 254XXXXXXXXX format
        $phone = preg_replace('/\D/', '', $raw_phone);
        if (strlen($phone) === 9) $phone = '254' . $phone;
        elseif (strlen($phone) === 10 && $phone[0] === '0') $phone = '254' . substr($phone, 1);

        if (empty($meter_number) || $amount <= 0) {
            header("Location: ../tokens.php?error=Please+fill+all+required+fields");
            exit();
        }

        if ($payment_method === 'M-Pesa STK' && strlen($phone) !== 12) {
            header("Location: ../tokens.php?error=Invalid+phone+number+for+STK+Push");
            exit();
        }

        // Find property/unit from active lease
        $stmtLease = $pdo->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmtLease->execute([$tenantId]);
        $lease = $stmtLease->fetch();
        $property_id = $lease['property_id'] ?? null;
        $unit_id     = $lease['unit_id'] ?? null;

        // Build description with all relevant info
        $desc = "Token request | Meter: $meter_number";
        if ($phone)      $desc .= " | Phone: $phone";
        if ($reference)  $desc .= " | Ref: $reference";

        // Attempt Daraja STK Push (if credentials configured)
        $stkPushResult = null;
        if ($payment_method === 'M-Pesa STK' && $phone) {
            $stkPushResult = initiateStkPush($phone, $amount, $meter_number);
        }

        try {
            $pdo->beginTransaction();

            $token_id = generateUUID();
            $stmt = $pdo->prepare("
                INSERT INTO tokens 
                    (id, tenant_id, property_id, unit_id, token_type, meter_number, token_code, units_value, amount, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, '', 0, ?, 'Pending', ?)
            ");
            $stmt->execute([$token_id, $tenantId, $property_id, $unit_id, $token_type, $meter_number, $amount, $_SESSION['user_id']]);

            $trans_id   = generateUUID();
            $trans_type = ($token_type === 'Electricity') ? 'Electricity Token' : 'Water Token';
            $stmtTrans  = $pdo->prepare("
                INSERT INTO transactions 
                    (id, tenant_id, amount, transaction_type, payment_method, status, description, transaction_date) 
                VALUES (?, ?, ?, ?, ?, 'Pending', ?, NOW())
            ");
            $stmtTrans->execute([$trans_id, $tenantId, $amount, $trans_type, $payment_method, $desc]);

            $pdo->commit();

            $successMsg = ($payment_method === 'M-Pesa STK')
                ? 'stk_sent&phone=' . urlencode($phone)
                : 'requested';

            header("Location: ../tokens.php?success=$successMsg&token_id=$token_id");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Error requesting token: " . $e->getMessage());
        }
    }

    // ─── ADMIN/STAFF: Confirm Payment & Generate Token ─────────────────────────
    if ($action === 'confirm_and_generate') {
        requireRole(['admin', 'staff']);

        $token_id   = $_POST['token_id'] ?? '';
        $units_value = (float)($_POST['units_value'] ?? 0);

        // Fetch pending token request
        $stmtFetch = $pdo->prepare("SELECT * FROM tokens WHERE id = ? AND status = 'Pending'");
        $stmtFetch->execute([$token_id]);
        $tokenReq = $stmtFetch->fetch();

        if (!$tokenReq) {
            header("Location: ../tokens.php?error=not_found");
            exit();
        }

        $token_code = generateTokenCode($tokenReq['token_type']);

        try {
            $pdo->beginTransaction();

            // 1. Activate token with generated code and units
            $stmtUpdate = $pdo->prepare("
                UPDATE tokens 
                SET token_code = ?, units_value = ?, status = 'Active' 
                WHERE id = ?
            ");
            $stmtUpdate->execute([$token_code, $units_value, $token_id]);

            // 2. Confirm the matching transaction as Paid
            $stmtConfirm = $pdo->prepare("
                UPDATE transactions 
                SET status = 'Paid' 
                WHERE tenant_id = ? 
                  AND transaction_type IN ('Electricity Token', 'Water Token') 
                  AND status = 'Pending' 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmtConfirm->execute([$tokenReq['tenant_id']]);

            $pdo->commit();
            header("Location: ../tokens.php?success=generated&code=" . urlencode($token_code));
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Error generating token: " . $e->getMessage());
        }
    }

    // ─── ADMIN/STAFF: Manually Generate Token (Direct) ─────────────────────────
    if ($action === 'generate') {
        requireRole(['staff', 'landlord']);

        $tenant_id   = $_POST['tenant_id'] ?? null;
        $token_type  = $_POST['token_type'] ?? 'Electricity';
        $units_value = (float)($_POST['units_value'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);
        $meter_number = trim($_POST['meter_number'] ?? '');

        $stmtLease = $pdo->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmtLease->execute([$tenant_id]);
        $lease = $stmtLease->fetch();
        $property_id = $lease['property_id'] ?? null;
        $unit_id     = $lease['unit_id'] ?? null;

        $token_id   = generateUUID();
        $token_code = generateTokenCode($token_type);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO tokens 
                    (id, tenant_id, property_id, unit_id, token_type, meter_number, token_code, units_value, amount, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)
            ");
            $stmt->execute([$token_id, $tenant_id, $property_id, $unit_id, $token_type, $meter_number, $token_code, $units_value, $amount, $_SESSION['user_id']]);

            $trans_id   = generateUUID();
            $trans_type = ($token_type === 'Electricity') ? 'Electricity Token' : 'Water Token';
            $bankAcc    = getBankAccount($pdo, trim($_POST['bank_account_id'] ?? ''));
            $stmtTrans  = $pdo->prepare("
                INSERT INTO transactions 
                    (id, tenant_id, amount, transaction_type, payment_method, status, description, bank_account_id, transaction_date) 
                VALUES (?, ?, ?, ?, 'System Generated', 'Paid', ?, ?, NOW())
            ");
            $stmtTrans->execute([$trans_id, $tenant_id, $amount, $trans_type, "Token: $token_code | Meter: $meter_number", $bankAcc['id'] ?? null]);

            $pdo->commit();
            header("Location: ../tokens.php?success=generated&code=" . urlencode($token_code));
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Error generating token: " . $e->getMessage());
        }
    }
}
?>
