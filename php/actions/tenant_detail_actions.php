<?php
/**
 * Tenant Detail Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tenantId = $_POST['tenant_id'] ?? '';

    if ($action === 'update_profile') {
        $fullName = $_POST['full_name'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $profession = $_POST['profession'] ?? '';
        $employerName = $_POST['employer_name'] ?? '';
        $maritalStatus = $_POST['marital_status'] ?? 'Single';

        try {
            $pdo->beginTransaction();

            // 1. Update Profile (linked by user_id)
            $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
            $stmt->execute([$tenantId]);
            $userId = $stmt->fetchColumn();

            if ($userId) {
                $stmt = $pdo->prepare("UPDATE profiles SET full_name = ?, phone = ?, address = ? WHERE id = ?");
                $stmt->execute([$fullName, $phone, $address, $userId]);
            }

            // 2. Update Tenant record
            $stmt = $pdo->prepare("
                UPDATE tenants 
                SET full_name = ?, phone = ?, current_address = ?, profession = ?, employer_name = ?, marital_status = ?
                WHERE id = ?
            ");
            $stmt->execute([$fullName, $phone, $address, $profession, $employerName, $maritalStatus, $tenantId]);

            $pdo->commit();
            header("Location: ../tenant_details.php?id=$tenantId&success=profile_updated");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Error updating profile: " . $e->getMessage());
        }
    }

    if ($action === 'reset_password') {
        $newPass = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';

        if ($newPass !== $confPass) {
            die("Passwords do not match.");
        }

        $stmt = $pdo->prepare("SELECT user_id FROM tenants WHERE id = ?");
        $stmt->execute([$tenantId]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $userId]);
            header("Location: ../tenant_details.php?id=$tenantId&success=password_reset");
            exit();
        }
    }
}
