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
                VALUES (?, ?, ?, ?, ?, 'Unpaid', 'Rent')
            ");
            $stmtInsert->execute([
                $invId, 
                $tenantId, 
                $leaseId, 
                $lease['monthly_rent'], 
                $dueDate
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
                    VALUES (?, ?, ?, ?, ?, 'Unpaid', 'Garbage')
                ");
                $stmtInsert->execute([
                    $invId, 
                    $tenantId, 
                    $leaseId, 
                    $lease['garbage_fee'], 
                    $dueDate
                ]);
                $invoicesCreated++;
            }
        }
    }
    
    return $invoicesCreated;
}
