<?php
/**
 * Unit Management Actions
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $propertyId = $_POST['property_id'];
    $unitNumber = $_POST['unit_number'];
    $floorNumber = $_POST['floor_number'] ?? 'G';
    $unitType = $_POST['unit_type'];
    $category = $_POST['category'] ?? '';
    $rentAmount = $_POST['rent_amount'];
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

    try {
        $stmt = $pdo->prepare("
            INSERT INTO units (id, property_id, unit_number, floor_number, unit_type, category, rent_amount, status, images)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$id, $propertyId, $unitNumber, $floorNumber, $unitType, $category, $rentAmount, $status, $imagesJson]);
        header("Location: ../property_details.php?id=$propertyId&success=unit_created");
    } catch (PDOException $e) {
        // Self-healing: category or images missing
        if ($e->getCode() == '42S22') {
            try {
                $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `unit_type` ");
                $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `images` JSON NULL AFTER `status` ");
                $stmt = $pdo->prepare("
                    INSERT INTO units (id, property_id, unit_number, floor_number, unit_type, category, rent_amount, status, images)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$id, $propertyId, $unitNumber, $floorNumber, $unitType, $category, $rentAmount, $status, $imagesJson]);
                header("Location: ../property_details.php?id=$propertyId&success=unit_created");
                exit();
            } catch (PDOException $subE) {
                die("Unit Repair Failed: " . $subE->getMessage());
            }
        }
        header("Location: ../property_details.php?id=$propertyId&error=create_failed_detail&msg=" . urlencode($e->getMessage()));
    }
}

else if ($action === 'update') {
    $unitId = $_POST['unit_id'];
    $propertyId = $_POST['property_id'];
    $unitNumber = $_POST['unit_number'];
    $floorNumber = $_POST['floor_number'];
    $unitType = $_POST['unit_type'];
    $category = $_POST['category'];
    $rentAmount = $_POST['rent_amount'];
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
        SET unit_number = ?, floor_number = ?, unit_type = ?, category = ?, rent_amount = ?, status = ?, images = ?
        WHERE id = ?
    ");
    
    if ($stmt->execute([$unitNumber, $floorNumber, $unitType, $category, $rentAmount, $status, $imagesJson, $unitId])) {
        header("Location: ../property_details.php?id=$propertyId&success=unit_updated");
    } else {
        header("Location: ../property_details.php?id=$propertyId&error=update_failed");
    }
}
