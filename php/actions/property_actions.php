<?php
/**
 * Property Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = $_POST['title'] ?? '';
        $location = $_POST['location'] ?? '';
        $property_code = $_POST['property_code'] ?? '';
        $description = $_POST['description'] ?? '';
        $price = 0; // Price is now set per unit
        $property_type = $_POST['property_type'] ?? 'Apartment';
        $status = $_POST['status'] ?? 'Available';
        $landlord_id = $_POST['landlord_id'] ?? null;
        $area = $_POST['area'] ?? 0;
        $water_rate = $_POST['water_rate'] ?? 0;
        $garbage_fee = $_POST['garbage_fee'] ?? 0;
        
        // Handle Image Uploads
        $imageUrls = [];
        if (!empty($_FILES['property_images']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/properties/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            foreach ($_FILES['property_images']['name'] as $key => $val) {
                if ($_FILES['property_images']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.\-_]/", "", basename($_FILES['property_images']['name'][$key]));
                    $targetPath = $uploadDir . $fileName;
                    if (move_uploaded_file($_FILES['property_images']['tmp_name'][$key], $targetPath)) {
                        $imageUrls[] = 'php/uploads/properties/' . $fileName;
                    }
                }
            }
        }

        // If no images uploaded, use default
        if (empty($imageUrls)) {
            $imageUrls[] = 'https://images.unsplash.com/photo-1613490493576-7fde63acd811?q=80&w=800';
        }

        $images = json_encode($imageUrls);
        $amenities = json_encode([]); // Can be expanded later

        $id = generateUUID();
        
        try {
            $stmt = $pdo->prepare("INSERT INTO properties (id, landlord_id, title, location, description, price, property_type, status, images, amenities, area, property_code, water_rate, garbage_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $landlord_id, $title, $location, $description, $price, $property_type, $status, $images, $amenities, $area, $property_code, $water_rate, $garbage_fee]);
            
            header("Location: ../properties.php?success=created");
            exit();
        } catch (PDOException $e) {
            // Self-healing: missing columns
            if ($e->getCode() == '42S22') {
                try {
                    $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `property_code` VARCHAR(50) NULL AFTER `area` ");
                    $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `water_rate` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `property_code` ");
                    $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `garbage_fee` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `water_rate` ");
                    
                    // Retry
                    $stmt = $pdo->prepare("INSERT INTO properties (id, landlord_id, title, location, description, price, property_type, status, images, amenities, area, property_code, water_rate, garbage_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$id, $landlord_id, $title, $location, $description, $price, $property_type, $status, $images, $amenities, $area, $property_code, $water_rate, $garbage_fee]);
                    header("Location: ../properties.php?success=created");
                    exit();
                } catch (PDOException $retryError) {
                    die("Database Repair Failed: " . $retryError->getMessage());
                }
            }
            die("Error creating property: " . $e->getMessage());
        }
    }

    else if ($action === 'update') {
        $id = $_POST['id'] ?? '';
        $title = $_POST['title'] ?? '';
        $location = $_POST['location'] ?? '';
        $property_code = $_POST['property_code'] ?? '';
        $description = $_POST['description'] ?? '';
        $property_type = $_POST['property_type'] ?? 'Apartment';
        $status = $_POST['status'] ?? 'Available';
        $landlord_id = $_POST['landlord_id'] ?? null;
        $area = $_POST['area'] ?? 0;
        $water_rate = $_POST['water_rate'] ?? 0;
        $garbage_fee = $_POST['garbage_fee'] ?? 0;

        // Fetch existing images
        $stmt = $pdo->prepare("SELECT images FROM properties WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        $imageUrls = json_decode($existing['images'] ?? '[]', true);

        // Handle New Image Uploads
        if (!empty($_FILES['property_images']['name'][0])) {
            $uploadDir = __DIR__ . '/../uploads/properties/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            foreach ($_FILES['property_images']['name'] as $key => $val) {
                if ($_FILES['property_images']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.\-_]/", "", basename($_FILES['property_images']['name'][$key]));
                    $targetPath = $uploadDir . $fileName;
                    if (move_uploaded_file($_FILES['property_images']['tmp_name'][$key], $targetPath)) {
                        $imageUrls[] = 'php/uploads/properties/' . $fileName;
                    }
                }
            }
        }

        $images = json_encode($imageUrls);

        try {
            $stmt = $pdo->prepare("UPDATE properties SET landlord_id=?, title=?, location=?, description=?, property_type=?, status=?, images=?, area=?, property_code=?, water_rate=?, garbage_fee=? WHERE id=?");
            $stmt->execute([$landlord_id, $title, $location, $description, $property_type, $status, $images, $area, $property_code, $water_rate, $garbage_fee, $id]);
            $redir = !empty($_POST['_redirect']) ? '../' . $_POST['_redirect'] : '../properties.php?success=updated';
            header("Location: $redir");
            exit();
        } catch (PDOException $e) {
            if ($e->getCode() == '42S22') {
                $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `property_code` VARCHAR(50) NULL AFTER `area` ");
                $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `water_rate` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `property_code` ");
                $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `garbage_fee` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `water_rate` ");

                $stmt = $pdo->prepare("UPDATE properties SET landlord_id=?, title=?, location=?, description=?, property_type=?, status=?, images=?, area=?, property_code=?, water_rate=?, garbage_fee=? WHERE id=?");
                $stmt->execute([$landlord_id, $title, $location, $description, $property_type, $status, $images, $area, $property_code, $water_rate, $garbage_fee, $id]);
                $redir = !empty($_POST['_redirect']) ? '../' . $_POST['_redirect'] : '../properties.php?success=updated';
                header("Location: $redir");
                exit();
            }
            die("Error updating property: " . $e->getMessage());
        }
    }

    if ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        try {
            $stmt = $pdo->prepare("DELETE FROM properties WHERE id = ?");
            $stmt->execute([$id]);
            header("Location: ../properties.php?success=deleted");
            exit();
        } catch (PDOException $e) {
            die("Error deleting property: " . $e->getMessage());
        }
    }
}
?>
