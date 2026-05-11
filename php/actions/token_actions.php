<?php
/**
 * Token Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

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
        $payment_method = $_POST['payment_method'] ?? 'M-Pesa';
        $reference      = trim($_POST['reference'] ?? '');

        if (empty($meter_number) || $amount <= 0) {
            header("Location: ../tokens.php?error=invalid_input");
            exit();
        }

        // Find property/unit from active lease
        $stmtLease = $pdo->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmtLease->execute([$tenantId]);
        $lease = $stmtLease->fetch();
        $property_id = $lease['property_id'] ?? null;
        $unit_id     = $lease['unit_id'] ?? null;

        try {
            $pdo->beginTransaction();

            // 1. Create a PENDING token request (no token code yet, status = Pending)
            $token_id = generateUUID();
            $stmt = $pdo->prepare("
                INSERT INTO tokens 
                    (id, tenant_id, property_id, unit_id, token_type, meter_number, token_code, units_value, amount, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, '', 0, ?, 'Pending', ?)
            ");
            $stmt->execute([$token_id, $tenantId, $property_id, $unit_id, $token_type, $meter_number, $amount, $_SESSION['user_id']]);

            // 2. Create a PENDING transaction for admin to confirm
            $trans_id   = generateUUID();
            $trans_type = ($token_type === 'Electricity') ? 'Electricity Token' : 'Water Token';
            $desc       = "Token request for Meter: $meter_number" . ($reference ? " | Ref: $reference" : '');
            $stmtTrans  = $pdo->prepare("
                INSERT INTO transactions 
                    (id, tenant_id, amount, transaction_type, payment_method, status, description, transaction_date) 
                VALUES (?, ?, ?, ?, ?, 'Pending', ?, NOW())
            ");
            $stmtTrans->execute([$trans_id, $tenantId, $amount, $trans_type, $payment_method, $desc]);

            $pdo->commit();
            header("Location: ../tokens.php?success=requested&token_id=$token_id");
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
            $stmtTrans  = $pdo->prepare("
                INSERT INTO transactions 
                    (id, tenant_id, amount, transaction_type, payment_method, status, description, transaction_date) 
                VALUES (?, ?, ?, ?, 'System Generated', 'Paid', ?, NOW())
            ");
            $stmtTrans->execute([$trans_id, $tenant_id, $amount, $trans_type, "Token: $token_code | Meter: $meter_number"]);

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
