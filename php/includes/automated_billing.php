<?php
/**
 * Automated Billing System
 * Primelink Management System
 */

/**
 * Runs the automated billing check for Rent and Garbage.
 * This ensures that every active tenant has an invoice for the current month.
 * 
 * @param PDO $pdo
 * @return int Number of new invoices created
 */
function runAutomatedBilling($pdo) {
    // Standardize month and year for consistent matching
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    // Standard due date is the 5th of the month (as per tenancy agreement)
    $dueDate = date('Y-m-05');
    
    // 1. Fetch all active leases with property and unit info
    $stmt = $pdo->query("
        SELECT l.id as lease_id, l.tenant_id, l.monthly_rent, p.garbage_fee, p.title as property_name, u.unit_number
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        WHERE l.status = 'Active' AND t.status = 'Active'
    ");
    $activeLeases = $stmt->fetchAll();

    $invoicesCreated = 0;

    foreach ($activeLeases as $lease) {
        $tenantId = $lease['tenant_id'];
        $leaseId = $lease['lease_id'];
        
        // --- 1. RENT BILLING ---
        // Check if Rent invoice exists for this month
        $stmtRent = $pdo->prepare("
            SELECT id FROM invoices 
            WHERE tenant_id = ? 
            AND lease_id = ? 
            AND invoice_type = 'Rent' 
            AND MONTH(created_at) = ? 
            AND YEAR(created_at) = ?
        ");
        $stmtRent->execute([$tenantId, $leaseId, $currentMonth, $currentYear]);
        
        if (!$stmtRent->fetch()) {
            // Create Rent Invoice
            $invId = generateUUID();
            $stmtInsert = $pdo->prepare("
                INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type)
                VALUES (:id, :tenant_id, :lease_id, :amount, :due_date, 'Unpaid', 'Rent')
            ");
            $stmtInsert->execute([
                'id'        => $invId, 
                'tenant_id' => $tenantId, 
                'lease_id'  => $leaseId, 
                'amount'    => $lease['monthly_rent'], 
                'due_date'  => $dueDate
            ]);
            $invoicesCreated++;
        }

        // --- 2. GARBAGE BILLING ---
        if ($lease['garbage_fee'] > 0) {
            // Check if Garbage invoice exists for this month
            $stmtGarbage = $pdo->prepare("
                SELECT id FROM invoices 
                WHERE tenant_id = ? 
                AND lease_id = ? 
                AND invoice_type = 'Garbage' 
                AND MONTH(created_at) = ? 
                AND YEAR(created_at) = ?
            ");
            $stmtGarbage->execute([$tenantId, $leaseId, $currentMonth, $currentYear]);

            if (!$stmtGarbage->fetch()) {
                // Create Garbage Invoice
                $invId = generateUUID();
                $stmtInsert = $pdo->prepare("
                    INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type)
                    VALUES (:id, :tenant_id, :lease_id, :amount, :due_date, 'Unpaid', 'Garbage')
                ");
                $stmtInsert->execute([
                    'id'        => $invId, 
                    'tenant_id' => $tenantId, 
                    'lease_id'  => $leaseId, 
                    'amount'    => $lease['garbage_fee'], 
                    'due_date'  => $dueDate
                ]);
                $invoicesCreated++;
            }
        }
    }
    
    return $invoicesCreated;
}
/**
 * Generates initial invoices for a newly assigned lease (Rent, Garbage, Deposit).
 * Useful for immediate billing upon registration.
 */
function generateInitialInvoices($pdo, $tenantId, $leaseId, $unitId) {
    // Fetch property/unit details for accurate billing
    $stmt = $pdo->prepare("SELECT u.monthly_rent, u.deposit_amount, p.garbage_fee 
                           FROM units u JOIN properties p ON u.property_id = p.id 
                           WHERE u.id = ?");
    $stmt->execute([$unitId]);
    $details = $stmt->fetch();

    if (!$details) return 0;

    $dueDate = date('Y-m-05'); // Standard 5th of the month
    $created = 0;

    // 1. Rent
    $invRentId = generateUUID();
    $stmt = $pdo->prepare("INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type) 
                           VALUES (:id, :tenant_id, :lease_id, :amount, :due_date, 'Unpaid', 'Rent')");
    $stmt->execute(['id' => $invRentId, 'tenant_id' => $tenantId, 'lease_id' => $leaseId, 'amount' => $details['monthly_rent'], 'due_date' => $dueDate]);
    $created++;

    // 2. Garbage
    if ($details['garbage_fee'] > 0) {
        $invGarbageId = generateUUID();
        $stmt = $pdo->prepare("INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type) 
                               VALUES (:id, :tenant_id, :lease_id, :amount, :due_date, 'Unpaid', 'Garbage')");
        $stmt->execute(['id' => $invGarbageId, 'tenant_id' => $tenantId, 'lease_id' => $leaseId, 'amount' => $details['garbage_fee'], 'due_date' => $dueDate]);
        $created++;
    }

    // 3. Security Deposit
    if ($details['deposit_amount'] > 0) {
        $invDepositId = generateUUID();
        $stmt = $pdo->prepare("INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type) 
                               VALUES (:id, :tenant_id, :lease_id, :amount, :due_date, 'Unpaid', 'Deposit')");
        $stmt->execute(['id' => $invDepositId, 'tenant_id' => $tenantId, 'lease_id' => $leaseId, 'amount' => $details['deposit_amount'], 'due_date' => $dueDate]);
        $created++;
    }

    return $created;
}
