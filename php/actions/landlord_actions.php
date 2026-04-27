<?php
/**
 * Landlord Actions Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['staff']); // admin/staff only

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../landlords.php');
    exit();
}

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'create':
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$fullName || !$email || !$password) {
            header('Location: ../landlords.php?error=missing_fields');
            exit();
        }

        try {
            $userId = generateUUID();
            $hash   = password_hash($password, PASSWORD_BCRYPT);

            // Create user account
            $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, 'landlord')")
                ->execute([$userId, $email, $hash]);

            // Create profile
            $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, 'landlord')")
                ->execute([$userId, $fullName, $email, $phone]);

            // Create landlord record
            $landlordId = generateUUID();
            $pdo->prepare("INSERT INTO landlords (id, full_name, email, phone, user_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$landlordId, $fullName, $email, $phone, $userId]);

            header('Location: ../landlords.php?success=Landlord+account+created+successfully');
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                header('Location: ../landlords.php?error=Email+already+exists');
            } else {
                header('Location: ../landlords.php?error=' . urlencode($e->getMessage()));
            }
        }
        exit();

    case 'assign_properties':
        $landlordId   = $_POST['landlord_id'] ?? '';
        $propertyIds  = $_POST['property_ids'] ?? [];

        if (!$landlordId) {
            header('Location: ../landlords.php?error=invalid_landlord');
            exit();
        }

        try {
            // First, unset any properties previously assigned to this landlord that are not in the new list
            $pdo->prepare("UPDATE properties SET landlord_id = NULL WHERE landlord_id = ?")
                ->execute([$landlordId]);

            // Now assign the checked properties
            if (!empty($propertyIds)) {
                $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
                $params = array_merge([$landlordId], $propertyIds);
                $pdo->prepare("UPDATE properties SET landlord_id = ? WHERE id IN ($placeholders)")
                    ->execute($params);
            }

            header('Location: ../landlords.php?success=Properties+assigned+successfully');
        } catch (PDOException $e) {
            header('Location: ../landlords.php?error=' . urlencode($e->getMessage()));
        }
        exit();

    case 'unassign_property':
        $propertyId = $_POST['property_id'] ?? '';
        try {
            $pdo->prepare("UPDATE properties SET landlord_id = NULL WHERE id = ?")
                ->execute([$propertyId]);
            header('Location: ../landlords.php?success=Property+unassigned');
        } catch (PDOException $e) {
            header('Location: ../landlords.php?error=' . urlencode($e->getMessage()));
        }
        exit();

    default:
        header('Location: ../landlords.php');
        exit();
}
