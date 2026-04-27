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
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $password = password_hash($_POST['password'] ?? 'tenant123', PASSWORD_DEFAULT);
        
        $user_id = generateUUID();
        $tenant_id = generateUUID();

        try {
            $pdo->beginTransaction();

            // 1. Create User
            $stmt = $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, 'tenant')");
            $stmt->execute([$user_id, $email, $password]);

            // 2. Create Profile
            $stmt = $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, 'tenant')");
            $stmt->execute([$user_id, $full_name, $email, $phone]);

            // 3. Create Tenant
            $stmt = $pdo->prepare("INSERT INTO tenants (id, user_id, full_name, email, phone, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            $stmt->execute([$tenant_id, $user_id, $full_name, $email, $phone]);

            $pdo->commit();
            header("Location: ../tenants.php?success=created");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Error creating tenant: " . $e->getMessage());
        }
    }
}
?>
