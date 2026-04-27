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
            // 0. Check for existing email & Resolve Orphan status
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
                $stmt->execute([$userId, $email, $hashedPassword]);
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
                    if ($e->getCode() == '42S22' && strpos($e->getMessage(), 'address') !== false) {
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

            // 4. Create Tenant Record with Self-Healing
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
            
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $tenantId, $userId, $fullName, $email, $phone, $idNo, $idCopyUrl, 
                    $maritalStatus, $hasKids, $address, $spouseName, $spousePhone, 
                    $spouseIdNo, $spouseEmail, $profession, $employerName, $occupationType,
                    $businessName, $businessNature, $nokName, $nokContact, $nokRelationship
                ]);
            } catch (PDOException $e) {
                // Self-healing for missing columns in tenants table
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
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $tenantId, $userId, $fullName, $email, $phone, $idNo, $idCopyUrl, 
                        $maritalStatus, $hasKids, $address, $spouseName, $spousePhone, 
                        $spouseIdNo, $spouseEmail, $profession, $employerName, $occupationType,
                        $businessName, $businessNature, $nokName, $nokContact, $nokRelationship
                    ]);
                } else { throw $e; }
            }

            // 5. Auto-generate Documents Link
            $docId = generateUUID();
            $stmt = $pdo->prepare("INSERT INTO documents (id, tenant_id, title, category, file_url, file_size) VALUES (?, ?, ?, 'Lease', ?, 'Generated')");
            $stmt->execute([$docId, $tenantId, "System Generated Lease - " . $fullName, "view_lease.php?tenant_id=" . $tenantId]);

            $pdo->commit();
            header("Location: ../tenants.php?success=created");
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            die("Error creating tenant: " . $e->getMessage());
        }
    }
}
?>
