<?php
/**
 * Unit Management Actions
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

$action  = $_POST['action'] ?? '';
$_redir  = !empty($_POST['_redirect']) ? '../' . $_POST['_redirect'] : null;

if ($action === 'create') {
    $propertyId = $_POST['property_id'];
    $unitNumber = $_POST['unit_number'];
    $floorNumber = $_POST['floor_number'] ?? 'G';
    $unitType = $_POST['unit_type'];
    $category = $_POST['category'] ?? '';
    $electricityMeter = $_POST['electricity_meter'] ?? '';
    $waterMeter = $_POST['water_meter'] ?? '';
    $monthlyRent       = $_POST['monthly_rent']       ?? $_POST['rent_amount'] ?? 0;
    $depositAmount     = $_POST['deposit_amount']     ?? 0;
    $waterDeposit      = $_POST['water_deposit']      ?? 0;
    $electricityDeposit= $_POST['electricity_deposit']?? 0;
    $goodwill          = $_POST['goodwill']            ?? 0;
    $status = $_POST['status'] ?? 'Available';

    // Handle Image Uploads
    $imageUrls = [];
    if (!empty($_FILES['unit_images']['name'][0])) {
        $uploadDir = __DIR__ . '/../uploads/units/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        foreach ($_FILES['unit_images']['name'] as $key => $val) {
            $fileName = time() . '_' . basename($_FILES['unit_images']['name'][$key]);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['unit_images']['tmp_name'][$key], $targetPath)) {
                $imageUrls[] = 'php/uploads/units/' . $fileName;
            }
        }
    }

    $id = bin2hex(random_bytes(18));
    $imagesJson = json_encode($imageUrls);

    // Self-heal new deposit columns
    foreach ([
        "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `water_deposit`       DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `deposit_amount`",
        "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `electricity_deposit` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `water_deposit`",
        "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `goodwill`            DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `electricity_deposit`",
    ] as $_ddl) { try { $pdo->exec($_ddl); } catch (PDOException $_ex) {} }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO units (id, property_id, unit_number, floor_number, unit_type, category, electricity_meter, water_meter, monthly_rent, deposit_amount, water_deposit, electricity_deposit, goodwill, status, images)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $propertyId, $unitNumber, $floorNumber, $unitType, $category, $electricityMeter, $waterMeter, $monthlyRent, $depositAmount, $waterDeposit, $electricityDeposit, $goodwill, $status, $imagesJson]);
        header("Location: " . ($_redir ?? "../property_details.php?id=$propertyId&success=unit_created"));
    } catch (PDOException $e) {
        // Self-healing: category, images, or rent_amount naming drift
        if ($e->getCode() == '42S22') {
            try {
                $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `unit_type` ");
                $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `images` JSON NULL AFTER `status` ");
                $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `electricity_meter` VARCHAR(100) NULL AFTER `deposit_amount` ");
                $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `water_meter` VARCHAR(100) NULL AFTER `electricity_meter` ");

                // Attempt to rename rent_amount to monthly_rent if it exists
                try {
                    $pdo->exec("ALTER TABLE `units` CHANGE COLUMN `rent_amount` `monthly_rent` DECIMAL(15,2) NOT NULL DEFAULT 0");
                } catch(Exception $ex) {
                    // Already renamed or missing
                }

                $stmt = $pdo->prepare("
                    INSERT INTO units (id, property_id, unit_number, floor_number, unit_type, category, electricity_meter, water_meter, monthly_rent, deposit_amount, water_deposit, electricity_deposit, goodwill, status, images)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id, $propertyId, $unitNumber, $floorNumber, $unitType, $category, $electricityMeter, $waterMeter, $monthlyRent, $depositAmount, $waterDeposit, $electricityDeposit, $goodwill, $status, $imagesJson]);
                header("Location: " . ($_redir ?? "../property_details.php?id=$propertyId&success=unit_created"));
                exit();
            } catch (PDOException $subE) {
                die("Unit Repair Failed: " . $subE->getMessage());
            }
        }
        header("Location: ../property_details.php?id=$propertyId&error=create_failed_detail&msg=" . urlencode($e->getMessage()));
    }
}

else if ($action === 'delete') {
    $unitId     = trim($_POST['unit_id']     ?? '');
    $propertyId = trim($_POST['property_id'] ?? '');

    if (!$unitId) { header("Location: " . ($_redir ?? "../property_details.php?id=$propertyId")); exit(); }

    // Block if active lease exists
    $check = $pdo->prepare("SELECT COUNT(*) FROM leases WHERE unit_id = ? AND status = 'Active'");
    $check->execute([$unitId]);
    if ((int)$check->fetchColumn() > 0) {
        header("Location: ../property_details.php?id=$propertyId&tab=units&error=has_tenant");
        exit();
    }

    // Fetch unit info for audit
    $row = $pdo->prepare("SELECT unit_number FROM units WHERE id = ?");
    $row->execute([$unitId]);
    $uNum = $row->fetchColumn() ?: $unitId;

    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM units WHERE id = ?")->execute([$unitId]);
        $pdo->commit();

        require_once __DIR__ . '/../includes/audit.php';
        logAction($pdo, 'unit_deleted', 'Units', $unitId, "Deleted unit $uNum from property $propertyId");

        header("Location: " . ($_redir ?? "../property_details.php?id=$propertyId&success=unit_deleted"));
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: ../property_details.php?id=$propertyId&error=" . urlencode($e->getMessage()));
    }
    exit();
}

else if ($action === 'update') {
    $unitId = $_POST['unit_id'];
    $propertyId = $_POST['property_id'];
    $unitNumber = $_POST['unit_number'];
    $floorNumber = $_POST['floor_number'];
    $unitType = $_POST['unit_type'];
    $category = $_POST['category'];
    $electricityMeter = $_POST['electricity_meter'] ?? '';
    $waterMeter = $_POST['water_meter'] ?? '';
    $monthlyRent        = $_POST['monthly_rent']        ?? $_POST['rent_amount'] ?? 0;
    $depositAmount      = $_POST['deposit_amount']      ?? 0;
    $waterDeposit       = $_POST['water_deposit']       ?? 0;
    $electricityDeposit = $_POST['electricity_deposit'] ?? 0;
    $goodwill           = $_POST['goodwill']            ?? 0;
    $status = $_POST['status'];

    // Fetch existing images
    $stmt = $pdo->prepare("SELECT images FROM units WHERE id = ?");
    $stmt->execute([$unitId]);
    $existing = $stmt->fetch();
    $imageUrls = json_decode($existing['images'] ?? '[]', true);

    // Handle New Image Uploads
    if (!empty($_FILES['unit_images']['name'][0])) {
        $uploadDir = __DIR__ . '/../uploads/units/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        foreach ($_FILES['unit_images']['name'] as $key => $val) {
            $fileName = time() . '_' . basename($_FILES['unit_images']['name'][$key]);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['unit_images']['tmp_name'][$key], $targetPath)) {
                $imageUrls[] = 'php/uploads/units/' . $fileName;
            }
        }
    }

    $imagesJson = json_encode($imageUrls);

    $stmt = $pdo->prepare("
        UPDATE units
        SET unit_number = ?, floor_number = ?, unit_type = ?, category = ?, electricity_meter = ?, water_meter = ?,
            monthly_rent = ?, deposit_amount = ?, water_deposit = ?, electricity_deposit = ?, goodwill = ?,
            status = ?, images = ?
        WHERE id = ?
    ");

    if ($stmt->execute([$unitNumber, $floorNumber, $unitType, $category, $electricityMeter, $waterMeter, $monthlyRent, $depositAmount, $waterDeposit, $electricityDeposit, $goodwill, $status, $imagesJson, $unitId])) {
        header("Location: " . ($_redir ?? "../property_details.php?id=$propertyId&success=unit_updated"));
    } else {
        header("Location: ../property_details.php?id=$propertyId&error=update_failed");
    }
}
