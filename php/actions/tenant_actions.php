<?php
/**
 * Tenant Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $fullName = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $password = $_POST['password'] ?? 'Prime@123';
        $address = $_POST['address'] ?? '';
        
        // Advanced Fields
        $idNo = $_POST['id_no'] ?? null;
        $maritalStatus = $_POST['marital_status'] ?? 'Single';
        $hasKids = isset($_POST['has_kids']) ? 1 : 0;
        $spouseName = $_POST['spouse_name'] ?? null;
        $spousePhone = $_POST['spouse_phone'] ?? null;
        $spouseIdNo = $_POST['spouse_id_no'] ?? null;
        $spouseEmail = $_POST['spouse_email'] ?? null;
        $profession = $_POST['profession'] ?? null;
        $employerName = $_POST['employer_name'] ?? null;
        $occupationType = $_POST['occupation_type'] ?? 'Residential';
        $businessName = $_POST['business_name'] ?? null;
        $businessNature = $_POST['business_nature'] ?? null;
        $nokName = $_POST['nok_name'] ?? null;
        $nokContact = $_POST['nok_contact'] ?? null;
        $nokRelationship = $_POST['nok_relationship'] ?? null;

        $userId = generateUUID();
        $tenantId = generateUUID();
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        try {
            // 0. PRE-FLIGHT: Self-Healing (Outside transaction to avoid implicit commit)
            // We do a quick check/repair of the tenants table before starting
            try {
                $pdo->query("SELECT id_no FROM tenants LIMIT 1");
            } catch (PDOException $e) {
                if ($e->getCode() == '42S22') {
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `id_no` VARCHAR(100) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `id_copy_url` TEXT NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `terms_accepted_at` TIMESTAMP NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `marital_status` VARCHAR(50) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `has_kids` TINYINT(1) DEFAULT 0");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `current_address` TEXT NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_name` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_phone` VARCHAR(50) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_id_no` VARCHAR(100) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_email` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `profession` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `employer_name` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `occupation_type` VARCHAR(100) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_name` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_nature` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_name` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_contact` VARCHAR(255) NULL");
                    $pdo->exec("ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_relationship` VARCHAR(100) NULL");
                }
            }
            
            // 1. Check for existing email & Resolve Orphan status
            $stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch();
            
            if ($existingUser) {
                $userId = $existingUser['id'];
                // Check if already a tenant record exists
                $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ? OR email = ?");
                $stmt->execute([$userId, $email]);
                if ($stmt->fetch()) {
                    die("Error: This tenant is already registered and active in the system.");
                }
                // If we are here, the user exists in 'users' but NOT in 'tenants'. We proceed to link them.
            } else {
                $userId = generateUUID();
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, 'tenant')");
                $stmt->execute([$userId, $email, $hashedPassword, 'tenant']);
                $pdo->commit();
            }

            $pdo->beginTransaction();

            // 2. Create/Update Profile
            $stmt = $pdo->prepare("SELECT id FROM profiles WHERE id = ?");
            $stmt->execute([$userId]);
            if (!$stmt->fetch()) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role, address) VALUES (?, ?, ?, ?, 'tenant', ?)");
                    $stmt->execute([$userId, $fullName, $email, $phone, $address]);
                } catch (PDOException $e) {
                    if ($e->getCode() == '42S22') {
                        $pdo->exec("ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone` ");
                        $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role, address) VALUES (?, ?, ?, ?, 'tenant', ?)");
                        $stmt->execute([$userId, $fullName, $email, $phone, $address]);
                    } else { throw $e; }
                }
            }

            // 3. Handle File Uploads (IDs)
            $idCopyUrl = null;
            if (isset($_FILES['id_copy']) && $_FILES['id_copy']['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES['id_copy']['name'], PATHINFO_EXTENSION);
                $fileName = "id_" . substr($userId, 0, 8) . "_" . time() . "." . $ext;
                if (!is_dir(__DIR__ . "/../uploads/ids")) mkdir(__DIR__ . "/../uploads/ids", 0777, true);
                move_uploaded_file($_FILES['id_copy']['tmp_name'], __DIR__ . "/../uploads/ids/" . $fileName);
                $idCopyUrl = "php/uploads/ids/" . $fileName;
            }

            // 4. Create Tenant Record
            $tenantId = generateUUID();
            $sql = "INSERT INTO tenants (
                id, user_id, full_name, email, phone, status, id_no, id_copy_url, 
                marital_status, has_kids, current_address, spouse_name, spouse_phone, 
                spouse_id_no, spouse_email, profession, employer_name, occupation_type, 
                business_name, business_nature, next_of_kin_name, next_of_kin_contact, 
                next_of_kin_relationship, terms_accepted_at
            ) VALUES (
                ?, ?, ?, ?, ?, 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $tenantId, $userId, $fullName, $email, $phone, $idNo, $idCopyUrl, 
                $maritalStatus, $hasKids, $address, $spouseName, $spousePhone, 
                $spouseIdNo, $spouseEmail, $profession, $employerName, $occupationType,
                $businessName, $businessNature, $nokName, $nokContact, $nokRelationship
            ]);

            // 6. Handle Unit Assignment (Lease Creation)
            $propertyId = $_POST['property_id'] ?? null;
            $unitId = $_POST['unit_id'] ?? null;

            if ($propertyId && $unitId) {
                $leaseId = generateUUID();
                // We fetch rent/deposit from units table to ensure accuracy
                $stmt = $pdo->prepare("INSERT INTO leases (id, tenant_id, property_id, unit_id, start_date, end_date, monthly_rent, deposit_amount, status) 
                                     SELECT ?, ?, ?, id, NOW(), DATE_ADD(NOW(), INTERVAL 1 YEAR), monthly_rent, deposit_amount, 'Active' 
                                     FROM units WHERE id = ?");
                $stmt->execute([$leaseId, $tenantId, $propertyId, $unitId]);
                
                // Update unit status
                $stmt = $pdo->prepare("UPDATE units SET status = 'Occupied' WHERE id = ?");
                $stmt->execute([$unitId]);

                // --- IMMEDIATE BILLING ---
                require_once __DIR__ . '/../includes/automated_billing.php';
                generateInitialInvoices($pdo, $tenantId, $leaseId, $unitId);
            }

            if ($pdo->inTransaction()) $pdo->commit();
            header("Location: ../tenants.php?success=created");
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die("Error creating tenant: " . $e->getMessage());
        }
    }

    // ── assign_unit: create a new lease for an existing tenant ────────
    if ($action === 'assign_unit') {
        $tenantId    = trim($_POST['tenant_id']      ?? '');
        $unitId      = trim($_POST['unit_id']         ?? '');
        $propertyId  = trim($_POST['property_id']     ?? '');
        $startDate   = $_POST['start_date']            ?? date('Y-m-d');
        $endDate     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $monthlyRent = (float)($_POST['monthly_rent']    ?? 0);
        $depositAmt  = (float)($_POST['deposit_amount']  ?? 0);

        if (!$tenantId || !$unitId || !$propertyId || $monthlyRent <= 0) {
            header('Location: ../tenants.php?error=' . urlencode('Missing required fields.'));
            exit();
        }

        // Resolve property_id from unit if blank
        if (!$propertyId) {
            $r = $pdo->prepare("SELECT property_id FROM units WHERE id = ?");
            $r->execute([$unitId]);
            $propertyId = $r->fetchColumn() ?: '';
        }

        // Block if tenant already has an active lease
        $dup = $pdo->prepare("SELECT COUNT(*) FROM leases WHERE tenant_id = ? AND status = 'Active'");
        $dup->execute([$tenantId]);
        if ((int)$dup->fetchColumn() > 0) {
            header('Location: ../tenants.php?error=' . urlencode('Tenant already has an active lease. End the current lease first.'));
            exit();
        }

        try {
            $pdo->beginTransaction();
            $leaseId = generateUUID();
            $pdo->prepare("
                INSERT INTO leases (id, tenant_id, unit_id, property_id, start_date, end_date, monthly_rent, deposit_amount, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')
            ")->execute([$leaseId, $tenantId, $unitId, $propertyId, $startDate, $endDate, $monthlyRent, $depositAmt]);

            $pdo->prepare("UPDATE units   SET status = 'Occupied' WHERE id = ?")->execute([$unitId]);
            $pdo->prepare("UPDATE tenants SET status = 'Active'   WHERE id = ?")->execute([$tenantId]);
            $pdo->commit();

            require_once __DIR__ . '/../includes/audit.php';
            logAction($pdo, 'unit_assigned', 'Tenants', $tenantId, "Lease {$leaseId} created, unit {$unitId}");

            header('Location: ../tenants.php?success=unit_assigned');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: ../tenants.php?error=' . urlencode($e->getMessage()));
        }
        exit();
    }

    // ── delete: permanently remove tenant with no lease history ───────
    if ($action === 'delete') {
        $tenantId = trim($_POST['tenant_id'] ?? '');
        if (!$tenantId) { header('Location: ../tenants.php'); exit(); }

        // Block delete if tenant has any lease (active or historical)
        $leaseCheck = $pdo->prepare("SELECT COUNT(*) FROM leases WHERE tenant_id = ?");
        $leaseCheck->execute([$tenantId]);
        if ((int)$leaseCheck->fetchColumn() > 0) {
            header('Location: ../tenants.php?error=has_lease');
            exit();
        }

        $row = $pdo->prepare("SELECT user_id, full_name FROM tenants WHERE id = ?");
        $row->execute([$tenantId]);
        $tenant = $row->fetch();
        if (!$tenant) { header('Location: ../tenants.php?error=not_found'); exit(); }

        try {
            $pdo->beginTransaction();
            // Clean up related records first
            $pdo->prepare("DELETE FROM maintenance_requests WHERE tenant_id = ?")->execute([$tenantId]);
            $pdo->prepare("DELETE FROM invoices            WHERE tenant_id = ?")->execute([$tenantId]);
            $pdo->prepare("DELETE FROM tenants             WHERE id        = ?")->execute([$tenantId]);
            if ($tenant['user_id']) {
                $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$tenant['user_id']]);
                $pdo->prepare("DELETE FROM profiles      WHERE id      = ?")->execute([$tenant['user_id']]);
                $pdo->prepare("DELETE FROM users         WHERE id      = ?")->execute([$tenant['user_id']]);
            }
            $pdo->commit();

            require_once __DIR__ . '/../includes/audit.php';
            logAction($pdo, 'tenant_deleted', 'Tenants', $tenantId, "Deleted: {$tenant['full_name']}");

            header('Location: ../tenants.php?success=tenant_deleted');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            header('Location: ../tenants.php?error=' . urlencode($e->getMessage()));
        }
        exit();
    }
}
?>
