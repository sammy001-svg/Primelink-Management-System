<?php
/**
 * Property Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = $_POST['title'] ?? '';
        $location = $_POST['location'] ?? '';
        $description = $_POST['description'] ?? '';
        $price = $_POST['price'] ?? 0;
        $property_type = $_POST['property_type'] ?? 'Apartment';
        $status = $_POST['status'] ?? 'Available';
        $landlord_id = $_POST['landlord_id'] ?? null;
        $area = $_POST['area'] ?? 0;
        
        // Handle images (placeholder for real upload logic)
        $images = json_encode([$_POST['image_url'] ?? 'https://images.unsplash.com/photo-1613490493576-7fde63acd811?q=80&w=800']);
        $amenities = json_encode(explode(',', $_POST['amenities'] ?? ''));

        $id = generateUUID();
        
        try {
            $stmt = $pdo->prepare("INSERT INTO properties (id, landlord_id, title, location, description, price, property_type, status, images, amenities, area) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $landlord_id, $title, $location, $description, $price, $property_type, $status, $images, $amenities, $area]);
            
            header("Location: ../properties.php?success=created");
            exit();
        } catch (PDOException $e) {
            die("Error creating property: " . $e->getMessage());
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
