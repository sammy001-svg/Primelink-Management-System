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
 * Generate a random utility token
 */
function generateTokenCode($type) {
    $prefix = ($type === 'Electricity') ? 'EL' : 'WT';
    $part1 = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $part2 = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    $part3 = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    return "$prefix-$part1-$part2-$part3";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Generate Token (Admin/Landlord)
    if ($action === 'generate') {
        requireRole(['staff', 'landlord']);
        
        $tenant_id   = $_POST['tenant_id'] ?? null;
        $token_type  = $_POST['token_type'] ?? 'Electricity';
        $units_value = $_POST['units_value'] ?? 0;
        $amount      = $_POST['amount'] ?? 0;
        
        // Find property/unit from active lease
        $stmtLease = $pdo->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmtLease->execute([$tenant_id]);
        $lease = $stmtLease->fetch();
        
        $property_id = $lease['property_id'] ?? null;
        $unit_id     = $lease['unit_id'] ?? null;
        
        $token_id = generateUUID();
        $token_code = generateTokenCode($token_type);

        try {
            $pdo->beginTransaction();
            
            // 1. Create Token
            $stmt = $pdo->prepare("INSERT INTO tokens (id, tenant_id, property_id, unit_id, token_type, token_code, units_value, amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$token_id, $tenant_id, $property_id, $unit_id, $token_type, $token_code, $units_value, $amount, $_SESSION['user_id']]);
            
            // 2. Create Transaction (as Paid)
            $trans_id = generateUUID();
            $trans_type = ($token_type === 'Electricity') ? 'Electricity Token' : 'Water Token';
            $stmtTrans = $pdo->prepare("INSERT INTO transactions (id, tenant_id, amount, transaction_type, payment_method, status, description, transaction_date) VALUES (?, ?, ?, ?, ?, 'Paid', ?, NOW())");
            $stmtTrans->execute([$trans_id, $tenant_id, $amount, $trans_type, 'System Generated', "Token: $token_code"]);
            
            $pdo->commit();
            header("Location: ../tokens.php?success=generated");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Error generating token: " . $e->getMessage());
        }
    }

    // Action: Purchase Token (Tenant)
    if ($action === 'purchase') {
        requireRole(['tenant']);
        
        // Resolve tenant ID
        $stmtT = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
        $stmtT->execute([$_SESSION['user_id']]);
        $tenantId = $stmtT->fetchColumn();
        
        $token_type = $_POST['token_type'] ?? 'Electricity';
        $amount     = $_POST['amount'] ?? 0;
        
        // Simple conversion logic (e.g. 1 KSh = 0.5 units)
        $units_value = $amount * 0.5; 
        
        // Find property/unit
        $stmtLease = $pdo->prepare("SELECT property_id, unit_id FROM leases WHERE tenant_id = ? AND status = 'Active' LIMIT 1");
        $stmtLease->execute([$tenantId]);
        $lease = $stmtLease->fetch();
        
        $property_id = $lease['property_id'] ?? null;
        $unit_id     = $lease['unit_id'] ?? null;
        
        $token_id = generateUUID();
        $token_code = generateTokenCode($token_type);

        try {
            $pdo->beginTransaction();
            
            // 1. Create Token
            $stmt = $pdo->prepare("INSERT INTO tokens (id, tenant_id, property_id, unit_id, token_type, token_code, units_value, amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$token_id, $tenantId, $property_id, $unit_id, $token_type, $token_code, $units_value, $amount, $_SESSION['user_id']]);
            
            // 2. Create Transaction (as Paid)
            $trans_id = generateUUID();
            $trans_type = ($token_type === 'Electricity') ? 'Electricity Token' : 'Water Token';
            $stmtTrans = $pdo->prepare("INSERT INTO transactions (id, tenant_id, amount, transaction_type, payment_method, status, description, transaction_date) VALUES (?, ?, ?, ?, ?, 'Paid', ?, NOW())");
            $stmtTrans->execute([$trans_id, $tenantId, $amount, $trans_type, 'M-Pesa (Simulated)', "Purchased Token: $token_code"]);
            
            $pdo->commit();
            header("Location: ../tokens.php?success=purchased");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Error purchasing token: " . $e->getMessage());
        }
    }
}
